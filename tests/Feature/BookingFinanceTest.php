<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use App\Services\CRM\BookingFinance;
use App\Services\CRM\BookingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingFinanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-31 10:00:00', 'Asia/Jakarta'));
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($this->admin);
    }

    private function booking(array $changes = []): Booking
    {
        $customer = Customer::create(['name' => 'Uji Transaksi', 'whatsapp_number' => '62800000'.random_int(10000, 99999), 'status' => 'lead']);
        $service = Service::create(['name' => 'Treatment', 'duration_minutes' => 60, 'price' => 100000, 'is_active' => true]);

        return Booking::create(array_replace(['customer_id' => $customer->id, 'service_id' => $service->id, 'booking_code' => 'FIN-'.Str::upper(Str::random(8)), 'booking_date' => '2026-10-31', 'start_time' => '10:00:00', 'end_time' => '11:00:00', 'status' => 'pending_confirmation', 'payment_status' => 'unpaid', 'service_price_snapshot' => 100000, 'travel_fee' => 10000, 'finance_managed' => true, 'finance_revision' => 1, 'legacy_credit_cents' => 0, 'created_at' => '2026-10-31 09:00:00'], $changes))->fresh();
    }

    private function data(Booking $booking, array $changes = []): array
    {
        return array_replace(['revision' => $booking->fresh()->finance_revision, 'entry_key' => (string) Str::uuid(), 'fingerprint' => app(BookingFinance::class)->fingerprint($booking->fresh()), 'kind' => 'payment', 'amount' => '30000', 'method' => 'cash', 'paid_at' => '2026-10-31T10:00', 'payer_name' => 'Pembayar Uji', 'confirmed' => 1], $changes);
    }

    private function record(Booking $booking, array $changes = []): BookingPayment
    {
        $this->post(route('admin.bookings.finance.record', $booking), $this->data($booking, $changes))->assertSessionHasNoErrors();

        return $booking->payments()->latest('id')->firstOrFail();
    }

    public function test_partial_full_refund_and_void_update_status_and_retain_receipt_history(): void
    {
        $booking = $this->booking();
        $first = $this->record($booking);
        $this->assertSame('partial', $booking->fresh()->payment_status);
        $this->assertSame(8000000, app(BookingFinance::class)->figures($booking->fresh())['balance']);
        $last = $this->record($booking, ['amount' => 80000]);
        $this->assertSame('paid', $booking->fresh()->payment_status);
        $refund = $this->record($booking, ['kind' => 'refund', 'amount' => 10000, 'notes' => 'Pelanggan menerima pengembalian sebagian.']);
        $this->assertSame('partial', $booking->fresh()->payment_status);
        $this->post(route('admin.bookings.finance.void', [$booking, $refund]), ['revision' => $booking->fresh()->finance_revision, 'reason' => 'Koreksi uji pencatatan refund.', 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertSame('paid', $booking->fresh()->payment_status);
        $this->get(route('admin.bookings.finance.receipt', [$booking, $refund]))->assertOk()->assertSee('KUITANSI TIDAK BERLAKU');
        $this->get(route('admin.bookings.finance', $booking))->assertOk();
    }

    public function test_double_submission_and_retry_after_void_cannot_duplicate_money(): void
    {
        $booking = $this->booking();
        $data = $this->data($booking);
        foreach (range(1, 2) as $_) {
            $this->post(route('admin.bookings.finance.record', $booking), $data)->assertSessionHasNoErrors();
        }$this->assertDatabaseCount('booking_payments', 1);
        $payment = BookingPayment::first();
        $this->post(route('admin.bookings.finance.void', [$booking, $payment]), ['revision' => $booking->fresh()->finance_revision, 'reason' => 'Koreksi data uji pembayaran.', 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->post(route('admin.bookings.finance.record', $booking), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('booking_payments', 1);
        $this->assertSame('unpaid', $booking->fresh()->payment_status);
    }

    public function test_transfer_requires_private_valid_proof_and_scopes_downloads_and_receipts(): void
    {
        $booking = $this->booking();
        $this->post(route('admin.bookings.finance.record', $booking), $this->data($booking, ['method' => 'transfer']))->assertSessionHasErrors(['file', 'reference']);
        $proof = UploadedFile::fake()->createWithContent('bukti.pdf', "%PDF-1.4\n%%EOF");
        $payment = $this->record($booking, ['method' => 'transfer', 'reference' => 'TRF-001', 'file' => $proof]);
        Storage::disk('local')->assertExists($payment->proof_path);
        $this->get(route('admin.bookings.finance.proof', [$booking, $payment]))->assertOk();
        $this->get(route('admin.bookings.finance.receipt', [$booking, $payment]))->assertOk()->assertSee($payment->receipt_number);
        $other = $this->booking();
        $this->get(route('admin.bookings.finance.proof', [$other, $payment]))->assertNotFound();
        $this->get(route('admin.bookings.finance.receipt', [$other, $payment]))->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'sales', 'status' => 'active']))->get(route('admin.bookings.finance', $booking))->assertForbidden();
    }

    public function test_amount_stale_revision_fingerprint_and_future_time_are_rejected(): void
    {
        $booking = $this->booking();
        foreach ([['amount' => 110001], ['amount' => -1], ['amount' => '1.001'], ['revision' => 99], ['fingerprint' => str_repeat('a', 64)], ['paid_at' => '2026-11-01T10:00']] as $changes) {
            $this->post(route('admin.bookings.finance.record', $booking), $this->data($booking, $changes))->assertSessionHasErrors();
        }$this->assertDatabaseCount('booking_payments', 0);
    }

    public function test_existing_paid_status_never_creates_new_cash_and_opening_credit_is_separate(): void
    {
        $booking = $this->booking(['finance_managed' => false, 'payment_status' => 'paid']);
        $this->get(route('admin.bookings.finance', $booking))->assertOk()->assertSee('Belum ada saldo jurnal');
        $this->assertDatabaseCount('booking_payments', 0);
        $this->post(route('admin.bookings.finance.activate', $booking), ['revision' => 1, 'credit' => 110000, 'reference' => 'Bukti lama telah ditinjau admin.', 'confirmed' => 1])->assertSessionHasNoErrors();
        $f = app(BookingFinance::class)->figures($booking->fresh());
        $this->assertSame(11000000, $f['credit']);
        $this->assertSame(0, $f['paid']);
        $this->assertSame(0, $f['balance']);
        $this->assertDatabaseCount('booking_payments', 0);
    }

    public function test_public_booking_requires_contact_verification_before_payment(): void
    {
        $booking = $this->booking(['public_request_data' => ['name' => 'Kontak belum diverifikasi', 'whatsapp_number' => '6280000090000']]);
        $this->post(route('admin.bookings.finance.record', $booking), $this->data($booking))->assertSessionHasErrors('finance');
        $this->assertDatabaseCount('booking_payments', 0);
    }

    public function test_refund_and_correction_cannot_create_negative_or_excess_balance(): void
    {
        $booking = $this->booking();
        $payment = $this->record($booking);
        $refund = $this->record($booking, ['kind' => 'refund', 'amount' => 30000, 'notes' => 'Pengembalian penuh sudah dilakukan.']);
        $this->assertSame('refunded', $booking->fresh()->payment_status);
        $this->post(route('admin.bookings.finance.void', [$booking, $payment]), ['revision' => $booking->fresh()->finance_revision, 'reason' => 'Koreksi tidak valid.', 'confirmed' => 1])->assertSessionHasErrors('finance');
        $this->post(route('admin.bookings.finance.record', $booking), $this->data($booking, ['kind' => 'refund', 'amount' => 1, 'notes' => 'Refund berlebih tidak boleh.']))->assertSessionHasErrors('amount');
    }

    public function test_reports_include_all_501_rows_and_distinguish_cash_from_historical_status(): void
    {
        $booking = $this->booking();
        $rows = [];
        foreach (range(1, 500) as $i) {
            $row = $booking->getAttributes();
            unset($row['id']);
            $row['booking_code'] = 'BULK-FIN-'.$i;
            $row['payment_status'] = 'paid';
            $row['finance_managed'] = false;
            $rows[] = $row;
        }DB::table('bookings')->insert($rows);
        $this->record($booking);
        $r = $this->get(route('admin.reports.index', ['start_date' => '2026-10-31', 'end_date' => '2026-10-31']))->assertOk();
        $this->assertSame(501, $r->viewData('summary')['bookings']);
        $this->assertEquals(55110000, $r->viewData('summary')['revenue']);
        $this->assertEquals(30000, $r->viewData('summary')['payments']);
        $csv = $this->get(route('admin.reports.export', ['type' => 'revenue', 'start_date' => '2026-10-31', 'end_date' => '2026-10-31']))->assertOk()->streamedContent();
        $this->assertCount(502, explode("\n", trim(substr($csv, 3))));
        $this->get(route('admin.reports.show', ['type' => 'balances']))->assertOk()->assertSee('80.000');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    private function editData(Booking $booking, array $changes = []): array
    {
        return array_replace(['customer_id' => $booking->customer_id, 'service_id' => $booking->service_id, 'booking_date' => $booking->booking_date->toDateString(), 'start_time' => '10:00', 'status' => $booking->status, 'payment_status' => $booking->fresh()->payment_status], $changes);
    }

    public function test_http_cannot_forge_payment_status_or_confirm_unverified_public_contact(): void
    {
        $booking = $this->booking();
        $this->put(route('admin.bookings.update', $booking), $this->editData($booking, ['payment_status' => 'paid']))->assertSessionHasErrors('payment_status');
        $this->post(route('admin.bookings.store'), $this->editData($booking, ['payment_status' => 'paid']))->assertSessionHasErrors('payment_status');
        $booking->update(['public_request_data' => ['name' => 'Pemesan', 'whatsapp_number' => $booking->customer->whatsapp_number]]);
        $this->put(route('admin.bookings.update', $booking), $this->editData($booking, ['status' => 'confirmed']))->assertSessionHasErrors('booking');
        $this->assertSame('unpaid', $booking->fresh()->payment_status);
        $this->assertSame('pending_confirmation', $booking->fresh()->status);
        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_paid_booking_preserves_customer_price_and_history_and_requires_refund_before_cancellation(): void
    {
        $booking = $this->booking(['travel_fee' => 0]);
        $this->record($booking);
        $other = $this->booking();
        $this->put(route('admin.bookings.update', $booking), $this->editData($booking, ['customer_id' => $other->customer_id]))->assertSessionHasErrors('booking');
        $this->put(route('admin.bookings.update', $booking), $this->editData($booking, ['service_id' => $other->service_id]))->assertSessionHasErrors('booking');
        $this->put(route('admin.bookings.update', $booking), $this->editData($booking, ['status' => 'cancelled']))->assertSessionHasErrors('booking');
        $this->delete(route('admin.bookings.destroy', $booking))->assertSessionHasErrors('booking');
        $this->assertSame($booking->customer_id, $booking->fresh()->customer_id);
        $this->record($booking, ['kind' => 'refund', 'amount' => 30000, 'notes' => 'Uang DP telah dikembalikan penuh.']);
        app(BookingService::class)->cancel($booking);
        $this->assertSame('cancelled', $booking->status);
        $this->assertSame('refunded', $booking->fresh()->payment_status);
        $this->assertDatabaseCount('booking_payments', 2);
    }

    public function test_partial_historical_payment_requires_reviewed_price_and_only_new_settlement_enters_cash_report(): void
    {
        $booking = $this->booking(['finance_managed' => false, 'payment_status' => 'partial', 'service_price_snapshot' => null, 'travel_fee' => 0]);
        $data = ['revision' => 1, 'credit' => 50000, 'reference' => 'Bukti DP historis diperiksa.', 'confirmed' => 1];
        $this->post(route('admin.bookings.finance.activate', $booking), $data)->assertSessionHasErrors('finance');
        $this->post(route('admin.bookings.finance.activate', $booking), $data + ['legacy_service_price' => 120000])->assertSessionHasNoErrors();
        $this->record($booking, ['amount' => 70000]);
        $this->assertSame('paid', $booking->fresh()->payment_status);
        $summary = $this->get(route('admin.reports.index'))->assertOk()->viewData('summary');
        $this->assertEquals(70000, $summary['payments']);
        $this->assertEquals(0, $summary['balances']);
        $this->assertSame(5000000, (int) $booking->fresh()->legacy_credit_cents);
    }

    public function test_refunds_are_negative_in_cash_csv_and_void_entries_are_excluded(): void
    {
        $booking = $this->booking();
        $payment = $this->record($booking);
        $this->record($booking, ['kind' => 'refund', 'amount' => 10000, 'notes' => 'Kelebihan DP dikembalikan.']);
        $csv = $this->get(route('admin.reports.export', ['type' => 'payments']))->assertOk()->streamedContent();
        $this->assertStringContainsString('"-10000"', $csv);
        $this->assertStringNotContainsString("\"'-10000\"", $csv);
        $booking->customer->update(['name' => '=HYPERLINK("https://invalid.example")']);
        $csv = $this->get(route('admin.reports.export', ['type' => 'payments']))->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertEquals(20000, $this->get(route('admin.reports.index'))->viewData('summary')['payments']);
        $this->assertSame('Uji Transaksi', $payment->fresh()->snapshot['customer']['name']);
    }

    public function test_non_financial_roles_cannot_read_or_write_journal_or_download_evidence(): void
    {
        $booking = $this->booking();
        $payment = $this->record($booking);
        foreach (['sales', 'therapist'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active']);
            $this->actingAs($user)->get(route('admin.bookings.finance', $booking))->assertForbidden();
            $this->get(route('admin.bookings.finance.receipt', [$booking, $payment]))->assertForbidden();
            $this->get(route('admin.bookings.finance.proof', [$booking, $payment]))->assertForbidden();
            $this->post(route('admin.bookings.finance.record', $booking), $this->data($booking))->assertForbidden();
            $this->post(route('admin.bookings.finance.void', [$booking, $payment]), ['revision' => 2, 'reason' => 'Tidak berwenang', 'confirmed' => 1])->assertForbidden();
            $this->get(route('admin.reports.show', ['type' => 'payments']))->assertForbidden();
        }
        $this->assertDatabaseCount('booking_payments', 1);
    }

    public function test_dashboard_custom_period_includes_last_day_and_trend_beyond_one_month(): void
    {
        $this->booking(['booking_date' => '2026-09-01']);
        $this->booking(['booking_date' => '2026-10-31']);
        $response = $this->get(route('admin.dashboard', ['range' => 'custom', 'start_date' => '2026-09-01', 'end_date' => '2026-10-31']))->assertOk();
        $this->assertSame(2, $response->viewData('bookingsInRange'));
        $this->assertSame(2, array_sum(array_column($response->viewData('trend'), 'bookings')));
        $this->get(route('admin.reports.index', ['start_date' => '2026-11-01', 'end_date' => '2026-10-31']))->assertSessionHasErrors('start_date');
        $this->get(route('admin.reports.index', ['start_date' => 'invalid']))->assertSessionHasErrors('start_date');
    }
}
