<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\LaundryDocument;
use App\Models\LaundryOrder;
use App\Models\LaundryPayment;
use App\Models\LaundryQualityCheck;
use App\Models\LaundryTariff;
use App\Models\RentalOrder;
use App\Models\TherapistFee;
use App\Models\User;
use App\Services\CRM\LaundryFinance;
use App\Services\CRM\LaundryOperations;
use App\Services\CRM\LaundryOrderService;
use App\Services\CRM\LaundrySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class LaundryFulfilmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private LaundryTariff $tariff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 30)->setTime(10, 0));
        Storage::fake('local');
        Queue::fake();
        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($this->admin);
        $this->customer = Customer::create(['name' => 'Ibu Uji Laundry', 'whatsapp_number' => '6280000016001', 'status' => 'lead']);
        app(LaundrySettings::class)->save(['accepting_orders' => true, 'outlet_name' => 'Outlet Laundry Uji', 'address' => 'Alamat Laundry Uji', 'whatsapp_number' => '6280000016000', 'opening_hours' => '08.00–17.00', 'terms' => 'Ketentuan laundry uji', 'allow_collection' => false], $this->admin, Request::create('/'));
        $this->tariff = LaundryTariff::create(['code' => 'UJI-KG', 'name' => 'Cuci Lipat Uji', 'unit' => 'kg', 'audience' => 'both', 'price' => 7500, 'minimum_milli' => 3000, 'increment_milli' => 500, 'duration_hours' => 24, 'is_active' => true]);
    }

    private function order(bool $approved = true): LaundryOrder
    {
        $service = app(LaundryOrderService::class);
        $preview = $service->preview(['entry_key' => (string) Str::uuid(), 'customer_id' => $this->customer->id, 'received_at' => '2026-10-30 08:00', 'intake_mode' => 'walkin', 'items' => [
            ['laundry_tariff_id' => $this->tariff->id, 'bag_label' => 'Baju Bayi', 'received_pieces' => 12, 'estimated_quantity' => '3.8', 'condition_notes' => 'Noda awal telah dicatat.'],
            ['laundry_tariff_id' => $this->tariff->id, 'bag_label' => 'Baju Ibu', 'received_pieces' => 5, 'estimated_quantity' => '3', 'condition_notes' => 'Lima potong lengkap.'],
        ]], $this->admin);
        $order = $service->create($preview['token'], $this->admin, Request::create('/'));
        if ($approved) {
            $service->weigh($order, $this->weighing($order), $this->admin, Request::create('/'));
            $order->refresh();
            $service->approve($order, ['revision' => $order->revision, 'approved_name' => $this->customer->name, 'approval_channel' => 'outlet', 'approval_reference' => 'Pelanggan telah setuju hasil timbang.'], $this->admin, Request::create('/'));
        }

        return $order->fresh();
    }

    private function weighing(LaundryOrder $order): array
    {
        return ['revision' => $order->fresh()->revision, 'reason' => 'Penimbangan ulang untuk uji.', 'items' => $order->items()->get()->map(fn ($item) => ['id' => $item->id, 'actual_pieces' => $item->received_pieces, 'quantity' => '2.31', 'notes' => 'Jumlah lengkap.'])->all()];
    }

    private function postAction(LaundryOrder $order, string $action, array $data = [])
    {
        return $this->from(route('admin.laundry.orders.show', $order))->post(route('admin.laundry.orders.'.$action, $order), $data + ['revision' => $order->fresh()->revision]);
    }

    private function start(LaundryOrder $order, array $steps = ['washing', 'drying', 'finishing', 'packing']): void
    {
        $this->postAction($order, 'start', ['steps' => $steps, 'notes' => 'Rencana pengerjaan uji.'])->assertSessionHasNoErrors();
        $order->refresh();
    }

    private function toQuality(LaundryOrder $order): void
    {
        if ($order->fresh()->workflow_status === 'pending') {
            $this->start($order);
        }
        while ($next = LaundryOperations::nextStage($order->fresh())) {
            $this->postAction($order, 'advance', ['next_stage' => $next, 'notes' => 'Tahap sebelumnya selesai.'])->assertSessionHasNoErrors();
        }
        $order->refresh();
        $this->assertSame('quality_check', $order->workflow_status);
    }

    private function qualityData(LaundryOrder $order): array
    {
        return ['result' => 'pass', 'notes' => 'Semua kantong sudah diperiksa.', 'items' => $order->items()->get()->map(fn ($item) => ['id' => $item->id, 'checked_pieces' => $item->actual_pieces, 'notes' => 'Kondisi dan jumlah sesuai.'] + array_fill_keys(array_keys(LaundryQualityCheck::CHECKS), 1))->all()];
    }

    private function ready(LaundryOrder $order): void
    {
        $this->toQuality($order);
        $this->postAction($order, 'quality', $this->qualityData($order))->assertSessionHasNoErrors();
        $order->refresh();
    }

    private function paymentData(LaundryOrder $order, int $amount = 15000): array
    {
        return ['revision' => $order->fresh()->revision, 'entry_key' => (string) Str::uuid(), 'amount' => $amount, 'method' => 'cash', 'paid_at' => '2026-10-30T09:30', 'payer_name' => 'Ibu Pembayar Uji', 'notes' => 'Pembayaran contoh uji.', 'confirmed' => 1];
    }

    private function pay(LaundryOrder $order, int $amount = 15000): LaundryPayment
    {
        $this->post(route('admin.laundry.payments.store', $order), $this->paymentData($order, $amount))->assertSessionHasNoErrors();
        $order->refresh();

        return $order->payments()->latest('id')->firstOrFail();
    }

    private function handoverData(LaundryOrder $order): array
    {
        return ['receiver_name' => 'Ayah Penerima Uji', 'receiver_phone' => '081234000222', 'notes' => 'Diterima lengkap oleh keluarga.', 'confirmed' => 1, 'items' => $order->items()->get()->map(fn ($item) => ['id' => $item->id, 'returned_pieces' => $item->actual_pieces, 'label_confirmed' => 1])->all()];
    }

    private function voidData(LaundryOrder $order): array
    {
        return ['revision' => $order->fresh()->revision, 'reason' => 'Koreksi pencatatan yang keliru.', 'confirmed' => 1];
    }

    public function test_complete_laundry_flow_records_dp_settlement_qc_handover_and_immutable_documents(): void
    {
        $order = $this->order();
        $this->assertSame(45000, $order->actual_total);
        $this->post(route('admin.laundry.documents.invoice', $order), ['revision' => $order->revision])->assertRedirect();
        $invoice = $order->documents()->where('type', 'invoice')->sole();
        $dp = $this->pay($order);
        $this->assertSame(30000, $order->fresh()->balance());
        $this->get(route('admin.laundry.orders.show', $order))->assertOk()->assertSee('Dibayar sebagian / DP');
        $this->ready($order);
        $this->pay($order, 30000);
        $this->postAction($order, 'handover', $this->handoverData($order))->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame('handed_over', $order->workflow_status);
        $this->assertSame('Ayah Penerima Uji', $order->receiver_name);
        $this->assertSame(0, $order->balance());
        $this->assertNotNull($order->handed_over_at);
        $this->assertSame(1, $order->qualityChecks()->count());
        $this->assertSame(2, $order->documents()->where('type', 'receipt')->count());
        $this->assertSame(0, $invoice->snapshot['paid']);
        $this->assertSame(15000, $dp->document->snapshot['paid']);
        $this->get(route('admin.laundry.orders.show', $order))->assertOk()->assertSee('Sudah diserahkan')->assertSee('Lunas')->assertSee('Ayah Penerima Uji');
        $this->get(route('admin.laundry.orders.receipt', $order))->assertOk()->assertSee('BUKTI SERAH TERIMA BARANG')->assertSee('Ayah Penerima Uji')->assertSee('12 potong')->assertSee('5 potong');
        $this->get(route('admin.laundry.documents.show', [$order, $dp->document]))->assertOk()->assertSee('KUITANSI PEMBAYARAN LAUNDRY')->assertSee('Rp 15.000')->assertSee('Rp 30.000');
        $this->get(route('admin.laundry.documents.show', [$order, $invoice]))->assertOk()->assertSee('INVOICE LAUNDRY')->assertHeader('Cache-Control', 'no-store, private');
        $event = $order->events()->where('kind', 'handed_over')->sole();
        $this->assertCount(2, $event->details['items']);
        $this->assertSame($this->admin->id, $event->actor_id);
        $this->assertSame(0, Booking::count());
        $this->assertSame(0, RentalOrder::count());
        $this->assertSame(0, TherapistFee::count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_start_requires_approval_valid_plan_and_fresh_revision(): void
    {
        $order = $this->order(false);
        $this->postAction($order, 'start', ['steps' => ['finishing', 'packing']])->assertSessionHasErrors('laundry');
        $order = $this->order();
        foreach ([['packing'], ['washing', 'packing'], ['finishing', 'unknown', 'packing']] as $steps) {
            $this->postAction($order, 'start', compact('steps'))->assertSessionHasErrors();
        }
        $this->postAction($order, 'start', ['steps' => ['finishing', 'packing'], 'revision' => 1])->assertSessionHasErrors('laundry');
        $this->start($order, ['packing', 'finishing']);
        $this->assertSame(['finishing', 'packing'], $order->process_steps);
        $this->postAction($order, 'start', ['steps' => ['finishing', 'packing']])->assertSessionHasErrors('laundry');
        $this->get(route('admin.laundry.orders.show', $order))->assertOk()->assertSee('Lanjut ke Disetrika / dilipat');
    }

    public function test_stages_cannot_be_skipped_reversed_or_repeated_with_stale_revision(): void
    {
        $order = $this->order();
        $this->start($order);
        $revision = $order->revision;
        $this->postAction($order, 'advance', ['next_stage' => 'packing'])->assertSessionHasErrors('laundry');
        $this->postAction($order, 'advance', ['next_stage' => 'washing'])->assertSessionHasNoErrors();
        $this->postAction($order, 'advance', ['next_stage' => 'drying', 'revision' => $revision])->assertSessionHasErrors('laundry');
        $this->postAction($order, 'advance', ['next_stage' => 'washing'])->assertSessionHasErrors('laundry');
        $this->assertSame('washing', $order->fresh()->workflow_status);
        $this->assertSame(1, $order->events()->where('kind', 'stage_changed')->count());
    }

    public function test_qc_rejects_wrong_stage_missing_bags_duplicate_foreign_items_and_incomplete_checklist(): void
    {
        $order = $this->order();
        $other = $this->order();
        $this->postAction($order, 'quality', $this->qualityData($order))->assertSessionHasErrors('laundry');
        $this->toQuality($order);
        $invalid = $this->qualityData($order);
        $invalid['items'][0]['clean'] = 0;
        $this->postAction($order, 'quality', $invalid)->assertSessionHasErrors('items');
        $invalid = $this->qualityData($order);
        $invalid['items'][0]['checked_pieces'] = 11;
        $this->postAction($order, 'quality', $invalid)->assertSessionHasErrors('items');
        $invalid = $this->qualityData($order);
        array_pop($invalid['items']);
        $this->postAction($order, 'quality', $invalid)->assertSessionHasErrors('items');
        $invalid = $this->qualityData($order);
        $invalid['items'][1]['id'] = $invalid['items'][0]['id'];
        $this->postAction($order, 'quality', $invalid)->assertSessionHasErrors();
        $invalid = $this->qualityData($order);
        $invalid['items'][0]['id'] = $other->items()->first()->id;
        $this->postAction($order, 'quality', $invalid)->assertSessionHasErrors('items');
        $this->assertSame(0, LaundryQualityCheck::count());
        $this->assertSame('quality_check', $order->fresh()->workflow_status);
        $this->get(route('admin.laundry.orders.show', $order))->assertOk()->assertSee('Simpan Pemeriksaan Kualitas');
    }

    public function test_rework_preserves_failed_inspection_and_repeats_remaining_steps_before_passing(): void
    {
        $order = $this->order();
        $this->toQuality($order);
        $data = $this->qualityData($order);
        $data['result'] = 'rework';
        $data['items'][0]['clean'] = 0;
        $this->postAction($order, 'quality', $data)->assertSessionHasErrors('restart_stage');
        $data['restart_stage'] = 'washing';
        $data['notes'] = 'Noda belum bersih, perlu dicuci ulang.';
        $this->postAction($order, 'quality', $data)->assertSessionHasNoErrors();
        $this->assertSame('washing', $order->fresh()->workflow_status);
        $this->assertNull($order->fresh()->ready_at);
        $this->assertFalse($order->qualityChecks()->sole()->passed);
        $this->get(route('admin.laundry.orders.show', $order))->assertOk()->assertSee('PERLU PERBAIKAN')->assertSee('Noda belum bersih');
        $this->ready($order);
        $this->assertSame(2, $order->qualityChecks()->count());
        $this->assertTrue($order->qualityChecks()->latest('id')->first()->passed);
        $this->assertSame('ready', $order->fresh()->workflow_status);
        $this->postAction($order, 'quality', $this->qualityData($order))->assertSessionHasErrors('laundry');
    }

    public function test_handover_requires_qc_full_payment_and_every_bag_with_confirmed_counts(): void
    {
        $order = $this->order();
        $this->postAction($order, 'handover', $this->handoverData($order))->assertSessionHasErrors('laundry');
        $this->ready($order);
        $this->pay($order);
        $this->postAction($order, 'handover', $this->handoverData($order))->assertSessionHasErrors('laundry');
        $this->pay($order, 30000);
        $data = $this->handoverData($order);
        unset($data['confirmed']);
        $this->postAction($order, 'handover', $data)->assertSessionHasErrors('confirmed');
        $data = $this->handoverData($order);
        $data['items'][0]['returned_pieces'] = 1;
        $this->postAction($order, 'handover', $data)->assertSessionHasErrors('items');
        $data = $this->handoverData($order);
        $data['items'][0]['label_confirmed'] = 0;
        $this->postAction($order, 'handover', $data)->assertSessionHasErrors();
        $data = $this->handoverData($order);
        array_pop($data['items']);
        $this->postAction($order, 'handover', $data)->assertSessionHasErrors('items');
        $this->assertNull($order->fresh()->handed_over_at);
        $this->assertSame(0, $order->events()->where('kind', 'handed_over')->count());
    }

    public function test_manual_payments_reject_unapproved_overpayment_decimal_missing_ack_and_invalid_dates(): void
    {
        $unapproved = $this->order(false);
        $this->post(route('admin.laundry.payments.store', $unapproved), $this->paymentData($unapproved))->assertSessionHasErrors('laundry');
        $order = $this->order();
        foreach ([0, -100, '100.5', 45001] as $amount) {
            $data = $this->paymentData($order);
            $data['amount'] = $amount;
            $this->post(route('admin.laundry.payments.store', $order), $data)->assertSessionHasErrors('amount');
        }
        foreach (['2026-10-30T07:59', '2026-10-30T10:01'] as $paidAt) {
            $this->post(route('admin.laundry.payments.store', $order), array_replace($this->paymentData($order), ['paid_at' => $paidAt]))->assertSessionHasErrors('paid_at');
        }
        $data = $this->paymentData($order);
        unset($data['confirmed']);
        $this->post(route('admin.laundry.payments.store', $order), $data)->assertSessionHasErrors('confirmed');
        $this->post(route('admin.laundry.payments.store', $order), array_replace($this->paymentData($order), ['method' => 'transfer']))->assertSessionHasErrors(['proof', 'reference']);
        $this->assertSame(0, LaundryPayment::count());
        $this->assertSame(0, LaundryDocument::count());
        $this->assertSame([], Storage::disk('local')->allFiles('laundry/payment-proofs'));
    }

    public function test_transfer_proof_is_validated_stored_privately_and_scoped_to_its_order(): void
    {
        $order = $this->order();
        $other = $this->order();
        $data = array_replace($this->paymentData($order), ['method' => 'transfer', 'reference' => 'TRF-UJI-001', 'proof' => UploadedFile::fake()->image('bukti.png')]);
        $this->post(route('admin.laundry.payments.store', $order), $data)->assertSessionHasNoErrors();
        $payment = $order->payments()->sole();
        Storage::disk('local')->assertExists($payment->proof_path);
        $this->assertStringStartsWith('laundry/payment-proofs/', $payment->proof_path);
        $this->get(route('admin.laundry.payments.proof', [$order, $payment]))->assertOk()->assertDownload('bukti-laundry-'.$payment->id.'.png')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get(route('admin.laundry.payments.proof', [$other, $payment]))->assertNotFound();
        $this->get(route('admin.laundry.documents.show', [$other, $payment->document]))->assertNotFound();
        $this->post(route('admin.laundry.payments.void', [$other, $payment]), $this->voidData($other))->assertNotFound();
        $this->post(route('admin.laundry.documents.void', [$order, $payment->document]), $this->voidData($order))->assertNotFound();
        $this->assertNull($payment->fresh()->voided_at);
        foreach ([UploadedFile::fake()->createWithContent('bad.jpg', '<script>bad</script>')->mimeType('text/html'), UploadedFile::fake()->create('big.pdf', 5121, 'application/pdf')] as $bad) {
            $this->post(route('admin.laundry.payments.store', $order), array_replace($data, ['entry_key' => (string) Str::uuid(), 'revision' => $order->fresh()->revision, 'proof' => $bad]))->assertSessionHasErrors('proof');
        }
        $this->assertSame(1, LaundryPayment::count());
    }

    public function test_payment_retry_is_idempotent_and_changed_payload_or_old_voided_retry_cannot_create_money(): void
    {
        $order = $this->order();
        $data = $this->paymentData($order);
        $this->post(route('admin.laundry.payments.store', $order), $data)->assertSessionHasNoErrors();
        $this->post(route('admin.laundry.payments.store', $order), $data)->assertSessionHasNoErrors();
        $this->assertSame(1, $order->payments()->count());
        $this->assertSame(1, $order->documents()->count());
        $this->post(route('admin.laundry.payments.store', $order), array_replace($data, ['amount' => 12000]))->assertSessionHasErrors('entry_key');
        $payment = $order->payments()->sole();
        $this->post(route('admin.laundry.payments.void', [$order, $payment]), $this->voidData($order))->assertSessionHasNoErrors();
        $this->post(route('admin.laundry.payments.store', $order), $data)->assertSessionHasNoErrors();
        $this->assertSame(1, $order->payments()->count());
        $this->assertSame(0, $order->fresh()->paidAmount());
        $this->assertNotNull($payment->document->fresh()->voided_at);
        $this->get(route('admin.laundry.documents.show', [$order, $payment->document]))->assertOk()->assertSee('DOKUMEN DIBATALKAN');
    }

    public function test_payment_times_with_offsets_are_normalized_and_printed_in_wib(): void
    {
        $order = $this->order();
        $data = array_replace($this->paymentData($order), ['paid_at' => '2026-10-30T02:30:00Z']);
        $this->post(route('admin.laundry.payments.store', $order), $data)->assertSessionHasNoErrors();
        $payment = $order->payments()->sole();
        $this->assertSame('2026-10-30 09:30', $payment->paid_at->format('Y-m-d H:i'));
        $this->assertSame('2026-10-30T09:30:00+07:00', $payment->document->snapshot['payment']['paid_at']);
        $this->get(route('admin.laundry.documents.show', [$order, $payment->document]))->assertOk()->assertSee('30 Oct 2026 09:30 WIB')->assertDontSee('30 Oct 2026 02:30 WIB');
    }

    public function test_invoice_snapshot_and_receipt_remain_unchanged_after_other_data_changes(): void
    {
        $order = $this->order();
        $this->post(route('admin.laundry.documents.invoice', $order), ['revision' => $order->revision])->assertRedirect();
        $invoice = $order->documents()->sole();
        $snapshot = $invoice->snapshot;
        $this->post(route('admin.laundry.documents.invoice', $order), ['revision' => 1])->assertRedirect(route('admin.laundry.documents.show', [$order, $invoice]));
        $payment = $this->pay($order);
        $this->customer->update(['name' => 'Nama CRM berubah']);
        $this->tariff->update(['price' => 99999, 'name' => 'Tarif diubah']);
        $this->assertSame($snapshot, $invoice->fresh()->snapshot);
        $this->get(route('admin.laundry.documents.show', [$order, $invoice]))->assertSee('Ibu Uji Laundry')->assertDontSee('Nama CRM berubah')->assertSee('Cuci Lipat Uji')->assertDontSee('Tarif diubah');
        $this->post(route('admin.laundry.documents.void', [$order, $invoice]), $this->voidData($order))->assertSessionHasNoErrors();
        $this->post(route('admin.laundry.documents.invoice', $order), ['revision' => $order->fresh()->revision])->assertRedirect();
        $newInvoice = $order->documents()->where('type', 'invoice')->whereNull('voided_at')->sole();
        $this->assertNotSame($invoice->number, $newInvoice->number);
        $this->assertSame(15000, $newInvoice->snapshot['paid']);
        $this->assertSame(15000, $payment->document->snapshot['payment']['amount']);
        $this->assertSame(3, $order->documents()->count());
    }

    public function test_active_documents_and_payments_lock_reweighing_and_cancellation_until_corrected(): void
    {
        $order = $this->order();
        $this->post(route('admin.laundry.documents.invoice', $order), ['revision' => $order->revision])->assertRedirect();
        $invoice = $order->documents()->sole();
        $payment = $this->pay($order);
        $this->postAction($order, 'weigh', $this->weighing($order))->assertSessionHasErrors('laundry');
        $this->postAction($order, 'cancel', ['reason' => 'Pelanggan membatalkan.'])->assertSessionHasErrors('laundry');
        $this->post(route('admin.laundry.payments.void', [$order, $payment]), $this->voidData($order))->assertSessionHasNoErrors();
        $this->post(route('admin.laundry.documents.void', [$order, $invoice]), $this->voidData($order))->assertSessionHasNoErrors();
        $this->postAction($order, 'weigh', $this->weighing($order))->assertSessionHasNoErrors();
        $this->assertSame('awaiting_approval', $order->fresh()->status);
        $this->postAction($order, 'cancel', ['reason' => 'Pelanggan membatalkan.'])->assertSessionHasNoErrors();
        $this->assertSame(0, $order->fresh()->balance());
        $this->assertSame('cancelled', $order->fresh()->workflow_status);
        $this->assertSame(45000, $order->fresh()->actual_total);
        $this->get(route('admin.laundry.orders.show', $order))->assertOk();
    }

    public function test_processing_always_locks_pricing_even_without_active_documents_or_payments(): void
    {
        $order = $this->order();
        $this->start($order);
        $this->postAction($order, 'weigh', $this->weighing($order))->assertSessionHasErrors('laundry');
        $this->assertSame('approved', $order->fresh()->status);
        $this->get(route('admin.laundry.orders.show', $order))->assertOk()->assertDontSee('Simpan Hasil Penimbangan');
    }

    public function test_finance_and_operations_share_revision_guards_and_foreign_documents_cannot_be_voided(): void
    {
        $order = $this->order();
        $other = $this->order();
        $this->start($order);
        $oldRevision = $order->revision;
        $this->pay($order);
        $this->postAction($order, 'advance', ['next_stage' => 'washing', 'revision' => $oldRevision])->assertSessionHasErrors('laundry');
        $this->post(route('admin.laundry.documents.invoice', $other), ['revision' => $other->revision])->assertRedirect();
        $foreign = $other->documents()->sole();
        $this->post(route('admin.laundry.documents.void', [$order, $foreign]), $this->voidData($order))->assertNotFound();
        $this->assertNull($foreign->fresh()->voided_at);
        $this->assertSame('queued', $order->fresh()->workflow_status);
    }

    public function test_payment_correction_after_handover_preserves_physical_history_and_reopens_balance(): void
    {
        $order = $this->order();
        $this->ready($order);
        $payment = $this->pay($order, 45000);
        $this->postAction($order, 'handover', $this->handoverData($order))->assertSessionHasNoErrors();
        $this->post(route('admin.laundry.payments.void', [$order, $payment]), $this->voidData($order))->assertSessionHasNoErrors();
        $this->assertSame('handed_over', $order->fresh()->workflow_status);
        $this->assertSame(45000, $order->fresh()->balance());
        $this->get(route('admin.laundry.orders.show', $order))->assertOk()->assertSee('Ada sisa tagihan setelah koreksi pembayaran');
        $this->postAction($order, 'cancel', ['reason' => 'Tidak boleh setelah diserahkan.'])->assertSessionHasErrors('laundry');
        $this->pay($order, 45000);
        $this->assertSame(0, $order->fresh()->balance());
        $this->postAction($order, 'handover', $this->handoverData($order))->assertSessionHasErrors('laundry');
    }

    public function test_failed_receipt_creation_rolls_back_payment_event_revision_and_private_upload(): void
    {
        $order = $this->order();
        $revision = $order->revision;
        $eventCount = $order->events()->count();
        Event::listen('eloquent.created: '.LaundryDocument::class, function () {
            throw new RuntimeException('Simulasi kegagalan penyimpanan dokumen.');
        });
        try {
            app(LaundryFinance::class)->payment($order, $this->paymentData($order), UploadedFile::fake()->image('bukti.png'), $this->admin, Request::create('/'));
            $this->fail('Expected rollback.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulasi kegagalan penyimpanan dokumen.', $exception->getMessage());
        }
        $this->assertSame(0, LaundryPayment::count());
        $this->assertSame(0, LaundryDocument::count());
        $this->assertSame($revision, $order->fresh()->revision);
        $this->assertSame($eventCount, $order->events()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('laundry/payment-proofs'));
    }

    public function test_operations_board_includes_old_open_orders_and_filters_deadlines_demo_and_completed(): void
    {
        $overdue = $this->order();
        $overdue->update(['received_at' => '2026-09-01 08:00', 'estimated_ready_at' => '2026-09-02 08:00']);
        $soon = $this->order();
        $soon->update(['estimated_ready_at' => now()->addHours(3)]);
        $demo = $this->order();
        $demo->update(['is_demo' => true, 'estimated_ready_at' => now()->subDay()]);
        $ready = $this->order();
        $this->ready($ready);
        $ready->update(['estimated_ready_at' => now()->subDay()]);
        $cancelled = $this->order();
        $this->postAction($cancelled, 'cancel', ['reason' => 'Pesanan dibatalkan pelanggan.'])->assertSessionHasNoErrors();
        $this->get(route('admin.laundry.operations'))->assertOk()->assertViewHas('counts', fn ($c) => $c['overdue'] === 1 && $c['soon'] === 1 && $c['ready'] === 1 && $c['open'] === 3)->assertSee($overdue->code)->assertDontSee($demo->code)->assertDontSee($cancelled->code);
        $this->get(route('admin.laundry.operations', ['deadline' => 'overdue']))->assertViewHas('orders', fn ($rows) => $rows->total() === 1);
        $this->get(route('admin.laundry.operations', ['include_demo' => 1, 'deadline' => 'overdue']))->assertViewHas('orders', fn ($rows) => $rows->total() === 2);
        $this->assertNull($ready->fresh()->deadlineWarning());
        $this->assertSame('Melewati estimasi selesai', $overdue->fresh()->deadlineWarning());
    }

    public function test_all_new_endpoints_are_private_and_only_active_admin_roles_can_use_them(): void
    {
        $order = $this->order();
        $payment = $this->pay($order);
        $document = $payment->document;
        foreach (['owner', 'manager', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'active']))->get(route('admin.laundry.operations'))->assertOk();
        }
        foreach (['sales', 'therapist'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'active']));
            $this->get(route('admin.laundry.operations'))->assertForbidden();
            $this->get(route('admin.laundry.documents.show', [$order, $document]))->assertForbidden();
            $this->get(route('admin.laundry.payments.proof', [$order, $payment]))->assertForbidden();
            foreach (['start', 'advance', 'quality', 'handover'] as $action) {
                $this->post(route('admin.laundry.orders.'.$action, $order), [])->assertForbidden();
            }
            $this->post(route('admin.laundry.payments.store', $order), [])->assertForbidden();
            $this->post(route('admin.laundry.payments.void', [$order, $payment]), [])->assertForbidden();
            $this->post(route('admin.laundry.documents.invoice', $order), [])->assertForbidden();
            $this->post(route('admin.laundry.documents.void', [$order, $document]), [])->assertForbidden();
        }
        $this->app['auth']->guard('web')->logout();
        $this->get(route('admin.laundry.operations'))->assertRedirect(route('login'));
        $this->get(route('admin.laundry.documents.show', [$order, $document]))->assertRedirect(route('login'));
    }
}
