<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\RentalCustomerRequest;
use App\Models\RentalOrder;
use App\Models\RentalProduct;
use App\Models\RentalUnit;
use App\Models\User;
use App\Services\CRM\RentalCustomerRequestService;
use App\Services\CRM\RentalDocumentService;
use App\Services\CRM\RentalInventory;
use App\Services\CRM\RentalLateFee;
use App\Services\CRM\RentalPolicy;
use App\Services\CRM\RentalPortalService;
use App\Services\CRM\RentalSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RentalPolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private RentalProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-28 09:00:00', 'Asia/Jakarta'));
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('local');
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($this->admin);
        $this->customer = Customer::create(['name' => 'Penyewa Uji', 'whatsapp_number' => '6281234567890', 'status' => 'lead']);
        $this->product = RentalProduct::create(['sku' => 'POL-ST', 'name' => 'Stroller Policy', 'category' => 'Stroller', 'terms' => 'Gunakan sesuai petunjuk stroller.', 'daily_rate' => 25000, 'deposit' => 100000, 'turnaround_days' => 1, 'is_active' => true]);
        foreach (range(1, 3) as $index) {
            RentalUnit::create(['rental_product_id' => $this->product->id, 'code' => 'POL-ST-'.$index, 'status' => 'ready']);
        }
        $this->configure();
    }

    private function configure(array $changes = []): void
    {
        $settings = app(RentalSettings::class);
        $settings->save(array_replace($settings->get(), ['rental_terms' => 'Ketentuan uji lokal: kembalikan alat dalam keadaan baik.', 'late_fee_daily' => 10000, 'late_fee_grace_days' => 1, 'late_fee_cap' => 25000, 'cancellation_fee_type' => 'percent', 'cancellation_fee_value' => 50], $changes));
    }

    private function input(array $changes = []): array
    {
        return array_replace(['items' => [['product_id' => $this->product->id, 'quantity' => 1]], 'start_date' => '2026-10-28', 'end_date' => '2026-10-30', 'period' => 'day', 'fulfilment' => 'self', 'customer_id' => $this->customer->id,
            'name' => 'Penyewa Uji', 'whatsapp_number' => '081234567890', 'terms_accepted' => 1], $changes);
    }

    private function quote(array $input): string
    {
        return $this->postJson(route('rental.quote'), $input)->assertOk()->json('token');
    }

    private function order(array $changes = [], bool $public = true): RentalOrder
    {
        $input = $this->input($changes);
        $input['quote_token'] = $this->quote($input);
        $this->post(route($public ? 'rental.store' : 'admin.rentals.orders.store'), $input)->assertRedirect()->assertSessionHasNoErrors();

        return RentalOrder::latest('id')->firstOrFail();
    }

    private function confirm(RentalOrder $order): void
    {
        $this->post(route('admin.rentals.orders.action', $order), ['action' => 'confirm'])->assertSessionHasNoErrors();
        $order->refresh();
    }

    private function pay(RentalOrder $order, int $amount = 50000, string $kind = 'payment')
    {
        return $this->post(route('admin.rentals.orders.payment', $order), ['entry_key' => (string) Str::uuid(), 'kind' => $kind, 'amount' => $amount, 'method' => 'transfer', 'reference' => 'UJI', 'party_name' => 'Penyewa Uji']);
    }

    private function open(RentalOrder $order): void
    {
        $request = Request::create('/');
        $request->setLaravelSession($this->app['session.store']);
        $link = app(RentalPortalService::class)->issue($order, $request);
        $this->get($link)->assertRedirect(route('rental.portal.show', $order->code));
    }

    private function requestData(RentalOrder $order, string $kind = 'cancellation'): array
    {
        return ['entry_key' => (string) Str::uuid(), 'kind' => $kind, 'reason' => 'Pengajuan pelanggan untuk pengujian lokal', 'estimate_accepted' => 1,
            'request_token' => app(RentalCustomerRequestService::class)->preview($order, $kind)['token']];
    }

    private function submit(RentalOrder $order, string $kind = 'cancellation'): RentalCustomerRequest
    {
        $this->open($order);
        $this->post(route('rental.portal.requests', $order->code), $this->requestData($order, $kind))->assertSessionHasNoErrors();

        return $order->customerRequests()->latest('id')->firstOrFail();
    }

    private function review(RentalOrder $order, RentalCustomerRequest $entry, string $action = 'approve')
    {
        return $this->post(route('admin.rentals.customer-requests.review', [$order, $entry]), ['action' => $action, 'review_notes' => 'Saldo dan kondisi telah ditinjau untuk pengujian']);
    }

    private function settleData(array $changes = []): array
    {
        return array_replace(['method' => 'transfer', 'party_name' => 'Penerima Uji', 'reference' => 'REFUND-UJI', 'refund_date' => today()->toDateString(), 'refund_completed' => 1, 'proof' => UploadedFile::fake()->image('refund.jpg')], $changes);
    }

    public function test_public_checkout_requires_consent_and_preserves_the_exact_policy_and_product_terms(): void
    {
        $input = $this->input();
        $input['quote_token'] = $this->quote($input);
        unset($input['terms_accepted']);
        $this->post(route('rental.store'), $input)->assertSessionHasErrors('terms_accepted');
        $this->assertDatabaseCount('rental_orders', 0);
        $input['terms_accepted'] = 1;
        $this->post(route('rental.store'), $input)->assertSessionHasNoErrors();
        $order = RentalOrder::firstOrFail();
        $this->assertNotNull($order->terms_accepted_at);
        $this->assertSame('checkout', $order->terms_acceptance_source);
        $this->assertSame(10000, $order->terms_snapshot['late_fee']['daily_rate']);
        $this->configure(['late_fee_daily' => 99000]);
        $this->product->update(['terms' => 'Ketentuan baru tidak mengganti pesanan lama.']);
        $this->get(route('rental.portal.show', $order->code))->assertOk()->assertSee('Gunakan sesuai petunjuk stroller.')->assertDontSee('Ketentuan baru tidak mengganti pesanan lama.');
        $this->assertSame(10000, $order->fresh()->terms_snapshot['late_fee']['daily_rate']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'rental.terms.accept', 'auditable_id' => $order->id]);
    }

    public function test_admin_cannot_accept_on_behalf_of_customer_and_portal_acceptance_unlocks_handover(): void
    {
        $order = $this->order([], false);
        $this->confirm($order);
        $this->assertNull($order->terms_accepted_at);
        $this->post(route('admin.rentals.orders.action', $order), ['action' => 'dispatch', 'terms_accepted' => 1])->assertSessionHasErrors('rental');
        $this->open($order);
        $route = route('rental.portal.terms', $order->code);
        $this->post($route, ['terms_hash' => str_repeat('0', 64), 'terms_accepted' => 1])->assertSessionHasErrors('terms');
        $this->post($route, ['terms_hash' => $order->terms_snapshot['hash']])->assertSessionHasErrors('terms_accepted');
        $this->post($route, ['terms_hash' => $order->terms_snapshot['hash'], 'terms_accepted' => 1])->assertSessionHasNoErrors();
        $time = $order->fresh()->terms_accepted_at->toIso8601String();
        $this->travel(1)->hours();
        $this->post($route, ['terms_hash' => $order->terms_snapshot['hash'], 'terms_accepted' => 1])->assertSessionHasNoErrors();
        $this->assertSame($time, $order->fresh()->terms_accepted_at->toIso8601String());
        $this->post(route('admin.rentals.orders.action', $order), ['action' => 'dispatch'])->assertSessionHasNoErrors();
        $this->assertSame('rented', $order->fresh()->status);
    }

    public function test_unaccepted_policy_and_legacy_orders_do_not_gain_cancellation_fees(): void
    {
        $order = $this->order([], false);
        $this->confirm($order);
        $this->pay($order)->assertSessionHasNoErrors();
        $this->assertSame(0, app(RentalPolicy::class)->cancellation($order->fresh())['fee']);
        $order->update(['terms_snapshot' => null]);
        $this->post(route('admin.rentals.orders.action', $order), ['action' => 'cancel'])->assertSessionHasNoErrors();
        $this->assertEquals(50000, $order->fresh()->refundable());
    }

    public static function cancellationCases(): array
    {
        return [
            'none' => ['none', 0, 50000, 0],
            'percent-excludes-deposit' => ['percent', 50, 175000, 37500],
            'fixed' => ['fixed', 20000, 50000, 20000],
            'capped-at-service' => ['fixed', 999999, 175000, 75000],
            'capped-at-received' => ['percent', 100, 10000, 10000],
            'no-payment-no-new-debt' => ['fixed', 20000, 0, 0],
        ];
    }

    #[DataProvider('cancellationCases')]
    public function test_cancellation_formula_limits_fees_to_service_and_received_funds(string $type, int $value, int $paid, int $expected): void
    {
        $this->configure(['cancellation_fee_type' => $type, 'cancellation_fee_value' => $value]);
        $order = $this->order();
        $this->confirm($order);
        if ($paid) {
            $this->pay($order, $paid)->assertSessionHasNoErrors();
        }
        $estimate = app(RentalPolicy::class)->cancellation($order->fresh());
        $this->assertSame($expected, $estimate['fee']);
        $this->assertSame($paid - $expected, $estimate['refund']);
    }

    public function test_submission_is_idempotent_and_does_not_cancel_or_refund_until_approved(): void
    {
        $order = $this->order();
        $this->confirm($order);
        $this->pay($order)->assertSessionHasNoErrors();
        $this->open($order);
        $data = $this->requestData($order);
        $route = route('rental.portal.requests', $order->code);
        $this->post($route, $data + ['status' => 'approved', 'approved_amount' => 999999])->assertSessionHasNoErrors();
        $this->post($route, $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('rental_customer_requests', 1);
        $entry = RentalCustomerRequest::firstOrFail();
        $this->assertSame('pending', $entry->status);
        $this->assertSame('reserved', $order->fresh()->status);
        $this->assertSame(1, $order->payments()->count());
        $data['entry_key'] = (string) Str::uuid();
        $this->post($route, $data)->assertSessionHasErrors('customer_request');
        $this->post(route('admin.rentals.orders.action', $order), ['action' => 'dispatch'])->assertSessionHasErrors('rental');
        $this->postJson(route('admin.rentals.orders.extension-preview', $order), ['new_end_date' => '2026-11-01'])->assertUnprocessable();
        $this->post(route('admin.rentals.orders.action', $order), ['action' => 'cancel', 'customer_request_id' => $entry->id])->assertSessionHasErrors('rental');
        $this->review($order, $entry)->assertSessionHasNoErrors();
        $this->review($order, $entry)->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $order->refresh()->status);
        $this->assertEquals(37500, $order->cancellation_fee);
        $this->assertEquals(12500, $order->refundable());
        $this->assertSame(12500, $entry->fresh()->approved_amount);
        $this->assertSame(1, $order->payments()->count());
        $this->assertSame(3, app(RentalInventory::class)->available($this->product, '2026-10-28', '2026-10-30')->count());
        $document = app(RentalDocumentService::class)->issueInvoice($order, $this->admin);
        $this->assertSame('37500.00', $document->snapshot['totals']['cancellation_fee']);
        $this->get(route('admin.rentals.documents.show', [$order, $document]))->assertOk()->assertSee('Biaya pembatalan');
        $this->get(route('rental.portal.document', [$order->code, $document]))->assertOk()->assertSee('Biaya pembatalan')->assertDontSee('total tagihan nol');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_free_window_uses_request_date_even_if_reviewed_after_start_date(): void
    {
        $this->configure(['cancellation_free_days' => 3]);
        $order = $this->order(['start_date' => '2026-10-31', 'end_date' => '2026-11-02']);
        $this->confirm($order);
        $this->pay($order)->assertSessionHasNoErrors();
        $entry = $this->submit($order);
        $this->assertSame(0, $entry->estimate['fee']);
        $this->travel(4)->days();
        $this->review($order, $entry)->assertSessionHasNoErrors();
        $this->assertEquals(0, $order->fresh()->cancellation_fee);
        $this->assertSame(50000, $entry->fresh()->approved_amount);
    }

    public function test_rejection_preserves_order_and_allows_a_new_customer_request(): void
    {
        $order = $this->order();
        $entry = $this->submit($order);
        $this->review($order, $entry, 'reject')->assertSessionHasNoErrors();
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('rejected', $entry->fresh()->status);
        $this->review($order, $entry)->assertSessionHasErrors('customer_request');
        $next = $this->submit($order);
        $this->assertNotSame($entry->id, $next->id);
    }

    public function test_tampered_expired_cross_order_and_changed_balance_quotes_are_rejected(): void
    {
        $order = $this->order();
        $this->confirm($order);
        $this->open($order);
        $data = $this->requestData($order);
        $route = route('rental.portal.requests', $order->code);
        $this->post($route, array_replace($data, ['request_token' => 'forged']))->assertSessionHasErrors('customer_request');
        $this->pay($order)->assertSessionHasNoErrors();
        $this->post($route, $data)->assertSessionHasErrors('customer_request');
        $data = $this->requestData($order);
        $this->travel(16)->minutes();
        $this->post($route, $data)->assertSessionHasErrors('customer_request');
        $other = $this->order();
        $this->open($other);
        $this->assertNotSame($order->id, $other->id);
        $this->post(route('rental.portal.requests', $other->code), $this->requestData($order))->assertSessionHasErrors('customer_request');
        $this->assertDatabaseCount('rental_customer_requests', 0);
    }

    public function test_pending_payment_proof_and_changed_ledger_prevent_stale_approval(): void
    {
        $order = $this->order();
        $this->confirm($order);
        $this->pay($order)->assertSessionHasNoErrors();
        $entry = $this->submit($order);
        $order->paymentProofs()->create(['entry_key' => (string) Str::uuid(), 'kind' => 'settlement', 'amount' => 125000, 'payer_name' => 'Penyewa', 'payment_date' => '2026-10-28', 'file_path' => 'unused.jpg', 'file_hash' => str_repeat('a', 64), 'file_mime' => 'image/jpeg', 'file_size' => 100, 'status' => 'pending']);
        $this->review($order, $entry)->assertSessionHasErrors('customer_request');
        $order->paymentProofs()->update(['status' => 'rejected']);
        $this->pay($order, 5000)->assertSessionHasNoErrors();
        $this->review($order, $entry)->assertSessionHasErrors('customer_request');
        $this->assertSame('reserved', $order->fresh()->status);
        $this->assertSame('pending', $entry->fresh()->status);
    }

    public function test_refund_settlement_is_exactly_once_private_and_creates_one_receipt(): void
    {
        $order = $this->order();
        $this->confirm($order);
        $this->pay($order)->assertSessionHasNoErrors();
        $entry = $this->submit($order);
        $this->review($order, $entry)->assertSessionHasNoErrors();
        $this->pay($order, 12500, 'refund')->assertSessionHasErrors('rental');
        $data = $this->settleData();
        $route = route('admin.rentals.customer-requests.settle', [$order, $entry]);
        $this->post($route, $data)->assertSessionHasNoErrors();
        $this->post($route, $data)->assertSessionHasNoErrors();
        $this->assertSame('settled', $entry->fresh()->status);
        $this->assertSame(1, $order->payments()->where('kind', 'refund')->count());
        $this->assertSame(1, $order->documents()->where('type', 'refund_receipt')->count());
        $this->assertEquals(0, $order->fresh()->refundable());
        Storage::disk('local')->assertExists($entry->refund_file_path);
        $this->get(route('rental.portal.refund-file', [$order->code, $entry]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->post($route, array_replace($data, ['reference' => 'DIFFERENT']))->assertSessionHasErrors('customer_request');
        $this->get(route('admin.rentals.customer-requests.index', ['status' => 'settled']))->assertOk()->assertSee('Refund sudah dicatat');
        $this->get(route('admin.rentals.orders.show', $order))->assertOk()->assertSee('Kalkulator denda');
        $other = $this->order();
        $this->open($other);
        $this->get(route('rental.portal.refund-file', [$other->code, $entry]))->assertNotFound();
        $this->withSession(['rental_portal_access' => []])->get(route('rental.portal.refund-file', [$order->code, $entry]))->assertNotFound();
    }

    public function test_refund_proof_validation_and_pdf_download(): void
    {
        $order = $this->order();
        $this->confirm($order);
        $this->pay($order)->assertSessionHasNoErrors();
        $entry = $this->submit($order);
        $route = route('admin.rentals.customer-requests.settle', [$order, $entry]);
        $this->post($route, $this->settleData())->assertSessionHasErrors('customer_request');
        $this->review($order, $entry)->assertSessionHasNoErrors();
        $this->post($route, $this->settleData(['proof' => UploadedFile::fake()->createWithContent('x.svg', '<svg><script>alert(1)</script></svg>')]))->assertSessionHasErrors('proof');
        $this->post($route, $this->settleData(['proof' => UploadedFile::fake()->create('large.pdf', 5121, 'application/pdf')]))->assertSessionHasErrors('proof');
        $this->post($route, $this->settleData(['refund_completed' => 0]))->assertSessionHasErrors('refund_completed');
        $this->post($route, $this->settleData(['refund_date' => '2026-10-29']))->assertSessionHasErrors('refund_date');
        $this->post($route, $this->settleData(['proof' => UploadedFile::fake()->createWithContent('refund.pdf', "%PDF-1.4\n%%EOF")]))->assertSessionHasNoErrors();
        $this->get(route('rental.portal.refund-file', [$order->code, $entry]))->assertOk()->assertDownload('refund-'.$entry->id.'.pdf');
    }

    public function test_roles_and_nested_resource_scoping_protect_review_and_proofs(): void
    {
        $order = $this->order();
        $other = $this->order();
        $entry = $this->submit($order);
        $this->review($other, $entry)->assertNotFound();
        $this->get(route('admin.rentals.customer-requests.file', [$other, $entry]))->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'therapist', 'status' => 'active']));
        $this->get(route('admin.rentals.customer-requests.index'))->assertForbidden();
        $this->review($order, $entry)->assertForbidden();
        $this->post(route('admin.rentals.customer-requests.settle', [$order, $entry]), $this->settleData())->assertForbidden();
        $this->actingAs($this->admin);
        session()->forget('rental_portal_access.'.$other->id);
        $this->get(route('rental.portal.show', $other->code))->assertNotFound();
        $this->post(route('rental.portal.terms', $other->code), ['terms_hash' => $other->terms_snapshot['hash'], 'terms_accepted' => 1])->assertNotFound();
    }

    public function test_completed_orders_can_request_deposit_refund_but_rented_orders_cannot_cancel(): void
    {
        $order = $this->order();
        $this->confirm($order);
        $this->pay($order, 175000)->assertSessionHasNoErrors();
        $this->post(route('admin.rentals.orders.action', $order), ['action' => 'dispatch'])->assertSessionHasNoErrors();
        $this->open($order);
        $this->get(route('rental.portal.show', $order->code))->assertOk()->assertDontSee('Kirim Pengajuan Pembatalan');
        $item = $order->items()->firstOrFail();
        $this->post(route('admin.rentals.orders.return', [$order, $item]), ['notes' => 'Dikembalikan lebih awal untuk pengujian'])->assertSessionHasNoErrors();
        $this->post(route('admin.rentals.orders.inspect', [$order, $item]), ['notes' => 'Baik bersih dan lengkap', 'unit_status' => 'ready', 'late_fee' => 0, 'damage_fee' => 0])->assertSessionHasNoErrors();
        $entry = $this->submit($order, 'refund');
        $this->assertSame(100000, $entry->estimate['refund']);
        $this->review($order, $entry)->assertSessionHasNoErrors();
        $this->assertSame('completed', $order->fresh()->status);
        $this->post(route('admin.rentals.customer-requests.settle', [$order, $entry]), $this->settleData())->assertSessionHasNoErrors();
        $this->assertEquals(75000, $order->fresh()->received());
        $this->assertEquals(0, $order->refundable());
    }

    public function test_late_fee_uses_actual_return_and_original_policy_even_if_inspected_later(): void
    {
        $order = $this->order();
        $this->confirm($order);
        $this->post(route('admin.rentals.orders.action', $order), ['action' => 'dispatch'])->assertSessionHasNoErrors();
        $this->travelTo(Carbon::parse('2026-11-02 23:55:00', 'Asia/Jakarta'));
        $item = $order->items()->firstOrFail();
        $this->post(route('admin.rentals.orders.return', [$order, $item]), ['notes' => 'Dikembalikan terlambat sesuai kondisi aktual'])->assertSessionHasNoErrors();
        $this->configure(['late_fee_daily' => 99000, 'late_fee_grace_days' => 0]);
        $this->travel(10)->days();
        $calculation = app(RentalLateFee::class)->calculate($order->fresh(), $item->fresh());
        $this->assertSame(3, $calculation['late_days']);
        $this->assertSame(2, $calculation['billable_days']);
        $this->assertSame(20000, $calculation['suggested_fee']);
        $route = route('admin.rentals.orders.inspect', [$order, $item]);
        $data = ['notes' => 'Diperiksa lengkap dan bersih', 'unit_status' => 'ready', 'late_fee' => 15000, 'damage_fee' => 0];
        $this->post($route, $data)->assertSessionHasErrors('rental');
        $this->assertNull($item->fresh()->inspected_at);
        $this->post($route, $data + ['late_fee_reason' => 'Diskon denda disetujui karena koordinasi pengambilan'])->assertSessionHasNoErrors();
        $this->assertSame(15000, $item->fresh()->late_fee_calculation['applied_fee']);
        $this->assertEquals(90000, $order->fresh()->charges());
        $this->open($order);
        $this->get(route('rental.portal.show', $order->code))->assertOk()->assertSee('Diskon denda disetujui karena koordinasi pengambilan');
    }

    public function test_late_fee_calendar_day_grace_cap_and_per_unit_due_dates(): void
    {
        $order = $this->order(['items' => [['product_id' => $this->product->id, 'quantity' => 2]]]);
        $items = $order->items()->get();
        $this->travelTo(Carbon::parse('2026-11-10 00:01:00', 'Asia/Jakarta'));
        $items[0]->update(['returned_at' => '2026-10-30 23:59:59']);
        $this->assertSame(0, app(RentalLateFee::class)->calculate($order, $items[0])['suggested_fee']);
        $items[0]->update(['returned_at' => '2026-10-31 00:00:01']);
        $this->assertSame(0, app(RentalLateFee::class)->calculate($order, $items[0])['suggested_fee']);
        $items[1]->update(['rental_end_date' => '2026-11-05', 'returned_at' => '2026-11-10 00:00:00']);
        $calculation = app(RentalLateFee::class)->calculate($order, $items[1]);
        $this->assertSame(5, $calculation['late_days']);
        $this->assertSame(25000, $calculation['suggested_fee']);
        $order->update(['terms_snapshot' => null]);
        $this->assertNull(app(RentalLateFee::class)->calculate($order, $items[1])['policy']);
        $this->assertSame(3000, app(RentalLateFee::class)->finalize($order, $items[1], 3000, null)['applied_fee']);
    }

    public function test_settings_reject_invalid_policies_and_keep_snapshot_and_quote_integrity(): void
    {
        $input = $this->input();
        $input['quote_token'] = $this->quote($input);
        $settings = app(RentalSettings::class)->get();
        $route = route('admin.rentals.settings.update');
        $this->put($route, array_replace($settings, ['cancellation_fee_type' => 'percent', 'cancellation_fee_value' => 101]))->assertSessionHasErrors('cancellation_fee_value');
        $this->put($route, array_replace($settings, ['late_fee_daily' => -1]))->assertSessionHasErrors('late_fee_daily');
        $this->put($route, array_replace($settings, ['rental_terms' => '']))->assertSessionHasErrors('rental_terms');
        $this->put($route, array_replace($settings, ['late_fee_daily' => 12000]))->assertSessionHasNoErrors();
        $this->post(route('rental.store'), $input)->assertSessionHasErrors('rental');
        $this->get(route('admin.rentals.settings.index'))->assertOk()->assertSee('Ketentuan sewa, pembatalan');
    }

    public function test_long_unicode_terms_support_a_full_ten_product_cart(): void
    {
        $this->configure(['rental_terms' => str_repeat('👶', 10000)]);
        $items = [];
        foreach (range(1, 10) as $index) {
            $product = RentalProduct::create(['sku' => 'LONG-'.$index, 'name' => 'Alat '.$index, 'category' => 'Perlengkapan', 'terms' => str_repeat('👶', 5000), 'components' => str_repeat('👶', 3000), 'daily_rate' => 1000, 'deposit' => 0, 'is_active' => true]);
            RentalUnit::create(['rental_product_id' => $product->id, 'code' => 'LONG-'.$index, 'status' => 'ready']);
            $items[] = ['product_id' => $product->id, 'quantity' => 1];
        }
        $input = $this->input(['items' => $items]);
        $input['quote_token'] = $this->quote($input);
        $this->assertLessThan(1000000, strlen($input['quote_token']));
        $this->post(route('rental.store'), $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(10, RentalOrder::latest('id')->firstOrFail()->items()->count());
    }

    public function test_admin_cancel_ignores_injected_backdate_and_fee_and_manual_refund_cannot_bypass_approved_request(): void
    {
        $this->configure(['cancellation_free_days' => 3]);
        $order = $this->order();
        $this->confirm($order);
        $this->pay($order)->assertSessionHasNoErrors();
        $this->post(route('admin.rentals.orders.action', $order), ['action' => 'cancel', 'requested_at' => '2026-10-01', 'cancellation_fee' => 0])->assertSessionHasNoErrors();
        $this->assertEquals(37500, $order->fresh()->cancellation_fee);
        $entry = $this->submit($order, 'refund');
        $this->review($order, $entry)->assertSessionHasNoErrors();
        $this->post(route('admin.rentals.orders.payment', $order), ['entry_key' => (string) Str::uuid(), 'kind' => 'refund', 'amount' => 12500, 'method' => 'cash', 'customer_request_id' => $entry->id])->assertSessionHasErrors('rental');
        $this->assertSame(0, $order->payments()->where('kind', 'refund')->count());
    }

    public function test_anonymous_portal_holder_can_accept_and_request_cancellation_without_admin_access(): void
    {
        $order = $this->order([], false);
        $this->confirm($order);
        $this->open($order);
        auth()->logout();
        $this->post(route('rental.portal.terms', $order->code), ['terms_hash' => $order->terms_snapshot['hash'], 'terms_accepted' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('rental.portal.requests', $order->code), $this->requestData($order))->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('admin.rentals.customer-requests.index'))->assertRedirect(route('login'));
        $this->assertSame('pending', $order->customerRequests()->firstOrFail()->status);
        $this->assertSame('reserved', $order->fresh()->status);
    }

    public function test_approved_cancellation_closes_a_pending_extension_and_preserves_original_consent(): void
    {
        $order = $this->order();
        $this->confirm($order);
        $this->open($order);
        $extension = $order->extensionRequests()->create(['entry_key' => (string) Str::uuid(), 'new_end_date' => '2026-11-01', 'quoted_amount' => 50000, 'notes' => 'Permintaan uji sebelum pembatalan', 'status' => 'pending']);
        $hash = $order->terms_snapshot['hash'];
        $entry = $this->submit($order);
        $this->review($order, $entry)->assertSessionHasNoErrors();
        $this->assertSame('rejected', $extension->fresh()->status);
        $this->assertSame($hash, $order->fresh()->terms_snapshot['hash']);
        $this->assertSame('2026-10-30', $order->end_date->toDateString());
    }
}
