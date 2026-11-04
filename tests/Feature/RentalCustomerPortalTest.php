<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\RentalOrder;
use App\Models\RentalPaymentProof;
use App\Models\RentalProduct;
use App\Models\RentalUnit;
use App\Models\User;
use App\Services\CRM\RentalDocumentService;
use App\Services\CRM\RentalOperations;
use App\Services\CRM\RentalPaymentTerms;
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
use Tests\TestCase;

class RentalCustomerPortalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private RentalProduct $product;

    private RentalProduct $chair;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-28 09:00:00', 'Asia/Jakarta'));
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('local');
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($this->admin);
        $this->customer = Customer::create(['name' => 'Nama Pelanggan Internal', 'whatsapp_number' => '6281234567890', 'status' => 'lead']);
        $this->product = RentalProduct::create(['sku' => 'PORTAL-ST', 'name' => 'Stroller Portal', 'category' => 'Stroller', 'daily_rate' => 25000, 'weekly_rate' => 140000, 'deposit' => 100000, 'turnaround_days' => 1, 'is_active' => true]);
        $this->chair = RentalProduct::create(['sku' => 'PORTAL-CH', 'name' => 'Kursi Portal', 'category' => 'Kursi', 'daily_rate' => 20000, 'deposit' => 75000, 'turnaround_days' => 1, 'is_active' => true]);
        foreach ([$this->product, $this->chair] as $product) {
            RentalUnit::create(['rental_product_id' => $product->id, 'code' => $product->sku.'-001', 'status' => 'ready', 'condition_notes' => 'Bersih, lengkap']);
        }
    }

    private function configure(array $changes = []): void
    {
        $settings = app(RentalSettings::class);
        $settings->save(array_replace($settings->get(), ['dp_type' => 'percent', 'dp_value' => 50, 'dp_due_hours' => 24, 'require_full_payment' => true, 'payment_instructions' => '[UJI] Konfirmasi rekening kepada admin.'], $changes));
    }

    private function data(array $changes = []): array
    {
        return array_replace(['items' => [['product_id' => $this->product->id, 'quantity' => 1]], 'start_date' => '2026-10-28', 'end_date' => '2026-10-30', 'period' => 'day', 'fulfilment' => 'self', 'customer_id' => $this->customer->id], $changes);
    }

    private function order(array $changes = [], bool $public = false): RentalOrder
    {
        $data = $this->data($changes);
        $data['quote_token'] = $this->postJson(route('rental.quote'), $data)->assertOk()->json('token');
        if ($public) {
            $data += ['name' => 'Penyewa Portal Sendiri', 'whatsapp_number' => '081234567890', 'terms_accepted' => 1];
        }
        $this->post(route($public ? 'rental.store' : 'admin.rentals.orders.store'), $data)->assertSessionHasNoErrors();

        $order = RentalOrder::latest('id')->firstOrFail();
        if (! $public) {
            // Fixture consent; the policy suite covers the real portal acceptance flow.
            $order->update(['terms_accepted_at' => now(), 'terms_acceptance_source' => 'portal']);
        }

        return $order;
    }

    private function confirmed(array $changes = []): RentalOrder
    {
        $this->configure();
        $order = $this->order($changes);
        $this->post(route('admin.rentals.orders.action', $order), ['action' => 'confirm'])->assertSessionHasNoErrors();

        return $order->refresh();
    }

    private function openPortal(RentalOrder $order): string
    {
        $request = Request::create('/');
        $request->setLaravelSession($this->app['session.store']);
        $link = app(RentalPortalService::class)->issue($order, $request, $this->admin);
        $this->get($link)->assertRedirect(route('rental.portal.show', $order->code))->assertHeader('Referrer-Policy', 'no-referrer');

        return $link;
    }

    private function proofData(array $changes = []): array
    {
        return array_replace(['entry_key' => (string) Str::uuid(), 'kind' => 'dp', 'amount' => 37500, 'payer_name' => 'Ibu Pembayar', 'payment_date' => '2026-10-28', 'reference' => 'TRANSFER-001', 'proof' => UploadedFile::fake()->image('transfer.jpg')], $changes);
    }

    private function submitProof(RentalOrder $order, array $changes = []): RentalPaymentProof
    {
        $this->openPortal($order);
        $this->post(route('rental.portal.proof', $order->code), $this->proofData($changes))->assertSessionHasNoErrors();

        return $order->paymentProofs()->latest('id')->firstOrFail();
    }

    private function review(RentalOrder $order, RentalPaymentProof $proof, string $action = 'approve')
    {
        return $this->post(route('admin.rentals.proofs.review', [$order, $proof]), ['action' => $action, 'review_notes' => 'Dana sudah dicocokkan dengan penerimaan uji']);
    }

    public function test_cart_is_session_scoped_persistent_and_supports_multiple_products(): void
    {
        $this->post(route('rental.cart.add'), ['product_id' => $this->product->id, 'quantity' => 1])->assertRedirect(route('rental.cart.index'));
        $this->post(route('rental.cart.add'), ['product_id' => $this->chair->id, 'quantity' => 1])->assertSessionHasNoErrors();
        $this->get(route('rental.cart.index'))->assertOk()->assertSee('Stroller Portal')->assertSee('Kursi Portal');
        $this->put(route('rental.cart.update', $this->product->id), ['quantity' => 2])->assertSessionHasNoErrors()->assertSessionHas('rental_cart.'.$this->product->id, 2);
        $this->put(route('rental.cart.update', $this->chair->id), ['quantity' => 0])->assertSessionHasNoErrors()->assertSessionMissing('rental_cart.'.$this->chair->id);
        $this->withSession(['rental_cart' => []])->put(route('rental.cart.update', $this->product->id), ['quantity' => 1])->assertNotFound();
    }

    public function test_cart_limits_and_inactive_product_are_checked_without_losing_old_selection(): void
    {
        $this->post(route('rental.cart.add'), ['product_id' => $this->product->id, 'quantity' => 20])->assertSessionHasNoErrors();
        $this->post(route('rental.cart.add'), ['product_id' => $this->chair->id, 'quantity' => 1])->assertSessionHasErrors('cart')->assertSessionHas('rental_cart.'.$this->product->id, 20);
        $this->product->update(['is_active' => false]);
        $this->get(route('rental.cart.index'))->assertOk()->assertSee('Hapus dari keranjang')->assertDontSee('data-rental-checkout', false);
        $this->post(route('rental.cart.add'), ['product_id' => $this->product->id, 'quantity' => 1])->assertNotFound();
    }

    public function test_multi_product_checkout_is_one_order_and_clears_cart_only_after_success(): void
    {
        $this->withSession(['rental_cart' => [$this->product->id => 1, $this->chair->id => 1]]);
        $order = $this->order(['items' => [['product_id' => $this->product->id, 'quantity' => 1], ['product_id' => $this->chair->id, 'quantity' => 1]]], true);
        $this->assertSame(2, $order->items()->count());
        $this->assertEquals(310000, $order->totalDue());
        $this->assertEmpty(session('rental_cart'));
        $this->get(route('rental.receipt', $order->code))->assertOk()->assertSee('Portal pribadi pesanan');
        $this->get(route('rental.portal.show', $order->code))->assertOk()->assertSee('Penyewa Portal Sendiri')->assertDontSee('Nama Pelanggan Internal');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_stale_stock_rolls_back_all_products_and_preserves_cart(): void
    {
        $data = $this->data(['items' => [['product_id' => $this->product->id, 'quantity' => 1], ['product_id' => $this->chair->id, 'quantity' => 1]], 'name' => 'Tidak Dibuat', 'whatsapp_number' => '081222222222', 'terms_accepted' => 1]);
        $data['quote_token'] = $this->postJson(route('rental.quote'), $data)->assertOk()->json('token');
        $this->order();
        $this->withSession(['rental_cart' => [$this->product->id => 1, $this->chair->id => 1]])->post(route('rental.store'), $data)->assertSessionHasErrors('rental')->assertSessionHas('rental_cart.'.$this->chair->id, 1);
        $this->assertDatabaseMissing('customers', ['name' => 'Tidak Dibuat']);
        $this->assertSame(0, $this->chair->units()->first()->items()->count());
    }

    public function test_portal_link_grants_only_one_order_and_token_is_hashed_redirected_and_revocable(): void
    {
        $order = $this->order();
        $this->get(route('rental.portal.show', $order->code))->assertNotFound();
        $link = $this->openPortal($order);
        $order->refresh();
        $this->assertSame(hash('sha256', basename($link)), $order->portal_token_hash);
        $this->assertArrayNotHasKey('portal_token_hash', $order->toArray());
        $this->get(route('rental.portal.show', $order->code))->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private')->assertSee($this->customer->name);
        $other = $this->order(['items' => [['product_id' => $this->chair->id, 'quantity' => 1]]]);
        $this->get(route('rental.portal.show', $other->code))->assertNotFound();
        $this->post(route('admin.rentals.portal.issue', $order))->assertSessionHasNoErrors();
        $this->get($link)->assertNotFound();
        $this->get(route('rental.portal.show', $order->code))->assertNotFound();
    }

    public function test_portal_link_expires_and_session_receipt_does_not_bypass_it(): void
    {
        $order = $this->order([], true);
        $order->update(['portal_expires_at' => now()->subMinute()]);
        $this->get(route('rental.portal.show', $order->code))->assertNotFound();
        $this->get(route('rental.receipt', $order->code))->assertOk()->assertDontSee('Tautan pribadi pesanan');
        $this->get(route('rental.portal.access', ['token' => str_repeat('a', 64)]))->assertNotFound();
        $this->get(route('rental.portal.access', ['token' => 'invalid']))->assertNotFound();
    }

    public function test_payment_policy_uses_service_charges_excludes_deposit_and_is_snapshotted(): void
    {
        $order = $this->confirmed();
        $this->assertSame(37500, $order->payment_terms['minimum_dp']);
        $this->assertSame('2026-10-28 23:59:59', $order->dp_due_at->format('Y-m-d H:i:s'));
        $this->configure(['dp_value' => 90, 'payment_instructions' => 'Instruksi BARU']);
        $this->assertSame(37500, $order->fresh()->payment_terms['minimum_dp']);
        $this->assertStringNotContainsString('BARU', $order->payment_terms['payment_instructions']);
        $terms = app(RentalPaymentTerms::class);
        $this->assertSame(2, $terms->snapshot(['dp_type' => 'percent', 'dp_value' => 50], 3)['minimum_dp']);
        $this->assertSame(100, $terms->snapshot(['dp_type' => 'fixed', 'dp_value' => 999], 100)['minimum_dp']);
    }

    public function test_dp_deadline_remains_after_confirmation_when_settlement_is_later(): void
    {
        $order = $this->confirmed(['start_date' => '2026-11-02', 'end_date' => '2026-11-03']);
        $this->assertSame('2026-10-29 09:00:00', $order->dp_due_at->format('Y-m-d H:i:s'));
        $this->assertTrue($order->dp_due_at->lte($order->balance_due_at));
    }

    public function test_invalid_dp_settings_rejected_and_changed_policy_invalidates_quote(): void
    {
        $settings = app(RentalSettings::class)->get();
        $this->put(route('admin.rentals.settings.update'), array_replace($settings, ['dp_type' => 'percent', 'dp_value' => 101]))->assertSessionHasErrors('dp_value');
        $data = $this->data();
        $data['quote_token'] = $this->postJson(route('rental.quote'), $data)->json('token');
        $this->configure();
        $this->post(route('admin.rentals.orders.store'), $data)->assertSessionHasErrors('rental');
    }

    public function test_pending_proof_changes_no_money_and_approval_records_once_with_named_receipt(): void
    {
        $order = $this->confirmed();
        $proof = $this->submitProof($order);
        $this->assertSame(0, $order->payments()->count());
        $this->assertEquals(175000, $order->fresh()->balance());
        $this->get(route('admin.rentals.payment-reviews'))->assertOk()->assertSee('Ibu Pembayar');
        $this->review($order, $proof)->assertSessionHasNoErrors();
        $this->review($order, $proof)->assertSessionHasNoErrors();
        $this->assertSame(1, $order->payments()->count());
        $this->assertEquals(137500, $order->fresh()->balance());
        $this->assertSame('Ibu Pembayar', $order->payments()->first()->party_name);
        $receipt = $order->documents()->sole();
        $this->assertSame('Ibu Pembayar', $receipt->snapshot['receipt']['party_name']);
        $this->assertSame('approved', $proof->fresh()->status);
        $this->review($order, $proof, 'reject')->assertSessionHasErrors('payment');
    }

    public function test_rejection_keeps_balance_and_customer_can_submit_replacement(): void
    {
        $order = $this->confirmed();
        $proof = $this->submitProof($order);
        $this->review($order, $proof, 'reject')->assertSessionHasNoErrors();
        $this->assertSame(0, $order->payments()->count());
        $this->get(route('rental.portal.show', $order->code))->assertOk()->assertSee('Ditolak');
        $this->post(route('rental.portal.proof', $order->code), $this->proofData(['proof' => UploadedFile::fake()->image('replacement.png')]))->assertSessionHasNoErrors();
        $this->assertSame(2, $order->paymentProofs()->count());
    }

    public function test_duplicate_upload_and_pending_parallel_submission_do_not_create_orphan_files(): void
    {
        $order = $this->confirmed();
        $this->openPortal($order);
        $data = $this->proofData();
        $this->post(route('rental.portal.proof', $order->code), $data)->assertSessionHasNoErrors();
        $this->post(route('rental.portal.proof', $order->code), $data)->assertSessionHasNoErrors();
        $this->post(route('rental.portal.proof', $order->code), $this->proofData())->assertSessionHasErrors('payment');
        $this->assertSame(1, $order->paymentProofs()->count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_balance_changed_by_manual_payment_prevents_overcredit_on_approval(): void
    {
        $order = $this->confirmed();
        $proof = $this->submitProof($order);
        $this->post(route('admin.rentals.orders.payment', $order), ['entry_key' => (string) Str::uuid(), 'kind' => 'payment', 'amount' => 175000, 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->review($order, $proof)->assertSessionHasErrors('payment');
        $this->assertSame('pending', $proof->fresh()->status);
        $this->assertSame(1, $order->payments()->count());
    }

    public function test_dp_then_full_settlement_unlocks_handover_only_after_both_approved(): void
    {
        $order = $this->confirmed();
        $this->post(route('admin.rentals.orders.action', $order), ['action' => 'dispatch'])->assertSessionHasErrors('rental');
        $proof = $this->submitProof($order);
        $this->review($order, $proof)->assertSessionHasNoErrors();
        $this->post(route('admin.rentals.orders.action', $order), ['action' => 'dispatch'])->assertSessionHasErrors('rental');
        $this->post(route('rental.portal.proof', $order->code), $this->proofData(['kind' => 'settlement', 'amount' => 137500, 'proof' => UploadedFile::fake()->image('settlement.png')]))->assertSessionHasNoErrors();
        $this->review($order, $order->paymentProofs()->latest('id')->first())->assertSessionHasNoErrors();
        $this->post(route('admin.rentals.orders.action', $order), ['action' => 'dispatch'])->assertSessionHasNoErrors();
        $this->assertSame('rented', $order->fresh()->status);
        $this->assertSame(0, (int) $order->balance());
    }

    public function test_unconfirmed_cancelled_invalid_amounts_and_untrusted_files_are_rejected(): void
    {
        $this->configure();
        $order = $this->order();
        $this->openPortal($order);
        $url = route('rental.portal.proof', $order->code);
        $this->post($url, $this->proofData())->assertSessionHasErrors('payment');
        $this->post(route('admin.rentals.orders.action', $order), ['action' => 'confirm'])->assertSessionHasNoErrors();
        foreach ([['amount' => 1], ['kind' => 'settlement', 'amount' => 37500], ['amount' => 999999], ['proof' => UploadedFile::fake()->create('bad.svg', 1, 'image/svg+xml')], ['proof' => UploadedFile::fake()->create('large.pdf', 5121, 'application/pdf')], ['payment_date' => '2026-10-29']] as $changes) {
            $this->post($url, $this->proofData($changes))->assertSessionHasErrors();
        }
        $this->post(route('admin.rentals.orders.action', $order), ['action' => 'cancel'])->assertSessionHasNoErrors();
        $this->post($url, $this->proofData())->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('rental_payment_proofs', 0);
    }

    public function test_proof_file_routes_are_scoped_and_admin_roles_are_required(): void
    {
        $order = $this->confirmed();
        $proof = $this->submitProof($order);
        $this->get(route('rental.portal.proof-file', [$order->code, $proof]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $other = $this->order(['items' => [['product_id' => $this->chair->id, 'quantity' => 1]]]);
        $this->openPortal($other);
        $this->get(route('rental.portal.proof-file', [$other->code, $proof]))->assertNotFound();
        $this->get(route('admin.rentals.proofs.file', [$other, $proof]))->assertNotFound();
        foreach (['sales', 'therapist'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'active']));
            $this->get(route('admin.rentals.payment-reviews'))->assertForbidden();
            $this->get(route('admin.rentals.proofs.file', [$order, $proof]))->assertForbidden();
            $this->post(route('admin.rentals.portal.issue', $order))->assertForbidden();
            $this->review($order, $proof)->assertForbidden();
        }
    }

    public function test_private_customer_documents_exclude_admin_notes_and_other_orders(): void
    {
        $order = $this->confirmed();
        $order->update(['notes' => 'INTERNAL-SECRET-COMMENT']);
        $doc = app(RentalDocumentService::class)->issueInvoice($order, $this->admin);
        $this->openPortal($order);
        $this->get(route('rental.portal.document', [$order->code, $doc]))->assertOk()->assertSee('Cetak / Simpan PDF')->assertDontSee('INTERNAL-SECRET-COMMENT')->assertDontSee('/admin/rentals');
        $other = $this->order(['items' => [['product_id' => $this->chair->id, 'quantity' => 1]]]);
        $this->openPortal($other);
        $this->get(route('rental.portal.document', [$other->code, $doc]))->assertNotFound();
        $this->withSession(['rental_portal_access' => []])->get(route('rental.portal.document', [$order->code, $doc]))->assertNotFound();
    }

    public function test_customer_extension_is_only_request_until_admin_approves_once(): void
    {
        $order = $this->confirmed();
        $this->openPortal($order);
        $preview = $this->postJson(route('rental.portal.extension-preview', $order->code), ['new_end_date' => '2026-11-01'])->assertOk()->json();
        $data = ['entry_key' => (string) Str::uuid(), 'new_end_date' => '2026-11-01', 'extension_token' => $preview['token'], 'notes' => 'Mohon tambah dua hari, setuju estimasi'];
        $url = route('rental.portal.extension', $order->code);
        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->assertSame('2026-10-30', $order->fresh()->end_date->toDateString());
        $entry = $order->extensionRequests()->sole();
        $this->assertSame(50000, $entry->quoted_amount);
        $review = route('admin.rentals.extension-requests.review', [$order, $entry]);
        $this->post($review, ['action' => 'approve', 'review_notes' => 'Disetujui sesuai estimasi pelanggan'])->assertSessionHasNoErrors();
        $this->post($review, ['action' => 'approve', 'review_notes' => 'Pengiriman ulang formulir'])->assertSessionHasNoErrors();
        $this->assertSame('2026-11-01', $order->fresh()->end_date->toDateString());
        $this->assertSame(1, $order->extensions()->count());
        $this->assertSame('approved', $entry->fresh()->status);
    }

    public function test_extension_approval_rechecks_stock_and_forged_quote_is_rejected(): void
    {
        $order = $this->confirmed();
        $this->openPortal($order);
        $url = route('rental.portal.extension', $order->code);
        $this->post($url, ['entry_key' => (string) Str::uuid(), 'new_end_date' => '2026-11-01', 'extension_token' => 'forged', 'notes' => 'Coba token palsu'])->assertSessionHasErrors('extension');
        $preview = $this->postJson(route('rental.portal.extension-preview', $order->code), ['new_end_date' => '2026-11-01'])->assertOk()->json();
        $this->post($url, ['entry_key' => (string) Str::uuid(), 'new_end_date' => '2026-11-01', 'extension_token' => $preview['token'], 'notes' => 'Mohon tambah dua hari'])->assertSessionHasNoErrors();
        $this->order(['start_date' => '2026-11-01', 'end_date' => '2026-11-02']);
        $entry = $order->extensionRequests()->sole();
        $review = route('admin.rentals.extension-requests.review', [$order, $entry]);
        $this->post($review, ['action' => 'approve', 'review_notes' => 'Cek ulang stok terbaru'])->assertSessionHasErrors('extension');
        $this->post($review, ['action' => 'reject', 'review_notes' => 'Maaf unit sudah dipesan penyewa lain'])->assertSessionHasNoErrors();
        $this->assertSame('2026-10-30', $order->fresh()->end_date->toDateString());
    }

    public function test_payment_due_and_pending_proof_create_alerts_without_auto_cancellation(): void
    {
        $order = $this->confirmed();
        $this->submitProof($order);
        $this->assertTrue(app(RentalOperations::class)->alerts($order)->contains('type', 'Bukti transfer menunggu verifikasi'));
        $this->travelTo(Carbon::parse('2026-10-30 10:00:00', 'Asia/Jakarta'));
        $this->assertTrue(app(RentalOperations::class)->alerts($order)->contains('type', 'Tenggat pembayaran terlewati'));
        $this->assertSame('reserved', $order->fresh()->status);
        $this->assertSame('pending', $order->paymentProofs()->sole()->status);
    }

    public function test_anonymous_token_holder_can_use_portal_and_cannot_inject_review_fields(): void
    {
        $order = $this->confirmed();
        $link = $this->openPortal($order);
        auth()->logout();
        $this->withSession(['rental_portal_access' => []])->get($link)->assertRedirect();
        $this->get(route('rental.portal.show', $order->code))->assertOk();
        $this->post(route('rental.portal.proof', $order->code), $this->proofData(['status' => 'approved', 'reviewed_by' => $this->admin->id, 'file_path' => 'forged']))->assertSessionHasNoErrors();
        $proof = $order->paymentProofs()->sole();
        $this->assertSame('pending', $proof->status);
        $this->assertNull($proof->reviewed_by);
        $this->assertStringStartsWith('rentals/payment-proofs/', $proof->file_path);
        $this->assertSame(0, $order->payments()->count());
        $this->post(route('admin.rentals.proofs.review', [$order, $proof]), ['action' => 'approve', 'review_notes' => 'Tidak berwenang'])->assertRedirect(route('login'));
    }

    public function test_public_booking_documents_use_submitted_identity_without_disclosing_other_crm_details(): void
    {
        $this->configure();
        $order = $this->order([], true);
        $this->post(route('admin.rentals.orders.action', $order), ['action' => 'confirm'])->assertSessionHasNoErrors();
        $doc = app(RentalDocumentService::class)->issueInvoice($order, $this->admin);
        $this->get(route('rental.portal.document', [$order->code, $doc]))->assertOk()->assertSee('Penyewa Portal Sendiri')->assertDontSee('Nama Pelanggan Internal');
    }

    public function test_pdf_proofs_download_privately_and_approved_image_cannot_be_reused(): void
    {
        $order = $this->confirmed();
        $this->openPortal($order);
        $data = $this->proofData(['proof' => UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF")]);
        $this->post(route('rental.portal.proof', $order->code), $data)->assertSessionHasNoErrors();
        $proof = $order->paymentProofs()->sole();
        $this->get(route('rental.portal.proof-file', [$order->code, $proof]))->assertOk()->assertDownload('bukti-'.$proof->id.'.pdf');
        $this->review($order, $proof)->assertSessionHasNoErrors();
        $data['entry_key'] = (string) Str::uuid();
        $data['kind'] = 'settlement';
        $data['amount'] = 137500;
        $this->post(route('rental.portal.proof', $order->code), $data)->assertSessionHasErrors('payment');
        $this->assertSame(1, $order->paymentProofs()->count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }
}
