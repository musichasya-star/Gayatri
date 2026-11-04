<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\RentalCareRecord;
use App\Models\RentalOrder;
use App\Models\RentalProduct;
use App\Models\RentalUnit;
use App\Models\User;
use App\Services\CRM\RentalReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class RentalReportingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private RentalProduct $product;

    private RentalUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-28 10:00:00', 'Asia/Jakarta'));
        Queue::fake();
        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($this->admin);
        $this->customer = Customer::create(['name' => 'Pelanggan Laporan', 'whatsapp_number' => '628111222333', 'status' => 'lead']);
        $this->product = RentalProduct::create(['sku' => 'RPT-ST', 'name' => 'Stroller Laporan', 'category' => 'Stroller', 'daily_rate' => 25000, 'deposit' => 100000, 'is_active' => true]);
        $this->unit = RentalUnit::create(['rental_product_id' => $this->product->id, 'code' => 'RPT-001', 'status' => 'ready', 'created_at' => '2026-09-01 00:00:00']);
        $this->unit->forceFill(['created_at' => '2026-09-01 00:00:00'])->saveQuietly();
    }

    private function order(array $changes = [], ?RentalUnit $unit = null): RentalOrder
    {
        $unit ??= $this->unit;
        $order = RentalOrder::create(array_replace(['code' => 'REPORT-'.Str::upper(Str::random(8)), 'quote_id' => (string) Str::uuid(), 'request_session_hash' => str_repeat('a', 64), 'customer_id' => $this->customer->id, 'customer_snapshot' => ['name' => 'Pelanggan Laporan', 'whatsapp_number' => '628111222333'],
            'source' => 'admin', 'status' => 'reserved', 'start_date' => '2026-10-05', 'end_date' => '2026-10-07', 'period' => 'day', 'period_count' => 3, 'fulfilment' => 'self', 'rental_subtotal' => 75000, 'deposit_total' => 100000, 'delivery_fee' => 10000, 'collection_fee' => 5000, 'pricing_snapshot' => ['route' => null]], $changes));
        $order->items()->create(['rental_unit_id' => $unit->id, 'product_name' => $this->product->name, 'unit_code' => $unit->code, 'rental_price' => 75000, 'deposit' => 100000, 'rental_end_date' => $order->end_date, 'reserved_until' => $order->end_date->copy()->addDays(2)]);

        return $order;
    }

    private function payment(RentalOrder $order, int|float $amount, string $kind = 'payment', string $date = '2026-10-06 12:00:00'): void
    {
        $payment = $order->payments()->create(['entry_key' => (string) Str::uuid(), 'kind' => $kind, 'amount' => $amount, 'method' => 'transfer', 'reference' => 'LAPORAN-UJI']);
        $payment->forceFill(['created_at' => $date])->saveQuietly();
    }

    private function report(string $start = '2026-10-01', string $end = '2026-10-28', bool $includeDemo = false): array
    {
        return app(RentalReportService::class)->report(Carbon::parse($start)->startOfDay(), Carbon::parse($end)->endOfDay(), $includeDemo);
    }

    private function csv(string $type, array $filters = []): array
    {
        $response = $this->get(route('admin.rentals.reports.export', array_replace(['type' => $type, 'start_date' => '2026-10-01', 'end_date' => '2026-10-28'], $filters)))->assertOk();
        $file = fopen('php://temp', 'w+');
        fwrite($file, substr($response->streamedContent(), 3));
        rewind($file);
        $rows = [];
        while (($row = fgetcsv($file, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        } fclose($file);

        return $rows;
    }

    public function test_report_separates_service_charges_cash_deposits_and_unverified_proofs(): void
    {
        $order = $this->order();
        $this->payment($order, 195000);
        $order->items()->update(['late_fee' => 2000, 'damage_fee' => 3000]);
        $order->paymentProofs()->create(['entry_key' => (string) Str::uuid(), 'kind' => 'settlement', 'amount' => 10000, 'payer_name' => 'Pengunggah', 'payment_date' => '2026-10-06', 'file_path' => 'unused.jpg', 'file_mime' => 'image/jpeg', 'file_hash' => str_repeat('b', 64), 'file_size' => 100, 'status' => 'pending']);
        $report = $this->report();
        $this->assertSame(19500000, $report['period']['paid']);
        $this->assertSame(9500000, $report['period']['charges']);
        $this->assertSame(10000000, $report['current']['deposit_held']);
        $this->assertSame(0, $report['current']['balance']);
        $this->assertSame(1, $report['current']['pending_proofs']);
        $this->get(route('admin.rentals.dashboard'))->assertOk()->assertSee('Dashboard Rental')->assertSee('Deposit ditahan pada sewa aktif');
        $this->get(route('admin.rentals.reports'))->assertOk()->assertSee($order->code)->assertSee('195.000');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_cancelled_completed_pending_and_partial_payment_figures_reconcile(): void
    {
        $cancelled = $this->order(['status' => 'cancelled', 'cancellation_fee' => 20000]);
        $this->payment($cancelled, 50000);
        $this->payment($cancelled, 10000, 'refund');
        $completed = $this->order(['status' => 'completed']);
        $this->payment($completed, 190000);
        $partial = $this->order();
        $this->payment($partial, 20000);
        $this->order(['status' => 'pending', 'hold_expires_at' => now()->subHour()]);
        $report = $this->report();
        $this->assertSame(4, $report['period']['orders']);
        $this->assertSame(1, $report['period']['expired']);
        $this->assertSame(20000000, $report['period']['charges']);
        $this->assertSame(17000000, $report['current']['balance']);
        $this->assertSame(12000000, $report['current']['refundable']);
        $this->assertSame(0, $report['current']['deposit_held']);
        $completed->customerRequests()->create(['entry_key' => (string) Str::uuid(), 'kind' => 'refund', 'reason' => 'Permintaan deposit uji', 'estimate' => ['received' => 190000, 'fee' => 0, 'refund' => 100000], 'status' => 'approved', 'approved_amount' => 100000]);
        $this->assertSame(10000000, $this->report()['current']['approved_refunds']);
        $this->assertCount(3, $report['attention']);
    }

    public function test_date_filters_use_ledger_recording_dates_and_current_balances_remain_current(): void
    {
        $order = $this->order(['start_date' => '2026-09-29', 'end_date' => '2026-10-01']);
        $this->payment($order, 50000, 'payment', '2026-09-30 23:59:59');
        $this->payment($order, 140000, 'payment', '2026-10-01 00:00:00');
        $report = $this->report();
        $this->assertSame(14000000, $report['period']['paid']);
        $this->assertSame(0, $report['period']['orders']);
        $this->assertSame(0, $report['period']['charges']);
        $this->assertSame(10000000, $report['current']['deposit_held']);
        $empty = $this->report('2026-10-15', '2026-10-20');
        $this->assertSame(0, $empty['period']['paid']);
        $this->assertSame(10000000, $empty['current']['deposit_held']);
        $this->assertCount(2, $this->report('2026-09-01', '2026-10-28')['months']);
    }

    public function test_demo_order_unit_and_care_data_are_excluded_by_default_and_can_be_included(): void
    {
        $real = $this->order();
        $this->payment($real, 50000);
        $demoUnit = RentalUnit::create(['rental_product_id' => $this->product->id, 'code' => 'DEMO-RPT', 'is_demo' => true, 'status' => 'ready', 'created_at' => '2026-09-01']);
        $demoUnit->forceFill(['created_at' => '2026-09-01'])->saveQuietly();
        $demo = $this->order(['is_demo' => true], $demoUnit);
        $this->payment($demo, 90000);
        foreach ([[$this->unit, 10000, null], [$demoUnit, 5000, null], [$this->unit, 2000, $demo->items()->first()->id]] as [$unit,$cost,$item]) {
            RentalCareRecord::create(['rental_unit_id' => $unit->id, 'rental_item_id' => $item, 'entry_key' => (string) Str::uuid(), 'kind' => 'cleaning', 'status_after' => 'ready', 'notes' => 'Biaya pengujian', 'cost' => $cost, 'performed_at' => '2026-10-06']);
        }
        $report = $this->report();
        $this->assertSame(5000000, $report['period']['paid']);
        $this->assertSame(1000000, $report['period']['care']);
        $this->assertSame(1, (int) $report['unit_counts']['ready']);
        $all = $this->report(includeDemo: true);
        $this->assertSame(14000000, $all['period']['paid']);
        $this->assertSame(1700000, $all['period']['care']);
        $this->get(route('admin.rentals.reports'))->assertOk()->assertDontSee($demo->code);
        $this->get(route('admin.rentals.reports', ['include_demo' => 1]))->assertOk()->assertSee($demo->code)->assertSee('Data demo disertakan');
    }

    public function test_usage_clips_actual_intervals_to_period_and_merges_overlap_per_physical_unit(): void
    {
        $first = $this->order(['status' => 'completed', 'start_date' => '2026-09-29', 'dispatched_at' => '2026-10-02 10:00:00']);
        $first->items()->update(['returned_at' => '2026-10-04 23:00:00']);
        $second = $this->order(['status' => 'completed', 'start_date' => '2026-10-04', 'dispatched_at' => '2026-10-04 08:00:00']);
        $second->items()->update(['returned_at' => '2026-10-06 09:00:00']);
        $product = $this->report('2026-10-01', '2026-10-10')['products'][0];
        $this->assertSame(5, $product['used_days']);
        $this->assertSame(10, $product['capacity_days']);
        $this->assertSame(50.0, $product['utilization']);
        $this->assertSame(1, $product['rented_units']);
        $this->assertSame(7500000, $product['rental']);
    }

    public function test_product_totals_use_saved_item_prices_and_ignore_cancelled_reservations(): void
    {
        $order = $this->order(['rental_subtotal' => 125000]);
        $order->items()->update(['rental_price' => 125000, 'late_fee' => 5000, 'damage_fee' => 10000]);
        $this->order(['status' => 'cancelled', 'cancellation_fee' => 20000]);
        $this->product->update(['daily_rate' => 999999, 'name' => 'Stroller Nama Baru']);
        $p = $this->report()['products'][0];
        $this->assertSame(12500000, $p['rental']);
        $this->assertSame(500000, $p['late_fee']);
        $this->assertSame(1000000, $p['damage_fee']);
        $this->assertSame(1, $p['rented_units']);
        $this->assertSame(0, $p['used_days']);
    }

    public function test_all_csv_exports_are_authorized_complete_and_never_include_private_tokens(): void
    {
        $order = $this->order(['portal_token_secret' => str_repeat('c', 64)]);
        $this->payment($order, 50000);
        foreach (['orders', 'cash', 'care', 'products', 'monthly', 'balances'] as $type) {
            $response = $this->get(route('admin.rentals.reports.export', ['type' => $type]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
            $content = $response->streamedContent();
            $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
            $this->assertStringNotContainsString(str_repeat('c', 64), $content);
            $this->assertStringNotContainsString('628111222333', $content);
        }
        $this->assertDatabaseHas('audit_logs', ['action' => 'rental.report.export']);
        $this->assertCount(2, $this->csv('orders'));
    }

    public function test_csv_neutralizes_formulas_and_preserves_multiline_values_and_negative_totals(): void
    {
        $name = " =HYPERLINK(\"https://example.invalid\")\nBaris kedua";
        $order = $this->order(['status' => 'cancelled', 'customer_snapshot' => ['name' => $name]]);
        $this->payment($order, 50000, 'payment', '2026-09-30');
        $this->payment($order, 50000, 'refund', '2026-10-01');
        $rows = $this->csv('orders');
        $this->assertSame("'".$name, $rows[1][1]);
        $monthly = $this->csv('monthly');
        $this->assertSame('-50000', $monthly[1][3]);
        $order->payments()->where('kind', 'refund')->update(['reference' => '@SUM(1,2)']);
        $cash = $this->csv('cash');
        $this->assertSame("'@SUM(1,2)", $cash[1][6]);
    }

    public function test_export_has_no_screen_pagination_limit(): void
    {
        foreach (range(1, 31) as $i) {
            $this->order();
        }
        $this->get(route('admin.rentals.reports'))->assertOk()->assertViewHas('orders', fn ($rows) => $rows->total() === 31 && $rows->count() === 20);
        $this->assertCount(32, $this->csv('orders'));
    }

    public function test_report_dates_export_types_and_roles_are_enforced(): void
    {
        foreach ([['start_date' => '2026-10-29'], ['start_date' => '2025-01-01'], ['end_date' => '2026-10-29'], ['start_date' => 'invalid'], ['include_demo' => 'hack']] as $filters) {
            $this->getJson(route('admin.rentals.reports', $filters))->assertUnprocessable();
        }
        $this->getJson(route('admin.rentals.reports.export', ['type' => 'secrets']))->assertUnprocessable();
        foreach (['sales', 'therapist'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'active']));
            foreach (['dashboard', 'reports', 'reports.export'] as $route) {
                $this->get(route('admin.rentals.'.$route, ['type' => 'orders']))->assertForbidden();
            }
        }
        auth()->logout();
        $this->get(route('admin.rentals.dashboard'))->assertRedirect(route('login'));
    }

    public function test_no_data_period_renders_and_pending_requests_are_not_included_in_service_value(): void
    {
        $this->order(['status' => 'pending']);
        $r = $this->report('2026-10-28', '2026-10-28');
        $this->assertSame(0, $r['period']['charges']);
        $this->assertSame(0, $r['current']['balance']);
        $this->get(route('admin.rentals.dashboard', ['start_date' => '2026-10-28', 'end_date' => '2026-10-28']))->assertOk();
    }

    public function test_public_checkout_cannot_hide_real_transactions_or_set_private_whatsapp_destination(): void
    {
        $input = ['items' => [['product_id' => $this->product->id, 'quantity' => 1]], 'start_date' => '2026-10-28', 'end_date' => '2026-10-28', 'period' => 'day', 'fulfilment' => 'self', 'name' => 'Penyewa Publik', 'whatsapp_number' => '08111222333', 'terms_accepted' => 1, 'is_demo' => 1, 'notification_phone' => '628999000111', 'notification_verified_at' => now()->toIso8601String()];
        $input['quote_token'] = $this->postJson(route('rental.quote'), $input)->assertOk()->json('token');
        $this->post(route('rental.store'), $input)->assertRedirect()->assertSessionHasNoErrors();
        $order = RentalOrder::latest('id')->firstOrFail();
        $this->assertFalse($order->is_demo);
        $this->assertNull($order->notification_phone);
        $this->assertNull($order->notification_verified_at);
        $input['start_date'] = $input['end_date'] = '2026-11-10';
        $input['customer_id'] = $this->customer->id;
        $input['quote_token'] = $this->postJson(route('rental.quote'), $input)->assertOk()->json('token');
        $this->post(route('admin.rentals.orders.store'), $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(RentalOrder::latest('id')->firstOrFail()->is_demo);
    }

    public function test_start_and_end_calendar_dates_are_inclusive_for_totals_products_screen_and_export(): void
    {
        $this->order(['start_date' => '2026-09-30']);
        $first = $this->order(['start_date' => '2026-10-01']);
        $last = $this->order(['start_date' => '2026-10-28']);
        $this->order(['start_date' => '2026-10-29']);
        $report = $this->report();
        $this->assertSame(2, $report['period']['orders']);
        $this->assertSame(18000000, $report['period']['charges']);
        $this->assertSame(2, $report['products'][0]['rented_units']);
        $this->get(route('admin.rentals.reports'))->assertOk()->assertSee($first->code)->assertSee($last->code)->assertViewHas('orders', fn ($orders) => $orders->total() === 2);
        $this->assertCount(3, $this->csv('orders'));
        $this->assertSame(1, $this->report('2026-10-28', '2026-10-28')['period']['orders']);
    }

    public function test_decimal_ledger_amounts_are_preserved_in_report_and_csv(): void
    {
        $order = $this->order(['status' => 'completed', 'rental_subtotal' => 75000.25, 'delivery_fee' => 0, 'collection_fee' => 0]);
        $this->payment($order, 175000.50);
        $this->payment($order, 100000.25, 'refund');
        $r = $this->report();
        $this->assertSame(7500025, $r['period']['net']);
        $this->assertSame(7500025, $r['period']['charges']);
        $this->assertSame(0, $r['current']['refundable']);
        $csv = $this->csv('monthly');
        $this->assertSame('75000.25', $csv[1][3]);
    }
}
