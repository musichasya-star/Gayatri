<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\RentalDocument;
use App\Models\RentalExtension;
use App\Models\RentalOrder;
use App\Models\RentalPayment;
use App\Models\RentalProduct;
use App\Models\RentalUnit;
use App\Models\User;
use App\Services\CRM\RentalDocumentService;
use App\Services\CRM\RentalSettings;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class RentalDocumentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private RentalProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-27 09:30:00', 'Asia/Jakarta'));
        Cache::flush();
        Queue::fake();
        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'name' => 'Admin Dokumen']);
        $this->actingAs($this->admin);
        $this->customer = Customer::create(['name' => 'Penyewa Dokumen', 'phone' => '081234567890', 'whatsapp_number' => '6281234567890', 'status' => 'lead', 'baby_name' => 'Tidak boleh ada di dokumen', 'notes' => 'Catatan privat pelanggan']);
        $this->product = RentalProduct::create(['sku' => 'DOC-ST', 'name' => 'Stroller Dokumen', 'category' => 'Stroller', 'daily_rate' => 25000, 'deposit' => 100000, 'turnaround_days' => 1, 'is_active' => true]);
        app(RentalSettings::class)->save(array_replace(app(RentalSettings::class)->get(), ['office_name' => 'Gayatri Rental', 'office_address' => "Jalan Melati 10\nKediri"]));
    }

    private function order(array $overrides = []): RentalOrder
    {
        $order = RentalOrder::create(array_replace([
            'code' => 'RENT-DOC-'.Str::upper(Str::random(8)), 'quote_id' => (string) Str::uuid(), 'request_session_hash' => str_repeat('a', 64),
            'customer_id' => $this->customer->id, 'created_by' => $this->admin->id, 'source' => 'admin', 'status' => 'reserved',
            'start_date' => '2026-10-27', 'end_date' => '2026-10-29', 'period' => 'day', 'period_count' => 3,
            'fulfilment' => 'both', 'address' => 'Jalan Penyewa 12', 'rental_subtotal' => 75000, 'deposit_total' => 100000, 'delivery_fee' => 15000, 'collection_fee' => 10000,
            'pricing_snapshot' => ['rental_subtotal' => 75000, 'deposit_total' => 100000, 'total' => 200000, 'rows' => [['product_id' => $this->product->id, 'name' => $this->product->name, 'rate' => 25000, 'unit_price' => 75000, 'unit_deposit' => 100000, 'quantity' => 1]]],
        ], $overrides));
        $unit = RentalUnit::create(['rental_product_id' => $this->product->id, 'code' => 'DOC-'.Str::upper(Str::random(10)), 'status' => 'ready']);
        $order->items()->create(['rental_unit_id' => $unit->id, 'product_name' => $this->product->name, 'unit_code' => $unit->code, 'rental_price' => $order->rental_subtotal, 'deposit' => 100000, 'rental_end_date' => $order->end_date, 'reserved_until' => '2026-10-31']);

        return $order;
    }

    private function payment(RentalOrder $order, float $amount = 60000, string $kind = 'payment'): RentalPayment
    {
        return $order->payments()->create(['entry_key' => (string) Str::uuid(), 'kind' => $kind, 'amount' => $amount, 'method' => 'transfer', 'reference' => 'BANK-'.Str::upper(Str::random(6)), 'notes' => 'Pencatatan uji', 'created_by' => $this->admin->id]);
    }

    private function service(): RentalDocumentService
    {
        return app(RentalDocumentService::class);
    }

    private function showUrl(RentalDocument $document): string
    {
        return route('admin.rentals.documents.show', ['order' => $document->rental_order_id, 'document' => $document->id]);
    }

    public function test_archive_uses_admin_navigation_while_issued_document_keeps_print_layout(): void
    {
        $order = $this->order();
        $document = $this->service()->issueInvoice($order, $this->admin);
        $snapshot = $document->refresh()->getRawOriginal();
        $this->get(route('admin.rentals.documents.index'))->assertOk()->assertSee('id="admin-sidebar"', false)
            ->assertSee('class="table-scroll"', false)->assertSee('role="region"', false)->assertSee($document->number);
        $this->get($this->showUrl($document))->assertOk()->assertSee('class="rd-page"', false)->assertDontSee('id="admin-sidebar"', false);
        $this->assertSame($snapshot, $document->fresh()->getRawOriginal());
    }

    public function test_invoice_preserves_partial_payment_fees_and_refundable_deposit_separately(): void
    {
        $order = $this->order();
        $order->items()->firstOrFail()->update(['late_fee' => '2000.25', 'damage_fee' => '3000.50']);
        $this->payment($order, 60000.50);
        $document = $this->service()->issueInvoice($order, $this->admin);
        $totals = $document->snapshot['totals'];

        $this->assertSame('105000.75', $totals['charges']);
        $this->assertSame('100000.00', $totals['deposit_required']);
        $this->assertSame('100000.00', $document->snapshot['original']['deposit_total']);
        $this->assertSame('205000.75', $totals['total_due']);
        $this->assertSame('60000.50', $totals['net_received']);
        $this->assertSame('145000.25', $totals['balance']);
        $this->assertSame('0.00', $totals['refundable']);
        $this->assertSame(['id', 'name', 'phone'], array_keys($document->snapshot['customer']));
        $this->assertSame('Gayatri Rental', $document->snapshot['office']['name']);
        $this->assertSame($this->admin->id, $document->issued_by);
        $this->assertTrue($document->issued_at->equalTo(now()));
        $this->assertSame(RentalDocument::fingerprintForSnapshot($document->snapshot), $document->fingerprint);
        $this->get($this->showUrl($document))->assertOk()->assertSee('Invoice ini bukan bukti pembayaran.')->assertSee('Deposit dapat dikembalikan.')->assertDontSee('Catatan privat pelanggan')->assertDontSee('Tidak boleh ada di dokumen')->assertDontSee($document->fingerprint);
    }

    public function test_completed_invoice_and_refund_receipt_follow_net_received_and_order_methods(): void
    {
        $order = $this->order(['status' => 'completed']);
        $order->items()->firstOrFail()->update(['late_fee' => 5000, 'damage_fee' => 10000]);
        $this->payment($order, 200000);
        $refund = $this->payment($order, 50000, 'refund');
        $invoice = $this->service()->issueInvoice($order, $this->admin);
        $receipt = $this->service()->issueReceipt($order, $refund, $this->admin);
        $fresh = $order->fresh(['items', 'payments']);
        $totals = $invoice->snapshot['totals'];

        foreach (['charges' => 'charges', 'total_due' => 'totalDue', 'net_received' => 'received', 'balance' => 'balance', 'refundable' => 'refundable'] as $field => $method) {
            $this->assertSame(number_format($fresh->$method(), 2, '.', ''), $totals[$field]);
        }
        $this->assertSame('115000.00', $totals['total_due']);
        $this->assertSame('0.00', $totals['deposit_required']);
        $this->assertSame('100000.00', $totals['deposit_original']);
        $this->assertSame('200000.00', $totals['payments']);
        $this->assertSame('50000.00', $totals['refunds']);
        $this->assertSame('150000.00', $totals['net_received']);
        $this->assertSame('35000.00', $totals['refundable']);
        $this->assertSame(RentalDocument::TYPE_REFUND_RECEIPT, $receipt->type);
        $this->assertStringStartsWith('RFD-', $receipt->number);
        $this->assertSame('50000.00', $receipt->snapshot['receipt']['amount']);
        $this->get($this->showUrl($receipt))->assertOk()->assertSee('NOMINAL DANA DIKEMBALIKAN')->assertSee('Penerima')->assertSee($refund->reference);
    }

    public function test_cancelled_invoice_has_zero_charges_and_preserves_historical_prices_and_refunds(): void
    {
        $order = $this->order(['status' => 'cancelled', 'notes' => 'Pembatalan: jadwal berubah']);
        $order->items()->firstOrFail()->update(['late_fee' => 5000, 'damage_fee' => 10000]);
        $this->payment($order, 200000);
        $this->payment($order, 30000, 'refund');
        $document = $this->service()->issueInvoice($order, $this->admin);

        foreach (['rental_subtotal', 'delivery_fee', 'collection_fee', 'late_fee', 'damage_fee', 'charges', 'deposit_required', 'total_due', 'balance'] as $field) {
            $this->assertSame('0.00', $document->snapshot['totals'][$field], $field);
        }
        $this->assertSame('170000.00', $document->snapshot['totals']['refundable']);
        $this->assertSame('75000.00', $document->snapshot['items'][0]['rental_price']);
        $this->assertSame('100000.00', $document->snapshot['original']['deposit_total']);
        $this->get($this->showUrl($document))->assertOk()->assertSee('Pesanan dibatalkan')->assertSee('Tagihan Rp 0,00')->assertSee('Pembatalan: jadwal berubah');
    }

    public function test_extension_charge_is_already_included_once_in_item_and_order_price(): void
    {
        $order = $this->order(['status' => 'rented']);
        $order->update(['rental_subtotal' => 125000, 'end_date' => '2026-10-31', 'period_count' => 5]);
        $order->items()->firstOrFail()->update(['rental_price' => 125000, 'rental_end_date' => '2026-10-31']);
        RentalExtension::create(['rental_order_id' => $order->id, 'created_by' => $this->admin->id, 'entry_key' => (string) Str::uuid(), 'old_end_date' => '2026-10-29', 'new_end_date' => '2026-10-31', 'schedule_version' => 2, 'additional_charge' => 50000, 'pricing_snapshot' => [], 'notes' => 'Dua hari tambahan']);
        $document = $this->service()->issueInvoice($order, $this->admin);

        $this->assertSame('125000.00', $document->snapshot['items'][0]['rental_price']);
        $this->assertSame('125000.00', $document->snapshot['totals']['rental_subtotal']);
        $this->assertSame('50000.00', $document->snapshot['totals']['extension_included']);
        $this->assertSame('150000.00', $document->snapshot['totals']['charges']);
        $this->assertSame('250000.00', $document->snapshot['totals']['total_due']);
        $this->assertSame('75000.00', $document->snapshot['original']['rental_subtotal']);
        $this->get($this->showUrl($document))->assertOk()->assertSee('nominal berikut tidak ditambahkan lagi')->assertSee('Dua hari tambahan');
    }

    public function test_pending_expired_and_unknown_states_cannot_issue_invoice(): void
    {
        foreach ([['status' => 'pending'], ['status' => 'pending', 'hold_expires_at' => now()->subMinute()], ['status' => 'expired']] as $state) {
            $this->postJson(route('admin.rentals.orders.invoice', $this->order($state)))->assertUnprocessable()->assertJsonValidationErrors('rental_document');
        }
        $this->assertDatabaseCount('rental_documents', 0);
        $this->assertSame(0, AuditLog::where('action', 'rental.document.issue')->count());
    }

    public function test_invoice_deduplicates_across_time_and_operators_and_versions_changes(): void
    {
        $order = $this->order();
        $original = $this->service()->issueInvoice($order, $this->admin);
        $this->travel(1)->days();
        $owner = User::factory()->create(['role' => 'owner', 'status' => 'active']);
        $same = $this->service()->issueInvoice($order, $owner);
        $this->assertSame($original->id, $same->id);
        $this->assertSame($original->snapshot, $same->snapshot);
        $this->assertSame($this->admin->id, $same->issued_by);
        $this->assertTrue($same->issued_at->equalTo($original->issued_at));

        $order->update(['delivery_fee' => 16000]);
        $second = $this->service()->issueInvoice($order, $owner);
        $this->assertSame(2, $second->version);
        $this->assertStringEndsWith('-V2', $second->number);
        $this->assertNotSame($original->fingerprint, $second->fingerprint);
        $order->update(['delivery_fee' => 15000]);
        $this->assertSame($original->id, $this->service()->issueInvoice($order, $owner)->id);
        $order->update(['delivery_fee' => 17000]);
        $third = $this->service()->issueInvoice($order, $owner);
        $this->assertSame(3, $third->version);
        $this->assertSame('15000.00', $original->fresh()->snapshot['totals']['delivery_fee']);
        $this->assertDatabaseCount('rental_documents', 3);
        $this->assertSame(3, AuditLog::where('action', 'rental.document.issue')->count());
    }

    public function test_new_payments_identity_or_office_data_reissue_invoice_but_catalogue_does_not(): void
    {
        $order = $this->order();
        $original = $this->service()->issueInvoice($order, $this->admin);
        $this->product->update(['name' => 'Nama katalog baru', 'daily_rate' => 99000, 'deposit' => 999999]);
        $this->assertSame($original->id, $this->service()->issueInvoice($order, $this->admin)->id);
        $this->payment($order, 20000);
        $this->assertSame(2, $this->service()->issueInvoice($order, $this->admin)->version);
        $this->customer->update(['name' => 'Identitas diperbarui']);
        $this->assertSame(3, $this->service()->issueInvoice($order, $this->admin)->version);
        app(RentalSettings::class)->save(array_replace(app(RentalSettings::class)->get(), ['office_address' => 'Alamat kantor baru']));
        $this->assertSame(4, $this->service()->issueInvoice($order, $this->admin)->version);
        $this->assertSame('Penyewa Dokumen', $original->fresh()->snapshot['customer']['name']);
        $this->get($this->showUrl($original))->assertOk()->assertSee('Stroller Dokumen')->assertSee('Jalan Melati 10')->assertDontSee('Nama katalog baru')->assertDontSee('Identitas diperbarui')->assertDontSee('Alamat kantor baru');
    }

    public function test_receipt_uses_one_recorded_entry_and_duplicate_keeps_original_data_forever(): void
    {
        $order = $this->order();
        $payment = $this->payment($order, 40000);
        $this->post(route('admin.rentals.orders.receipt', [$order, $payment]), ['amount' => 999999, 'method' => 'cash', 'payer' => 'Forged payer'])->assertRedirect()->assertSessionHasNoErrors();
        $original = RentalDocument::sole();
        $this->assertSame(RentalDocument::TYPE_PAYMENT_RECEIPT, $original->type);
        $this->assertStringStartsWith('KWT-', $original->number);
        $this->assertSame('40000.00', $original->snapshot['receipt']['amount']);
        $this->assertSame('transfer', $original->snapshot['receipt']['method']);
        $this->assertSame('Penyewa Dokumen', $original->snapshot['receipt']['party_name']);
        $this->assertSame('160000.00', $original->snapshot['totals']['balance']);

        $this->travel(2)->days();
        $this->customer->update(['name' => 'Nama berubah', 'phone' => '0800000000']);
        $this->product->update(['name' => 'Katalog berubah', 'daily_rate' => 99999]);
        app(RentalSettings::class)->save(array_replace(app(RentalSettings::class)->get(), ['office_name' => 'Kantor berubah']));
        $order->update(['delivery_fee' => 99000]);
        $secondPayment = $this->payment($order, 10000);
        $same = $this->service()->issueReceipt($order, $payment, $this->admin);
        $this->assertSame($original->id, $same->id);
        $this->assertSame($original->snapshot, $same->snapshot);
        $this->assertSame($original->fingerprint, $same->fingerprint);
        $this->assertSame(1, AuditLog::where('action', 'rental.document.issue')->count());
        $this->assertNotSame($original->id, $this->service()->issueReceipt($order, $secondPayment, $this->admin)->id);
        $this->get($this->showUrl($original))->assertOk()->assertSee('NOMINAL PEMBAYARAN DITERIMA')->assertSee($payment->reference)->assertSee('Gayatri Rental')->assertDontSee('Nama berubah')->assertDontSee('Kantor berubah')->assertDontSee('Forged payer');
    }

    public function test_issued_documents_reject_model_quiet_and_bulk_changes_and_deletes(): void
    {
        $document = $this->service()->issueInvoice($this->order(), $this->admin);
        $original = $document->fresh()->getAttributes();
        $writes = [
            fn () => $document->fresh()->update(['number' => 'CHANGED']),
            fn () => $document->fresh()->forceFill(['snapshot' => []])->saveQuietly(),
            fn () => $document->fresh()->delete(),
            fn () => $document->fresh()->deleteQuietly(),
            fn () => RentalDocument::whereKey($document->id)->update(['number' => 'CHANGED']),
            fn () => RentalDocument::whereKey($document->id)->increment('version'),
            fn () => RentalDocument::whereKey($document->id)->delete(),
            fn () => RentalDocument::whereKey($document->id)->forceDelete(),
            fn () => RentalDocument::upsert([$original], ['id'], ['number']),
        ];
        foreach ($writes as $write) {
            try {
                $write();
                $this->fail('Issued document mutation was allowed.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('Dokumen terbit', $e->getMessage());
            }
        }
        $this->assertSame($original, $document->fresh()->getAttributes());
        $this->patch($this->showUrl($document), ['number' => 'CHANGED'])->assertStatus(405);
        $this->delete($this->showUrl($document))->assertStatus(405);
    }

    public function test_internal_fingerprint_detects_changed_payload_and_ignores_issuance_metadata(): void
    {
        $document = $this->service()->issueInvoice($this->order(), $this->admin);
        $snapshot = $document->snapshot;
        $snapshot['issuance'] = ['issued_at' => '2030-01-01T00:00:00+07:00', 'issuer_name' => 'Other'];
        $this->assertSame($document->fingerprint, RentalDocument::fingerprintForSnapshot($snapshot));
        $snapshot['totals']['total_due'] = '1.00';
        $this->assertNotSame($document->fingerprint, RentalDocument::fingerprintForSnapshot($snapshot));
        $this->expectException(InvalidArgumentException::class);
        RentalDocument::create(array_replace($document->getAttributes(), ['number' => 'FORGED', 'snapshot' => $snapshot]));
    }

    public function test_database_constraints_protect_fingerprint_version_and_payment_uniqueness(): void
    {
        $order = $this->order();
        $invoice = $this->service()->issueInvoice($order, $this->admin);
        $receipt = $this->service()->issueReceipt($order, $this->payment($order), $this->admin);
        $copies = [
            array_replace($invoice->getAttributes(), ['number' => 'DUPLICATE-HASH', 'version' => 2]),
            array_replace($invoice->getAttributes(), ['number' => 'DUPLICATE-VERSION', 'fingerprint' => str_repeat('b', 64)]),
            array_replace($receipt->getAttributes(), ['number' => 'DUPLICATE-PAYMENT', 'fingerprint' => str_repeat('c', 64)]),
        ];
        foreach ($copies as $copy) {
            unset($copy['id']);
            try {
                DB::transaction(fn () => DB::table('rental_documents')->insert($copy));
                $this->fail('A duplicate document bypassed the database constraint.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('unique', strtolower($e->getMessage()));
            }
        }
        $this->assertDatabaseCount('rental_documents', 2);
    }

    public function test_customer_office_product_and_ledger_text_is_escaped_and_assets_are_local(): void
    {
        $attack = '<script>alert("rental")</script>';
        $this->customer->update(['name' => $attack]);
        app(RentalSettings::class)->save(array_replace(app(RentalSettings::class)->get(), ['office_name' => $attack, 'office_address' => $attack]));
        $order = $this->order(['notes' => $attack, 'address' => $attack]);
        $order->items()->firstOrFail()->update(['product_name' => $attack, 'unit_code' => '<img src=x onerror=alert(1)>']);
        $payment = $this->payment($order);
        $payment->update(['reference' => $attack, 'notes' => $attack]);
        foreach ([$this->service()->issueInvoice($order, $this->admin), $this->service()->issueReceipt($order, $payment, $this->admin)] as $document) {
            $response = $this->get($this->showUrl($document))->assertOk()->assertSee(e($attack), false)->assertDontSee($attack, false)->assertDontSee('<img src=x', false)->assertSee('window.print()', false)->assertSee('css/rental-documents.css', false);
            $this->assertDoesNotMatchRegularExpression('/(?:src|href)=["\']https?:\/\/(?:fonts\.|unpkg\.|cdn\.)/i', $response->getContent());
        }
    }

    public function test_cross_order_receipts_and_documents_are_not_accessible(): void
    {
        $first = $this->order();
        $other = $this->order();
        $payment = $this->payment($first);
        $document = $this->service()->issueReceipt($first, $payment, $this->admin);
        $this->post(route('admin.rentals.orders.receipt', [$other, $payment]))->assertNotFound();
        $this->get(route('admin.rentals.documents.show', [$other, $document]))->assertNotFound();
        $this->assertDatabaseCount('rental_documents', 1);
        $this->expectException(ModelNotFoundException::class);
        $this->service()->issueReceipt($other, $payment, $this->admin);
    }

    public function test_document_routes_require_active_owner_manager_or_admin(): void
    {
        $order = $this->order();
        $payment = $this->payment($order);
        $document = $this->service()->issueInvoice($order, $this->admin);
        foreach (['owner', 'manager', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'active']));
            $this->get(route('admin.rentals.documents.index'))->assertOk();
            $this->get($this->showUrl($document))->assertOk();
            $this->post(route('admin.rentals.orders.invoice', $order))->assertRedirect($this->showUrl($document));
            $this->post(route('admin.rentals.orders.receipt', [$order, $payment]))->assertRedirect();
        }
        foreach (['sales', 'therapist'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'active']));
            $this->get(route('admin.rentals.documents.index'))->assertForbidden();
            $this->get($this->showUrl($document))->assertForbidden();
            $this->post(route('admin.rentals.orders.invoice', $order))->assertForbidden();
            $this->post(route('admin.rentals.orders.receipt', [$order, $payment]))->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'inactive']));
        $this->get($this->showUrl($document))->assertRedirect(route('login'));
        $this->get(route('admin.rentals.documents.index'))->assertRedirect(route('login'));
        $this->post(route('admin.rentals.orders.invoice', $order))->assertRedirect(route('login'));
        $this->post(route('admin.rentals.orders.receipt', [$order, $payment]))->assertRedirect(route('login'));
    }

    public function test_direct_service_call_also_rejects_unauthorized_issuance(): void
    {
        $order = $this->order();
        $payment = $this->payment($order);
        foreach ([['role' => 'sales', 'status' => 'active'], ['role' => 'admin', 'status' => 'inactive']] as $attributes) {
            $user = User::factory()->create($attributes);
            foreach ([fn () => $this->service()->issueInvoice($order, $user), fn () => $this->service()->issueReceipt($order, $payment, $user)] as $issue) {
                try {
                    $issue();
                    $this->fail('Unauthorized issuance was allowed.');
                } catch (AuthorizationException) {
                    $this->assertDatabaseCount('rental_documents', 0);
                }
            }
        }
    }

    public function test_issuance_audits_new_documents_only_without_customer_snapshot_or_financial_changes(): void
    {
        $order = $this->order();
        $payment = $this->payment($order);
        $before = [$order->fresh()->getAttributes(), $order->items()->get()->toArray(), $order->payments()->get()->toArray()];
        $this->post(route('admin.rentals.orders.invoice', $order))->assertRedirect();
        $invoice = RentalDocument::sole();
        $this->post(route('admin.rentals.orders.invoice', $order))->assertRedirect($this->showUrl($invoice));
        $this->post(route('admin.rentals.orders.receipt', [$order, $payment]))->assertRedirect();
        $this->post(route('admin.rentals.orders.receipt', [$order, $payment]))->assertRedirect();
        $audits = AuditLog::where('action', 'rental.document.issue')->get();
        $this->assertCount(2, $audits);
        $this->assertSame($invoice->number, $audits->first()->new_values['number']);
        $this->assertSame($order->id, $audits->first()->new_values['rental_order_id']);
        $this->assertSame(RentalDocument::class, $audits->first()->auditable_type);
        $this->assertSame(['number', 'type', 'rental_order_id', 'order_code', 'rental_payment_id', 'version'], array_keys($audits->first()->new_values));
        $this->assertSame($before, [$order->fresh()->getAttributes(), $order->items()->get()->toArray(), $order->payments()->get()->toArray()]);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_document_index_and_order_partial_paginate_and_filter_without_loaded_relations(): void
    {
        $order = $this->order();
        $first = null;
        for ($i = 0; $i < 21; $i++) {
            $order->update(['delivery_fee' => 15000 + $i]);
            $last = $this->service()->issueInvoice($order, $this->admin);
            $first ??= $last;
        }
        $other = $this->service()->issueInvoice($this->order(), $this->admin);
        $response = $this->get(route('admin.rentals.documents.index', ['order_id' => $order->id, 'type' => 'invoice']))->assertOk();
        $this->assertSame(21, $response->viewData('documents')->total());
        $this->assertCount(20, $response->viewData('documents')->items());
        $response->assertDontSee($other->number);
        $pageTwo = $this->get(route('admin.rentals.documents.index', ['order_id' => $order->id, 'type' => 'invoice', 'page' => 2]))->assertOk();
        $this->assertCount(1, $pageTwo->viewData('documents')->items());
        $this->get(route('admin.rentals.documents.index', ['order_code' => $order->code]))->assertOk()->assertDontSee($other->number);
        $this->get(route('admin.rentals.documents.index', ['to' => '2026-10-27']))->assertOk();
        $this->getJson(route('admin.rentals.documents.index', ['from' => '2026-10-28', 'to' => '2026-10-27']))->assertUnprocessable();
        $this->getJson(route('admin.rentals.documents.index', ['type' => 'invalid']))->assertUnprocessable();
        $html = view('admin.rentals.partials.documents', ['order' => $order->fresh()])->render();
        $this->assertStringContainsString('documents_page=2', $html);
        $this->assertStringContainsString('href="'.$this->showUrl($last).'"', $html);
        $this->assertStringNotContainsString('href="'.$this->showUrl($first).'"', $html);
    }

    public function test_document_pages_and_issuance_redirect_are_not_cacheable(): void
    {
        $order = $this->order();
        $response = $this->post(route('admin.rentals.orders.invoice', $order))->assertRedirect();
        $document = RentalDocument::sole();
        foreach ([$response, $this->get($this->showUrl($document))->assertOk(), $this->get(route('admin.rentals.documents.index'))->assertOk()] as $privateResponse) {
            $this->assertStringContainsString('no-store', $privateResponse->headers->get('Cache-Control'));
            $privateResponse->assertHeader('X-Content-Type-Options', 'nosniff');
        }
        $this->get($this->showUrl($document))->assertSee('Data saat dokumen diterbitkan')->assertSee('Data dan saldo mengikuti waktu penerbitan.')->assertDontSee('SHA-256')->assertDontSee('tidak memindahkan uang')->assertDontSee('mengirim pesan');
    }
}
