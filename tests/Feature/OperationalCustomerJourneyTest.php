<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppMessageJob;
use App\Models\AvailabilitySlot;
use App\Models\Booking;
use App\Models\LaundryOrder;
use App\Models\LaundryPublicRequest;
use App\Models\LaundryQualityCheck;
use App\Models\LaundryTariff;
use App\Models\RentalOrder;
use App\Models\RentalProduct;
use App\Models\RentalUnit;
use App\Models\Service;
use App\Models\User;
use App\Services\CRM\BookingFinance;
use App\Services\CRM\LaundryPublicSettings;
use App\Services\CRM\LaundrySettings;
use App\Services\CRM\RentalSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationalCustomerJourneyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-05 10:00:00', 'Asia/Jakarta'));
        Http::fake();
        Queue::fake();
        Mail::fake();
        Notification::fake();
        Storage::fake('local');
        Storage::fake('public');
        config(['app.url' => 'http://localhost']);
        $this->admin = User::factory()->create(['role' => 'owner', 'status' => 'active']);
    }

    public function test_booking_request_confirmation_dp_settlement_completion_receipts_and_reports_share_one_booking(): void
    {
        $service = Service::create(['name' => 'Pijat Operasional Uji', 'service_group' => 'spa', 'price' => 100000, 'duration_minutes' => 60, 'is_active' => true]);
        $slot = AvailabilitySlot::create(['service_id' => $service->id, 'slot_date' => today(), 'start_time' => '13:00:00', 'end_time' => '14:00:00', 'capacity' => 1, 'booked_count' => 0, 'status' => 'available']);
        $request = ['name' => 'Pemesan Booking Operasional', 'whatsapp_number' => '6280000097101', 'service_group' => 'spa', 'service_id' => $service->id, 'availability_slot_id' => $slot->id, 'booking_date' => today()->toDateString(), 'start_time' => '13:00'];
        $this->post(route('landing.booking'), $request)->assertRedirect()->assertSessionHasNoErrors();
        $booking = Booking::sole();
        $this->assertSame('pending_confirmation', $booking->status);
        $this->assertSame('unpaid', $booking->payment_status);
        $this->assertSame(1, $slot->fresh()->booked_count);
        $this->assertDatabaseCount('booking_payments', 0);

        $this->actingAs($this->admin)->post(route('admin.bookings.verify-contact', $booking), ['reference' => 'Kontak sintetis diperiksa untuk pengujian.', 'confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $booking->refresh();
        $this->assertNotNull($booking->contact_verified_at);
        $update = ['customer_id' => $booking->customer_id, 'service_id' => $service->id, 'availability_slot_id' => $slot->id, 'booking_date' => today()->toDateString(), 'start_time' => '13:00', 'status' => 'confirmed', 'payment_status' => 'unpaid'];
        $this->put(route('admin.bookings.update', $booking), $update)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('confirmed', $booking->fresh()->status);

        $dpData = $this->bookingPaymentData($booking, 40000);
        $this->post(route('admin.bookings.finance.record', $booking), $dpData)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('admin.bookings.finance.record', $booking), $dpData)->assertRedirect()->assertSessionHasNoErrors();
        $dp = $booking->payments()->sole();
        $dpSnapshot = $dp->snapshot;
        $this->assertSame('partial', $booking->fresh()->payment_status);
        $this->assertSame(6000000, app(BookingFinance::class)->figures($booking->fresh())['balance']);
        $partial = $this->get(route('admin.reports.index', $this->period()))->assertOk()->viewData('summary');
        $this->assertEquals(40000, $partial['payments']);
        $this->assertEquals(60000, $partial['balances']);

        $settlementData = $this->bookingPaymentData($booking, 60000) + ['reference' => 'OPERATIONS-BOOKING-SETTLEMENT', 'file' => UploadedFile::fake()->createWithContent('booking-proof.pdf', "%PDF-1.4\n%%EOF")];
        $settlementData['method'] = 'transfer';
        $this->post(route('admin.bookings.finance.record', $booking), $settlementData)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('paid', $booking->fresh()->payment_status);
        $this->assertSame(0, app(BookingFinance::class)->figures($booking->fresh())['balance']);
        $settlement = $booking->payments()->latest('id')->firstOrFail();
        Storage::disk('local')->assertExists($settlement->proof_path);
        $this->get(route('admin.bookings.finance.proof', [$booking, $settlement]))->assertOk();

        $this->travelTo(Carbon::parse('2026-11-05 14:15:00', 'Asia/Jakarta'));
        $this->put(route('admin.bookings.update', $booking), array_replace($update, ['status' => 'completed', 'payment_status' => 'paid']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('completed', $booking->fresh()->status);
        $this->assertSame('paid', $booking->fresh()->payment_status);
        $this->assertSame($dpSnapshot, $dp->fresh()->snapshot);
        foreach ([$dp, $settlement] as $payment) {
            $this->get(route('admin.bookings.finance.receipt', [$booking, $payment]))->assertOk()->assertSee($payment->receipt_number)->assertSee($booking->booking_code);
        }
        $summary = $this->get(route('admin.reports.index', $this->period()))->assertOk()->viewData('summary');
        $this->assertSame(1, $summary['bookings']);
        $this->assertEquals(100000, $summary['revenue']);
        $this->assertEquals(100000, $summary['payments']);
        $this->assertEquals(0, $summary['balances']);
        $rows = $this->csvRows(route('admin.reports.export', ['type' => 'payments'] + $this->period()));
        $this->assertCount(2, $rows);
        $this->assertSame([$booking->booking_code], array_values(array_unique(array_column($rows, 'Kode'))));
        $this->assertEquals(100000, array_sum(array_column($rows, 'Nominal Rp')));
        $this->assertSame([$dp->receipt_number, $settlement->receipt_number], array_column($rows, 'Kuitansi'));
        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('booking_payments', 2);
        $this->assertDatabaseCount('rental_orders', 0);
        $this->assertDatabaseCount('laundry_orders', 0);
        Queue::assertPushed(SendWhatsAppMessageJob::class);
        $this->assertNoDelivery();
    }

    public function test_rental_portal_dp_approval_settlement_dispatch_return_inspection_refund_and_report_share_one_order(): void
    {
        $settings = app(RentalSettings::class);
        $settings->save(array_replace($settings->get(), ['dp_type' => 'percent', 'dp_value' => 50, 'require_full_payment' => true]));
        $product = RentalProduct::create(['sku' => 'OPERATIONS-STROLLER', 'name' => 'Stroller Operasional Uji', 'category' => 'Stroller', 'daily_rate' => 25000, 'deposit' => 100000, 'turnaround_days' => 1, 'is_active' => true, 'inspection_checklist' => ['Sabuk lengkap', 'Pengunci berfungsi', 'Bersih dan kering']]);
        $unit = RentalUnit::create(['rental_product_id' => $product->id, 'code' => 'OPERATIONS-STROLLER-001', 'status' => 'ready', 'condition_notes' => 'Lengkap dan bersih']);
        $request = ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'start_date' => '2026-11-05', 'end_date' => '2026-11-07', 'period' => 'day', 'fulfilment' => 'self', 'name' => 'Penyewa Operasional Uji', 'whatsapp_number' => '6280000097102', 'terms_accepted' => 1];
        $request['quote_token'] = $this->postJson(route('rental.quote'), $request)->assertOk()->json('token');
        $this->post(route('rental.store'), $request)->assertRedirect()->assertSessionHasNoErrors();
        $order = RentalOrder::sole();
        $this->assertSame('pending', $order->status);
        $this->assertNotNull($order->terms_accepted_at);
        $this->get(route('rental.portal.show', $order->code))->assertOk();
        $this->actingAs($this->admin)->post(route('admin.rentals.orders.action', $order), ['action' => 'confirm'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('reserved', $order->fresh()->status);
        $this->assertEquals(175000, $order->fresh()->balance());
        $item = $order->items()->sole();
        $this->post(route('admin.rentals.care.checkout', [$order, $item]), ['entry_key' => (string) Str::uuid(), 'care_version' => $unit->fresh()->care_version, 'notes' => 'Kondisi dan kelengkapan diperiksa sebelum keluar.', 'checklist' => ['ok', 'ok', 'ok']])->assertRedirect()->assertSessionHasNoErrors();

        $this->app['auth']->guard('web')->logout();
        $dpData = ['entry_key' => (string) Str::uuid(), 'kind' => 'dp', 'amount' => 37500, 'payer_name' => 'Penyewa Operasional Uji', 'payment_date' => today()->toDateString(), 'reference' => 'OPERATIONS-RENTAL-DP', 'proof' => UploadedFile::fake()->image('rental-dp.png')];
        $this->post(route('rental.portal.proof', $order->code), $dpData)->assertRedirect()->assertSessionHasNoErrors();
        $proof = $order->paymentProofs()->sole();
        $this->assertSame('pending', $proof->status);
        Storage::disk('local')->assertExists($proof->file_path);
        $this->assertDatabaseCount('rental_payments', 0);
        $this->assertEquals(175000, $order->fresh()->balance());
        $pending = $this->actingAs($this->admin)->get(route('admin.rentals.reports', $this->period()))->assertOk()->viewData('report');
        $this->assertSame(0, $pending['period']['paid']);
        $this->assertSame(1, $pending['current']['pending_proofs']);
        $this->post(route('admin.rentals.orders.action', $order), ['action' => 'dispatch'])->assertSessionHasErrors('rental');
        $review = ['action' => 'approve', 'review_notes' => 'Penerimaan sintetis dicocokkan oleh pemilik.'];
        $this->post(route('admin.rentals.proofs.review', [$order, $proof]), $review)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('admin.rentals.proofs.review', [$order, $proof]), $review)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('approved', $proof->fresh()->status);
        $this->assertDatabaseCount('rental_payments', 1);
        $dpReceipt = $order->documents()->sole();
        $dpSnapshot = $dpReceipt->snapshot;
        $this->assertSame('37500.00', $dpSnapshot['receipt']['amount']);
        $this->assertEquals(137500, $order->fresh()->balance());
        $this->post(route('admin.rentals.orders.action', $order), ['action' => 'dispatch'])->assertSessionHasErrors('rental');

        $this->app['auth']->guard('web')->logout();
        $this->post(route('rental.portal.proof', $order->code), array_replace($dpData, ['entry_key' => (string) Str::uuid(), 'kind' => 'settlement', 'amount' => 137500, 'reference' => 'OPERATIONS-RENTAL-SETTLEMENT', 'proof' => UploadedFile::fake()->image('rental-settlement.png', 20, 20)]))->assertRedirect()->assertSessionHasNoErrors();
        $settlementProof = $order->paymentProofs()->latest('id')->firstOrFail();
        $this->assertEquals(137500, $order->fresh()->balance());
        $this->actingAs($this->admin)->post(route('admin.rentals.proofs.review', [$order, $settlementProof]), $review)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals(0, $order->fresh()->balance());
        $this->post(route('admin.rentals.orders.action', $order), ['action' => 'dispatch'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('rented', $order->fresh()->status);
        $this->assertSame('rented', $unit->fresh()->status);

        $this->travelTo(Carbon::parse('2026-11-07 10:00:00', 'Asia/Jakarta'));
        $this->post(route('admin.rentals.orders.return', [$order, $item]), ['notes' => 'Unit diterima lengkap pada tanggal kontrak.', 'photo' => UploadedFile::fake()->image('rental-return.png')])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('inspection', $unit->fresh()->status);
        $this->assertEquals(0, $order->fresh()->refundable());
        Storage::disk('local')->assertExists($item->fresh()->return_photo_path);
        $this->post(route('admin.rentals.orders.inspect', [$order, $item]), ['notes' => 'Lengkap, bersih, tanpa kerusakan atau keterlambatan.', 'unit_status' => 'ready', 'late_fee' => 0, 'damage_fee' => 0, 'checklist' => ['ok', 'ok', 'ok']])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('ready', $unit->fresh()->status);
        $this->assertNotNull($item->fresh()->inspected_at);
        $this->assertEquals(100000, $order->fresh()->refundable());
        $refundData = ['entry_key' => (string) Str::uuid(), 'kind' => 'refund', 'amount' => 100000, 'method' => 'cash', 'party_name' => 'Penyewa Operasional Uji', 'reference' => 'OPERATIONS-RENTAL-REFUND', 'notes' => 'Deposit sintetis dikembalikan setelah pemeriksaan.'];
        $this->post(route('admin.rentals.orders.payment', $order), $refundData)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('admin.rentals.orders.payment', $order), $refundData)->assertRedirect()->assertSessionHasNoErrors();
        $refund = $order->payments()->where('kind', 'refund')->sole();
        $this->post(route('admin.rentals.orders.receipt', [$order, $refund]))->assertRedirect()->assertSessionHasNoErrors();
        $refundReceipt = $order->documents()->where('type', 'refund_receipt')->sole();
        $this->get(route('admin.rentals.documents.show', [$order, $refundReceipt]))->assertOk()->assertSee('100.000');
        $this->post(route('admin.rentals.orders.invoice', $order))->assertRedirect()->assertSessionHasNoErrors();
        $invoice = $order->documents()->where('type', 'invoice')->sole();
        $this->assertSame('75000.00', $invoice->snapshot['totals']['net_received']);
        $this->assertSame('0.00', $invoice->snapshot['totals']['balance']);
        $this->assertSame($dpSnapshot, $dpReceipt->fresh()->snapshot);
        $this->assertEquals(0, $order->fresh()->refundable());
        $report = $this->get(route('admin.rentals.reports', $this->period('2026-11-05')))->assertOk()->assertSee($order->code)->viewData('report');
        $this->assertSame(1, $report['period']['orders']);
        $this->assertSame(17500000, $report['period']['paid']);
        $this->assertSame(10000000, $report['period']['refunds']);
        $this->assertSame(7500000, $report['period']['net']);
        $this->assertSame(7500000, $report['period']['charges']);
        foreach (['balance', 'deposit_held', 'refundable', 'pending_proofs'] as $field) {
            $this->assertSame(0, $report['current'][$field], $field);
        }
        $rows = $this->csvRows(route('admin.rentals.reports.export', ['type' => 'orders'] + $this->period('2026-11-05')));
        $this->assertCount(1, $rows);
        $this->assertSame($order->code, $rows[0]['Kode']);
        $this->assertEquals(75000, $rows[0]['Tagihan jasa']);
        $this->assertEquals(175000, $rows[0]['Diterima']);
        $this->assertEquals(100000, $rows[0]['Refund']);
        $this->assertEquals(0, $rows[0]['Saldo refundable']);
        $this->assertDatabaseCount('rental_orders', 1);
        $this->assertDatabaseCount('rental_items', 1);
        $this->assertDatabaseCount('rental_payments', 3);
        $this->assertDatabaseCount('rental_payment_proofs', 2);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('laundry_orders', 0);
        Queue::assertNothingPushed();
        $this->assertNoDelivery();
    }

    public function test_laundry_public_request_intake_weighing_consent_qc_payment_handover_invoice_and_report_share_one_order(): void
    {
        app(LaundrySettings::class)->save(['accepting_orders' => true, 'outlet_name' => 'Outlet Operasional Uji', 'address' => 'Alamat sintetis outlet', 'whatsapp_number' => '6280000097100', 'allow_collection' => true], $this->admin, Request::create('/'));
        $public = app(LaundryPublicSettings::class);
        $public->save(array_replace($public->get(), ['enabled' => true]), $this->admin, Request::create('/'));
        $tariff = LaundryTariff::create(['code' => 'OPERATIONS-KG', 'name' => 'Cuci Operasional Uji', 'audience' => 'baby', 'unit' => 'kg', 'price' => 10000, 'minimum_milli' => 1000, 'increment_milli' => 500, 'duration_hours' => 24, 'is_active' => true]);
        $key = $this->get(route('laundry.index'))->assertOk()->viewData('entryKey');
        $preview = $this->post(route('laundry.preview'), ['entry_key' => $key, 'name' => 'Pelanggan Laundry Operasional', 'whatsapp_number' => '6280000097103', 'fulfilment' => 'dropoff', 'items' => [['laundry_tariff_id' => $tariff->id, 'quantity' => '2']]])->assertOk()->viewData('preview');
        $this->post(route('laundry.store'), ['token' => $preview['token'], 'confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $entry = LaundryPublicRequest::sole();
        $this->assertDatabaseCount('laundry_orders', 0);
        $this->assertDatabaseCount('customers', 0);
        $this->actingAs($this->admin)->post(route('admin.laundry.requests.verify', $entry), ['revision' => $entry->revision, 'decision' => 'verify', 'reference' => 'Kontak sintetis telah diperiksa.', 'confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('admin.laundry.requests.intake', $entry->fresh()), ['confirmed' => 1])->assertRedirect();
        $draft = session('laundry.intake_draft');
        $draft['items'][0]['received_pieces'] = 4;
        $draft['items'][0]['condition_notes'] = 'Empat pakaian diterima dengan label yang sesuai.';
        $intake = $this->post(route('admin.laundry.orders.preview'), $draft)->assertOk()->viewData('preview');
        $this->post(route('admin.laundry.orders.store'), ['token' => $intake['token'], 'confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $order = LaundryOrder::sole();
        $this->assertSame($order->id, $entry->fresh()->laundry_order_id);
        $this->assertSame('received', $order->status);
        $item = $order->items()->sole();
        $this->post(route('admin.laundry.orders.weigh', $order), ['revision' => $order->revision, 'items' => [['id' => $item->id, 'actual_pieces' => 4, 'quantity' => '2.31', 'notes' => 'Empat pakaian lengkap.']]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('awaiting_approval', $order->fresh()->status);
        $this->assertSame(25000, $order->fresh()->actual_total);
        $steps = ['washing', 'drying', 'finishing', 'packing'];
        $this->post(route('admin.laundry.orders.start', $order), ['revision' => $order->fresh()->revision, 'steps' => $steps])->assertSessionHasErrors('laundry');

        $this->app['auth']->guard('web')->logout();
        $this->post(route('laundry.portal.approve', $entry->public_id), ['revision' => $order->fresh()->revision, 'fingerprint' => $order->fresh()->pricingFingerprint(), 'approved_name' => $entry->name, 'confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('approved', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->approved_at);
        $this->actingAs($this->admin)->post(route('admin.laundry.orders.start', $order), ['revision' => $order->fresh()->revision, 'steps' => $steps, 'notes' => 'Rencana lengkap cuci sampai kemas.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('queued', $order->fresh()->workflow_status);
        foreach ([...$steps, 'quality_check'] as $stage) {
            $this->post(route('admin.laundry.orders.advance', $order), ['revision' => $order->fresh()->revision, 'next_stage' => $stage, 'notes' => 'Tahap sebelumnya selesai diperiksa.'])->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame($stage, $order->fresh()->workflow_status);
        }
        $handover = ['receiver_name' => 'Penerima Laundry Operasional', 'receiver_phone' => '6280000097103', 'confirmed' => 1, 'notes' => 'Empat pakaian diterima lengkap.', 'items' => [['id' => $item->id, 'returned_pieces' => 4, 'label_confirmed' => 1]]];
        $this->post(route('admin.laundry.orders.handover', $order), ['revision' => $order->fresh()->revision] + $handover)->assertSessionHasErrors('laundry');
        $this->post(route('admin.laundry.orders.quality', $order), ['revision' => $order->fresh()->revision, 'result' => 'pass', 'notes' => 'Kondisi, kebersihan dan jumlah cocok.', 'items' => [['id' => $item->id, 'checked_pieces' => 4, 'notes' => 'Seluruh pemeriksaan lulus.'] + array_fill_keys(array_keys(LaundryQualityCheck::CHECKS), 1)]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('ready', $order->fresh()->workflow_status);
        $this->assertTrue($order->qualityChecks()->sole()->passed);
        $this->post(route('admin.laundry.orders.handover', $order), ['revision' => $order->fresh()->revision] + $handover)->assertSessionHasErrors('laundry');

        $paymentData = ['revision' => $order->fresh()->revision, 'entry_key' => (string) Str::uuid(), 'amount' => 25000, 'method' => 'transfer', 'paid_at' => now()->format('Y-m-d\TH:i'), 'payer_name' => $entry->name, 'reference' => 'OPERATIONS-LAUNDRY-SETTLEMENT', 'confirmed' => 1, 'proof' => UploadedFile::fake()->image('laundry-payment.png')];
        $this->post(route('admin.laundry.payments.store', $order), $paymentData)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('admin.laundry.payments.store', $order), $paymentData)->assertRedirect()->assertSessionHasNoErrors();
        $payment = $order->payments()->sole();
        Storage::disk('local')->assertExists($payment->proof_path);
        $receipt = $payment->document;
        $receiptSnapshot = $receipt->snapshot;
        $this->assertSame(0, $order->fresh()->balance());
        $this->post(route('admin.laundry.orders.handover', $order), ['revision' => $order->fresh()->revision] + $handover)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('handed_over', $order->fresh()->workflow_status);
        $this->assertNotNull($order->fresh()->handed_over_at);
        $handoverEvent = $order->events()->where('kind', 'handed_over')->sole();
        $this->assertSame($item->id, $handoverEvent->details['items'][0]['id']);
        $this->assertSame(4, $handoverEvent->details['items'][0]['returned_pieces']);
        $this->assertTrue($handoverEvent->details['items'][0]['label_confirmed']);
        $this->assertSame($handover['receiver_name'], $order->fresh()->receiver_name);
        $this->post(route('admin.laundry.documents.invoice', $order), ['revision' => $order->fresh()->revision])->assertRedirect()->assertSessionHasNoErrors();
        $invoice = $order->documents()->where('type', 'invoice')->sole();
        $this->assertSame(25000, $invoice->snapshot['paid']);
        $this->assertSame(0, $invoice->snapshot['balance']);
        $this->assertSame($receiptSnapshot, $receipt->fresh()->snapshot);
        $this->get(route('admin.laundry.documents.show', [$order, $invoice]))->assertOk()->assertSee('INVOICE LAUNDRY')->assertSee($order->code)->assertSee('Rp 25.000');
        $this->get(route('admin.laundry.orders.receipt', $order))->assertOk()->assertSee('BUKTI SERAH TERIMA BARANG')->assertSee($handover['receiver_name']);
        $report = $this->get(route('admin.laundry.reports', $this->period()))->assertOk()->assertSee($order->code)->viewData('report');
        $this->assertSame(1, $report['period']['orders']);
        $this->assertSame(1, $report['period']['completed']);
        $this->assertSame(25000, $report['period']['charges']);
        $this->assertSame(25000, $report['period']['paid']);
        $this->assertSame(0, $report['current']['balance']);
        $rows = $this->csvRows(route('admin.laundry.reports.export', ['type' => 'payments'] + $this->period()));
        $this->assertCount(1, $rows);
        $this->assertSame($order->code, $rows[0]['Kode']);
        $this->assertEquals(25000, $rows[0]['Nominal Rp']);
        $this->assertSame('OPERATIONS-LAUNDRY-SETTLEMENT', $rows[0]['Referensi']);
        $this->app['auth']->guard('web')->logout();
        $this->get(route('laundry.portal.document', [$entry->public_id, $receipt->id]))->assertOk()->assertSee('25.000');
        $this->get(route('laundry.portal.document', [$entry->public_id, $invoice->id]))->assertOk()->assertSee('INVOICE LAUNDRY');
        $this->assertDatabaseCount('laundry_public_requests', 1);
        $this->assertDatabaseCount('laundry_orders', 1);
        $this->assertDatabaseCount('laundry_payments', 1);
        $this->assertDatabaseCount('laundry_quality_checks', 1);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('rental_orders', 0);
        Queue::assertNothingPushed();
        $this->assertNoDelivery();
    }

    private function bookingPaymentData(Booking $booking, int $amount): array
    {
        $booking->refresh();

        return ['revision' => $booking->finance_revision, 'fingerprint' => app(BookingFinance::class)->fingerprint($booking), 'entry_key' => (string) Str::uuid(), 'kind' => 'payment', 'amount' => $amount, 'method' => 'cash', 'paid_at' => now()->format('Y-m-d\TH:i'), 'payer_name' => 'Pembayar Booking Operasional', 'confirmed' => 1];
    }

    private function period(?string $start = null): array
    {
        return ['start_date' => $start ?? today()->toDateString(), 'end_date' => today()->toDateString()];
    }

    private function csvRows(string $url): array
    {
        $response = $this->get($url)->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, substr($content, 3));
        rewind($stream);
        $headers = fgetcsv($stream, 0, ',', '"', '');
        $rows = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $rows[] = array_combine($headers, $row);
        }
        fclose($stream);

        return $rows;
    }

    private function assertNoDelivery(): void
    {
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('jobs', 0);
    }
}
