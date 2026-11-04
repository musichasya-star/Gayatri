<?php

namespace Tests\Feature;

use App\Jobs\SendLaundryNotificationJob;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\LaundryCourier;
use App\Models\LaundryDocument;
use App\Models\LaundryNotification;
use App\Models\LaundryOrder;
use App\Models\LaundryPayment;
use App\Models\LaundryPaymentProof;
use App\Models\LaundryPublicRequest;
use App\Models\LaundryTariff;
use App\Models\RentalOrder;
use App\Models\TherapistFee;
use App\Models\User;
use App\Services\CRM\LaundryCustomerPayment;
use App\Services\CRM\LaundryNotificationService;
use App\Services\CRM\LaundryOrderService;
use App\Services\CRM\LaundryPortal;
use App\Services\CRM\LaundryPublicSettings;
use App\Services\CRM\LaundrySettings;
use App\Services\CRM\LaundryTransportPricing;
use App\Services\CRM\LaundryTransportService;
use App\Services\WhatsApp\WhatsAppGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class LaundryCustomerPortalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private LaundryTariff $kg;

    private LaundryTariff $piece;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 31)->setTime(10, 0));
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('local');
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        app(LaundrySettings::class)->save(['accepting_orders' => true, 'outlet_name' => 'Laundry Uji', 'address' => 'Alamat outlet uji', 'whatsapp_number' => '6280000019000', 'opening_hours' => '08–17', 'terms' => 'Periksa label pakaian', 'allow_collection' => true], $this->admin, Request::create('/'));
        $settings = app(LaundryPublicSettings::class);
        $settings->save(array_replace($settings->get(), ['enabled' => true, 'bank_name' => 'Bank Uji', 'account_number' => '000000', 'account_holder' => 'DEMO']), $this->admin, Request::create('/'));
        $this->kg = LaundryTariff::create(['code' => 'PUBLIC-KG', 'name' => 'Cuci Bayi', 'audience' => 'baby', 'unit' => 'kg', 'price' => 7500, 'minimum_milli' => 3000, 'increment_milli' => 500, 'duration_hours' => 24, 'is_active' => true]);
        $this->piece = LaundryTariff::create(['code' => 'PUBLIC-PC', 'name' => 'Selimut', 'audience' => 'both', 'unit' => 'piece', 'price' => 15000, 'minimum_milli' => 1000, 'increment_milli' => 1000, 'duration_hours' => 48, 'is_active' => true]);
    }

    private function data(array $changes = []): array
    {
        $key = $this->get(route('laundry.index'))->assertOk()->viewData('entryKey');

        return array_replace(['entry_key' => $key, 'name' => 'Bunda Portal', 'whatsapp_number' => '080000019001', 'fulfilment' => 'dropoff', 'items' => [['laundry_tariff_id' => $this->kg->id, 'quantity' => '3.1'], ['laundry_tariff_id' => $this->piece->id, 'quantity' => '2']]], $changes);
    }

    public function test_expired_estimate_keeps_the_editable_draft(): void
    {
        $data = $this->data();
        $preview = $this->post(route('laundry.preview'), $data)->assertOk()->assertSee('Estimasi total')->viewData('preview');

        $this->get(route('laundry.index'))->assertOk()->assertSee($data['name'])->assertViewHas('draft', fn ($draft) => $draft['items'] === $data['items']);
        $this->travel(16)->minutes();
        $this->post(route('laundry.store'), ['token' => $preview['token'], 'confirmed' => 1])
            ->assertRedirect(route('laundry.index'))->assertSessionHasErrors('laundry');
        $this->get(route('laundry.index'))->assertOk()->assertSee($data['name']);
        $this->assertDatabaseCount('laundry_public_requests', 0);
    }

    private function entry(array $changes = []): LaundryPublicRequest
    {
        $preview = $this->post(route('laundry.preview'), $this->data($changes))->assertOk()->viewData('preview');
        $this->post(route('laundry.store'), ['token' => $preview['token'], 'confirmed' => 1])->assertSessionHasNoErrors()->assertRedirect();

        return LaundryPublicRequest::latest('id')->firstOrFail();
    }

    private function verify(LaundryPublicRequest $entry): void
    {
        $this->actingAs($this->admin)->post(route('admin.laundry.requests.verify', $entry), ['revision' => $entry->revision, 'decision' => 'verify', 'reference' => 'Pelanggan dikonfirmasi melalui telepon.', 'confirmed' => 1])->assertSessionHasNoErrors();
        $entry->refresh();
    }

    private function order(array $changes = []): array
    {
        $entry = $this->entry($changes);
        $this->verify($entry);
        $this->post(route('admin.laundry.requests.intake', $entry), ['confirmed' => 1])->assertRedirect(route('admin.laundry.orders.create'));
        $draft = session('laundry.intake_draft');
        foreach ($draft['items'] as &$line) {
            $line['received_pieces'] = 2;
            $line['condition_notes'] = 'Dua potong telah diperiksa.';
        }
        unset($line);
        $preview = $this->post(route('admin.laundry.orders.preview'), $draft)->assertOk()->viewData('preview');
        $this->post(route('admin.laundry.orders.store'), ['token' => $preview['token'], 'confirmed' => 1])->assertSessionHasNoErrors();
        $order = $entry->fresh()->order;
        $this->post(route('admin.laundry.orders.weigh', $order), ['revision' => $order->revision, 'items' => $order->items->map(fn ($item) => ['id' => $item->id, 'actual_pieces' => 2, 'quantity' => '2.31', 'notes' => 'Jumlah sesuai.'])->all()])->assertSessionHasNoErrors();
        $this->app['auth']->forgetGuards();

        return [$entry->fresh(), $order->fresh()];
    }

    private function approval(LaundryPublicRequest $entry, LaundryOrder $order, array $changes = []): array
    {
        return array_replace(['revision' => $order->fresh()->revision, 'fingerprint' => $order->fresh()->pricingFingerprint(), 'approved_name' => $entry->name, 'confirmed' => 1], $changes);
    }

    private function approve(LaundryPublicRequest $entry, LaundryOrder $order): void
    {
        $this->post(route('laundry.portal.approve', $entry->public_id), $this->approval($entry, $order))->assertSessionHasNoErrors();
        $order->refresh();
    }

    private function proofData(LaundryOrder $order, array $changes = []): array
    {
        return array_replace(['entry_key' => (string) Str::uuid(), 'revision' => $order->fresh()->revision, 'fingerprint' => $order->fresh()->pricingFingerprint(), 'amount' => 15000, 'paid_at' => '2026-10-31T10:00', 'payer_name' => 'Bunda Portal', 'reference' => 'TRF-TEST-123', 'confirmed' => 1, 'file' => UploadedFile::fake()->createWithContent('bukti.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+nmGQAAAAASUVORK5CYII='))], $changes);
    }

    private function proof(LaundryPublicRequest $entry, LaundryOrder $order, array $changes = []): LaundryPaymentProof
    {
        $this->post(route('laundry.portal.proof', $entry->public_id), $this->proofData($order, $changes))->assertSessionHasNoErrors();

        return $order->paymentProofs()->latest('id')->firstOrFail();
    }

    private function review(LaundryPaymentProof $proof, string $decision = 'approve')
    {
        return $this->actingAs($this->admin)->post(route('admin.laundry.requests.proofs.review', $proof), ['revision' => $proof->revision, 'decision' => $decision, 'notes' => 'Bukti dan mutasi rekening sudah diperiksa.', 'confirmed' => 1]);
    }

    public function test_public_request_is_estimate_only_and_retry_does_not_create_crm_or_orders(): void
    {
        $data = $this->data();
        $preview = $this->post(route('laundry.preview'), $data)->assertOk()->viewData('preview');
        $this->assertSame(56250, $preview['estimate']['total']);
        foreach (range(1, 2) as $_) {
            $this->post(route('laundry.store'), ['token' => $preview['token'], 'confirmed' => 1])->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('laundry_public_requests', 1);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('laundry_orders', 0);
        $this->assertSame(0, Booking::count() + RentalOrder::count() + TherapistFee::count());
        Queue::assertNothingPushed();
    }

    public function test_unknown_weight_and_shipping_never_appear_free(): void
    {
        $settings = app(LaundryTransportPricing::class);
        $settings->save(array_replace($settings->settings(), ['enabled' => true]), $this->admin, Request::create('/'));
        $preview = $this->post(route('laundry.preview'), $this->data(['delivery_required' => 1, 'address' => 'Alamat lengkap pelanggan uji', 'items' => [['laundry_tariff_id' => $this->kg->id]]]))->assertOk()->viewData('preview');
        $this->assertNull($preview['estimate']['service_total']);
        $this->assertNull($preview['estimate']['transport_total']);
        $this->assertNull($preview['estimate']['total']);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_disabled_form_closed_and_piece_fraction_duplicate_inactive_rejected(): void
    {
        $this->post(route('laundry.preview'), $this->data(['items' => [['laundry_tariff_id' => $this->piece->id, 'quantity' => '1.5']]]))->assertRedirect(route('laundry.index'))->assertSessionHasErrors();
        $this->post(route('laundry.preview'), $this->data(['items' => [['laundry_tariff_id' => $this->kg->id], ['laundry_tariff_id' => $this->kg->id]]]))->assertSessionHasErrors();
        $this->piece->update(['is_active' => false]);
        $this->post(route('laundry.preview'), $this->data())->assertSessionHasErrors();
        $s = app(LaundryPublicSettings::class);
        $s->save(array_replace($s->get(), ['enabled' => false]), $this->admin, Request::create('/'));
        $this->get(route('laundry.index'))->assertOk()->assertSee('Pemesanan online belum dibuka');
        $this->post(route('laundry.preview'), $this->data())->assertSessionHasErrors();
    }

    public function test_snapshot_changes_expiry_and_foreign_session_cannot_commit(): void
    {
        $preview = $this->post(route('laundry.preview'), $this->data())->assertOk()->viewData('preview');
        $this->kg->update(['price' => 9000]);
        $this->post(route('laundry.store'), ['token' => $preview['token'], 'confirmed' => 1])->assertSessionHasErrors();
        $preview = $this->post(route('laundry.preview'), $this->data())->assertOk()->viewData('preview');
        $this->travel(16)->minutes();
        $this->post(route('laundry.store'), ['token' => $preview['token'], 'confirmed' => 1])->assertSessionHasErrors();
        $this->flushSession();
        $this->post(route('laundry.store'), ['token' => $preview['token'], 'confirmed' => 1])->assertSessionHasErrors();
        $this->assertDatabaseCount('laundry_public_requests', 0);
    }

    public function test_portal_uses_encrypted_fragment_link_and_private_self_hosted_page(): void
    {
        $entry = $this->entry();
        $link = app(LaundryPortal::class)->link($entry);
        $this->assertStringContainsString('/laundry/akses#', $link);
        $token = $entry->portal_token_secret;
        $this->assertNotSame($token, DB::table('laundry_public_requests')->value('portal_token_secret'));
        $this->assertArrayNotHasKey('portal_token_secret', $entry->toArray());
        $response = $this->get(route('laundry.portal.show', $entry->public_id))->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString("script-src 'self'", $response->headers->get('Content-Security-Policy'));
        $response->assertDontSee('unpkg.com')->assertDontSee('fonts.googleapis.com');
        $this->assertStringNotContainsString($token, json_encode(DB::table('audit_logs')->get()));
        $this->flushSession();
        $this->get(route('laundry.portal.show', $entry->public_id))->assertNotFound();
        $this->post(route('laundry.portal.access'), ['token' => $token])->assertRedirect(route('laundry.portal.show', $entry->public_id));
        $this->get(route('laundry.portal.show', $entry->public_id))->assertOk();
    }

    public function test_rotation_revocation_expiry_and_session_expiry_remove_access(): void
    {
        $entry = $this->entry();
        $old = $entry->portal_token_secret;
        app(LaundryPortal::class)->issue($entry, Request::create('/'), $this->admin);
        $this->get(route('laundry.portal.show', $entry->public_id))->assertNotFound();
        $this->post(route('laundry.portal.access'), ['token' => $old])->assertNotFound();
        $this->post(route('laundry.portal.access'), ['token' => $entry->fresh()->portal_token_secret])->assertRedirect();
        $this->travel(121)->minutes();
        $this->get(route('laundry.portal.show', $entry->public_id))->assertNotFound();
        $this->post(route('laundry.portal.access'), ['token' => $entry->fresh()->portal_token_secret])->assertRedirect();
        app(LaundryPortal::class)->revoke($entry, $this->admin, Request::create('/'));
        $this->get(route('laundry.portal.show', $entry->public_id))->assertNotFound();
        $entry->update(['portal_expires_at' => now()->subMinute()]);
        $this->assertNull(app(LaundryPortal::class)->link($entry));
    }

    public function test_verified_contact_preserves_existing_customer_and_physical_counts_are_required(): void
    {
        $customer = Customer::create(['name' => 'Nama CRM Asli', 'whatsapp_number' => '6280000019001', 'status' => 'lead']);
        $entry = $this->entry();
        $this->verify($entry);
        $this->assertSame($customer->id, $entry->customer_id);
        $this->assertSame('Nama CRM Asli', $customer->fresh()->name);
        $this->post(route('admin.laundry.requests.intake', $entry), ['confirmed' => 1])->assertSessionHasNoErrors();
        $draft = session('laundry.intake_draft');
        $this->assertSame('', $draft['items'][0]['received_pieces']);
        $this->post(route('admin.laundry.orders.preview'), $draft)->assertSessionHasErrors(['items.0.received_pieces', 'items.0.condition_notes']);
        $this->assertDatabaseCount('laundry_orders', 0);
    }

    public function test_rejection_and_unverified_intake_cannot_create_orders(): void
    {
        $entry = $this->entry();
        $this->actingAs($this->admin)->post(route('admin.laundry.requests.intake', $entry), ['confirmed' => 1])->assertUnprocessable();
        $this->post(route('admin.laundry.requests.verify', $entry), ['revision' => 1, 'decision' => 'reject', 'reference' => 'Layanan tidak tersedia saat ini.', 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->get(route('laundry.portal.show', $entry->public_id))->assertOk()->assertSee('Layanan tidak tersedia saat ini.');
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('laundry_orders', 0);
    }

    public function test_exact_quote_approval_is_idempotent_and_reweigh_invalidates_consent(): void
    {
        [$entry,$order] = $this->order();
        $data = $this->approval($entry, $order);
        $this->post(route('laundry.portal.approve', $entry->public_id), array_replace($data, ['fingerprint' => str_repeat('a', 64)]))->assertSessionHasErrors();
        $this->approve($entry, $order);
        $this->post(route('laundry.portal.approve', $entry->public_id), $data)->assertSessionHasNoErrors();
        $this->assertSame(1, $order->events()->where('kind', 'approved')->count());
        $this->assertSame('portal', $order->fresh()->approval_channel);
        $this->actingAs($this->admin)->get(route('admin.laundry.orders.show', $order))->assertOk()->assertSee('Portal pelanggan');
        $service = app(LaundryOrderService::class);
        $service->weigh($order, ['revision' => $order->fresh()->revision, 'reason' => 'Periksa ulang berat nyata', 'items' => $order->items->map(fn ($item) => ['id' => $item->id, 'actual_pieces' => 2, 'quantity' => '4.1'])->all()], $this->admin, Request::create('/'));
        $this->post(route('laundry.portal.approve', $entry->public_id), $data)->assertSessionHasErrors();
        $this->assertNull($order->fresh()->approval_fingerprint);
    }

    public function test_required_delivery_must_be_priced_or_explicitly_free_before_approval(): void
    {
        $s = app(LaundryTransportPricing::class);
        $s->save(array_replace($s->settings(), ['enabled' => true]), $this->admin, Request::create('/'));
        [$entry,$order] = $this->order(['delivery_required' => 1, 'address' => 'Alamat lengkap pelanggan uji']);
        $this->assertFalse(LaundryPortal::priceReady($order));
        $this->post(route('laundry.portal.approve', $entry->public_id), $this->approval($entry, $order))->assertSessionHasErrors();
        $this->actingAs($this->admin)->post(route('admin.laundry.transport.waive', $order), ['revision' => $order->revision, 'kind' => 'delivery', 'approved_name' => $entry->name, 'reason' => 'Gratis antar disepakati pelanggan.', 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertTrue(LaundryPortal::priceReady($order->fresh()));
        $this->approve($entry, $order);
    }

    public function test_pending_proof_is_private_and_not_a_payment_and_retries_do_not_duplicate(): void
    {
        [$entry,$order] = $this->order();
        $this->approve($entry, $order);
        $data = $this->proofData($order);
        $this->post(route('laundry.portal.proof', $entry->public_id), $data)->assertSessionHasNoErrors();
        $this->post(route('laundry.portal.proof', $entry->public_id), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('laundry_payment_proofs', 1);
        $this->assertDatabaseCount('laundry_payments', 0);
        $this->assertSame(52500, $order->fresh()->balance());
        $proof = LaundryPaymentProof::first();
        Storage::disk('local')->assertExists($proof->file_path);
        $this->get(route('laundry.portal.show', $entry->public_id))->assertOk()->assertSee('menunggu pemeriksaan admin');
        $this->get(route('laundry.portal.proof-file', [$entry->public_id, $proof->id]))->assertOk();
        $this->post(route('laundry.portal.proof', $entry->public_id), $this->proofData($order))->assertSessionHasErrors();
        $this->flushSession();
        $this->get(route('laundry.portal.proof-file', [$entry->public_id, $proof->id]))->assertNotFound();
        Queue::assertNothingPushed();
    }

    public function test_pending_proof_blocks_manual_credit_reweigh_and_cancellation(): void
    {
        [$entry,$order] = $this->order();
        $this->approve($entry, $order);
        $this->proof($entry, $order);
        $order->refresh();
        $this->assertFalse($order->canReweigh());
        $this->actingAs($this->admin)->post(route('admin.laundry.payments.store', $order), ['revision' => $order->revision, 'entry_key' => (string) Str::uuid(), 'amount' => 10000, 'method' => 'cash', 'paid_at' => '2026-10-31T10:00', 'payer_name' => $entry->name, 'confirmed' => 1])->assertSessionHasErrors();
        $this->post(route('admin.laundry.orders.cancel', $order), ['revision' => $order->revision, 'reason' => 'Percobaan pembatalan uji'])->assertSessionHasErrors();
        $this->assertDatabaseCount('laundry_payments', 0);
    }

    public function test_approval_records_payment_receipt_once_and_customer_can_print_own_document(): void
    {
        [$entry,$order] = $this->order();
        $this->approve($entry, $order);
        $proof = $this->proof($entry, $order);
        $this->review($proof)->assertSessionHasNoErrors();
        $this->review($proof)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('laundry_payments', 1);
        $this->assertDatabaseCount('laundry_documents', 1);
        $this->assertSame(37500, $order->fresh()->balance());
        $payment = LaundryPayment::first();
        $this->assertSame($proof->file_path, $payment->proof_path);
        $this->assertSame('approved', $proof->fresh()->status);
        $doc = LaundryDocument::first();
        $this->get(route('laundry.portal.document', [$entry->public_id, $doc->id]))->assertOk()->assertSee('15.000')->assertSee(route('laundry.portal.show', $entry->public_id));
        $this->get(route('admin.laundry.orders.show', $order))->assertOk();
    }

    public function test_rejection_leaves_balance_and_allows_corrected_new_upload(): void
    {
        [$entry,$order] = $this->order();
        $this->approve($entry, $order);
        $proof = $this->proof($entry, $order);
        $this->review($proof, 'reject')->assertSessionHasNoErrors();
        $this->assertSame(52500, $order->fresh()->balance());
        $this->assertDatabaseCount('laundry_payments', 0);
        $this->get(route('laundry.portal.show', $entry->public_id))->assertOk()->assertSee('Bukti dan mutasi rekening sudah diperiksa.');
        $this->proof($entry, $order);
        $this->assertDatabaseCount('laundry_payment_proofs', 2);
        $this->review($proof)->assertSessionHasErrors();
    }

    public function test_customer_sees_voided_credit_and_restored_balance_without_losing_proof_history(): void
    {
        [$entry, $order] = $this->order();
        $this->approve($entry, $order);
        $proof = $this->proof($entry, $order);
        $this->review($proof)->assertSessionHasNoErrors();
        $payment = LaundryPayment::firstOrFail();
        $this->post(route('admin.laundry.payments.void', [$order, $payment]), ['revision' => $order->fresh()->revision, 'reason' => 'Mutasi bank perlu dicocokkan ulang.', 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertSame(52500, $order->fresh()->balance());
        $this->get(route('laundry.portal.show', $entry->public_id))->assertOk()->assertSee('Pencatatan pembayaran ini dikoreksi')->assertSee('Mutasi bank perlu dicocokkan ulang.');
        $this->get(route('laundry.portal.proof-file', [$entry->public_id, $proof->id]))->assertOk();
        $this->assertNotNull(LaundryDocument::first()->voided_at);
    }

    public function test_overpayment_stale_price_invalid_file_and_future_date_are_rejected(): void
    {
        [$entry,$order] = $this->order();
        $this->approve($entry, $order);
        foreach ([['amount' => 52501], ['fingerprint' => str_repeat('a', 64)], ['revision' => 1], ['paid_at' => '2026-11-01T10:00'], ['file' => UploadedFile::fake()->createWithContent('bad.html', '<script>bad</script>')], ['file' => UploadedFile::fake()->create('large.pdf', 5121, 'application/pdf')]] as $change) {
            $this->post(route('laundry.portal.proof', $entry->public_id), $this->proofData($order, $change))->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('laundry_payment_proofs', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_another_portal_cannot_download_proof_or_document_from_first_order(): void
    {
        [$entry,$order] = $this->order();
        $this->approve($entry, $order);
        $proof = $this->proof($entry, $order);
        $this->review($proof)->assertSessionHasNoErrors();
        $other = $this->entry(['whatsapp_number' => '080000019009']);
        $this->get(route('laundry.portal.proof-file', [$other->public_id, $proof->id]))->assertNotFound();
        $this->get(route('laundry.portal.document', [$other->public_id, LaundryDocument::first()->id]))->assertNotFound();
    }

    public function test_admin_routes_and_files_reject_sales_and_inactive_users(): void
    {
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active']);
        foreach (['admin.laundry.requests.index', 'admin.laundry.requests.proofs', 'admin.laundry.requests.settings'] as $route) {
            $this->actingAs($sales)->get(route($route))->assertForbidden();
        }
        $this->admin->update(['status' => 'inactive']);
        $this->actingAs($this->admin)->get(route('admin.laundry.requests.index'))->assertRedirect(route('login'));
    }

    public function test_admin_pages_render_and_shared_link_is_only_on_private_share_page(): void
    {
        [$entry,$order] = $this->order();
        $this->approve($entry, $order);
        $this->proof($entry, $order);
        foreach (['admin.laundry.requests.index', 'admin.laundry.requests.proofs', 'admin.laundry.requests.settings'] as $route) {
            $this->actingAs($this->admin)->get(route($route))->assertOk();
        }
        $this->get(route('admin.laundry.requests.show', $entry))->assertOk()->assertDontSee($entry->portal_token_secret);
        $this->post(route('admin.laundry.requests.portal', $entry), ['action' => 'share'])->assertOk()->assertSee($entry->portal_token_secret)->assertDontSee('unpkg.com')->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_pickup_request_links_completed_visit_and_fee_to_single_intake(): void
    {
        $pricing = app(LaundryTransportPricing::class);
        $pricing->save(array_replace($pricing->settings(), ['enabled' => true]), $this->admin, Request::create('/'));
        $entry = $this->entry(['fulfilment' => 'pickup', 'preferred_at' => '2026-10-31T11:00', 'address' => 'Alamat lengkap penjemputan uji']);
        $this->verify($entry);
        $courier = LaundryCourier::create(['name' => 'Petugas uji', 'is_active' => true]);
        $data = ['public_request_id' => $entry->id, 'entry_key' => (string) Str::uuid(), 'kind' => 'pickup', 'customer_id' => $entry->customer_id, 'laundry_courier_id' => $courier->id, 'address' => $entry->address, 'scheduled_start' => '2026-10-31T11:00', 'scheduled_end' => '2026-10-31T12:00', 'distance_source' => 'manual', 'distance_km' => '4', 'distance_note' => 'Jarak jalan diperiksa.', 'approved_name' => $entry->name, 'approval_reference' => 'Ongkir jemput disetujui pelanggan.'];
        $preview = $this->post(route('admin.laundry.transport.preview'), $data)->assertOk()->viewData('preview');
        foreach (range(1, 2) as $_) {
            $this->post(route('admin.laundry.transport.store'), ['token' => $preview['token'], 'confirmed' => 1])->assertSessionHasNoErrors();
        }
        $entry->refresh();
        $task = $entry->pickupTask;
        $this->assertNotNull($task);
        $this->post(route('admin.laundry.requests.intake', $entry), ['confirmed' => 1])->assertSessionHasErrors();
        $service = app(LaundryTransportService::class);
        $service->action($task, ['revision' => $task->revision, 'action' => 'start'], null, $this->admin, Request::create('/'));
        $service->action($task->fresh(), ['revision' => $task->fresh()->revision, 'action' => 'complete', 'receiver_name' => 'Petugas outlet', 'pieces' => 4, 'notes' => 'Empat potong diterima.', 'confirmed' => 1], null, $this->admin, Request::create('/'));
        $this->post(route('admin.laundry.requests.intake', $entry), ['confirmed' => 1])->assertSessionHasNoErrors();
        $draft = session('laundry.intake_draft');
        foreach ($draft['items'] as &$line) {
            $line['received_pieces'] = 2;
            $line['condition_notes'] = 'Dua potong diperiksa.';
        }
        unset($line);
        $preview = $this->post(route('admin.laundry.orders.preview'), $draft)->assertOk()->viewData('preview');
        foreach (range(1, 2) as $_) {
            $this->post(route('admin.laundry.orders.store'), ['token' => $preview['token'], 'confirmed' => 1])->assertSessionHasNoErrors();
        }
        $entry->refresh();
        $this->assertSame(10000, $entry->order->pickup_fee);
        $this->assertSame($entry->order->id, $task->fresh()->laundry_order_id);
        $this->assertSame('received', $entry->status);
        $this->assertDatabaseCount('laundry_transport_tasks', 1);
        $this->assertDatabaseCount('laundry_orders', 1);
    }

    public function test_public_estimate_enforces_delivery_radius_and_integer_piece_quantity(): void
    {
        $pricing = app(LaundryTransportPricing::class);
        $pricing->save(array_replace($pricing->settings(), ['enabled' => true, 'origin_latitude' => '-7.8', 'origin_longitude' => '112', 'max_distance_km' => '5']), $this->admin, Request::create('/'));
        $this->post(route('laundry.preview'), $this->data(['delivery_required' => 1, 'address' => 'Alamat jauh dari outlet uji', 'latitude' => -7, 'longitude' => 112]))->assertSessionHasErrors();
        $preview = $this->post(route('laundry.preview'), $this->data(['delivery_required' => 1, 'address' => 'Alamat dekat dengan outlet uji', 'latitude' => -7.8, 'longitude' => 112.01]))->assertOk()->viewData('preview');
        $this->assertSame(10000, $preview['estimate']['transport_total']);
        $this->assertSame(66250, $preview['estimate']['total']);
        $this->assertDatabaseCount('laundry_public_requests', 0);
    }

    public function test_failed_upload_transaction_removes_private_file_and_leaves_no_proof(): void
    {
        [$entry, $order] = $this->order();
        $this->approve($entry, $order);
        $data = $this->proofData($order);
        Event::listen('eloquent.created: '.LaundryPaymentProof::class, function () {
            throw new \RuntimeException('Simulated storage transaction failure');
        });
        try {
            app(LaundryCustomerPayment::class)->upload($entry, $data, $data['file'], Request::create('/'));
            $this->fail('Expected simulated failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated storage transaction failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('laundry_payment_proofs', 0);
        $this->assertDatabaseCount('laundry_payments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_rejected_proof_whatsapp_contains_reason_and_redacted_portal_without_credit(): void
    {
        [$entry, $order] = $this->order();
        $this->approve($entry, $order);
        $proof = $this->proof($entry, $order);
        $service = app(LaundryNotificationService::class);
        $service->save(array_replace($service->settings(), ['enabled' => true, 'dry_run' => true]), $this->admin, Request::create('/'));
        $this->review($proof, 'reject')->assertSessionHasNoErrors();
        $row = LaundryNotification::where('kind', 'proof_rejected')->firstOrFail();
        $this->assertSame('simulated', $row->status);
        $this->assertStringContainsString('Bukti dan mutasi rekening sudah diperiksa.', $row->content);
        $this->assertStringContainsString('[tautan-pribadi]', $row->content);
        $this->assertStringNotContainsString($entry->portal_token_secret, $row->content);
        $this->assertSame(52500, $order->fresh()->balance());
        Queue::assertNothingPushed();
    }

    public function test_portal_notification_keeps_secret_out_of_outbox_and_inbox_and_rotation_skips_stale_job(): void
    {
        [$entry,$order] = $this->order();
        $this->approve($entry, $order);
        $s = app(LaundryNotificationService::class);
        $s->save(array_replace($s->settings(), ['enabled' => true, 'dry_run' => false]), $this->admin, Request::create('/'));
        $row = $s->enqueue($order, null, 'portal_shared', 'test-portal');
        $this->assertSame('queued', $row->status);
        $this->assertStringNotContainsString($entry->portal_token_secret, $row->content);
        $gateway = \Mockery::mock(WhatsAppGateway::class);
        $gateway->shouldReceive('sendText')->once()->withArgs(fn ($session, $chat, $text) => str_contains($text, '/laundry/akses#'.$entry->portal_token_secret))->andReturn(['id' => 'TEST-LND-PORTAL']);
        (new SendLaundryNotificationJob($row->id))->handle($gateway, $s);
        $this->assertSame('sent', $row->fresh()->status);
        $this->assertStringNotContainsString($entry->portal_token_secret, json_encode(DB::table('messages')->get()));
        $row = $s->enqueue($order, null, 'portal_shared', 'test-portal-rotate');
        app(LaundryPortal::class)->issue($entry, Request::create('/'), $this->admin);
        RateLimiter::clear('crm:laundry-whatsapp-rate');
        (new SendLaundryNotificationJob($row->id))->handle($gateway, $s);
        $this->assertSame('skipped', $row->fresh()->status);
    }

    public function test_admin_share_reports_disabled_and_simulation_modes_truthfully(): void
    {
        [$entry, $order] = $this->order();
        $this->actingAs($this->admin)->post(route('admin.laundry.requests.portal', $entry), ['action' => 'whatsapp'])->assertSessionHasErrors('notification');
        $this->assertDatabaseCount('laundry_notifications', 0);
        $service = app(LaundryNotificationService::class);
        $service->save(array_replace($service->settings(), ['enabled' => true, 'dry_run' => true]), $this->admin, Request::create('/'));
        $this->post(route('admin.laundry.requests.portal', $entry), ['action' => 'whatsapp'])->assertSessionHas('status', 'Simulasi berbagi portal dicatat. Tidak ada pesan WhatsApp yang dikirim.');
        $this->assertSame('simulated', LaundryNotification::first()->status);
        Queue::assertNothingPushed();
    }
}
