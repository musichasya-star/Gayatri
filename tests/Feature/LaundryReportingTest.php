<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\LaundryOrder;
use App\Models\LaundryPayment;
use App\Models\LaundryPublicRequest;
use App\Models\LaundryTariff;
use App\Models\RentalOrder;
use App\Models\TherapistFee;
use App\Models\User;
use App\Services\CRM\LaundryReportService;
use App\Services\CRM\LaundrySettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class LaundryReportingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private LaundryTariff $kg;

    private LaundryTariff $piece;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-31 23:59:59', 'Asia/Jakarta'));
        Queue::fake();
        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($this->admin);
        $this->customer = Customer::create(['name' => 'Bunda Laporan', 'whatsapp_number' => '6280000021000', 'status' => 'lead']);
        $this->kg = LaundryTariff::create(['code' => 'RPT-KG', 'name' => 'Cuci Bayi', 'unit' => 'kg', 'audience' => 'baby', 'price' => 7500, 'minimum_milli' => 3000, 'increment_milli' => 500, 'duration_hours' => 24, 'is_active' => true]);
        $this->piece = LaundryTariff::create(['code' => 'RPT-PC', 'name' => 'Selimut Bayi', 'unit' => 'piece', 'audience' => 'baby', 'price' => 15000, 'minimum_milli' => 1000, 'increment_milli' => 1000, 'duration_hours' => 48, 'is_active' => true]);
    }

    private function order(array $changes = []): LaundryOrder
    {
        $order = LaundryOrder::create(array_replace(['entry_key' => (string) Str::uuid(), 'input_hash' => hash('sha256', Str::random()), 'code' => 'RPT-LND-'.Str::upper(Str::random(8)), 'customer_id' => $this->customer->id, 'created_by' => $this->admin->id, 'customer_snapshot' => $this->customer->only(['id', 'name', 'whatsapp_number', 'phone']), 'outlet_snapshot' => app(LaundrySettings::class)->get(), 'received_at' => '2026-10-31 09:00:00', 'estimated_ready_at' => '2026-11-01 09:00:00', 'intake_mode' => 'walkin', 'status' => 'approved', 'workflow_status' => 'pending', 'approved_at' => '2026-10-31 09:30:00', 'approved_name' => $this->customer->name, 'approval_channel' => 'outlet', 'approval_reference' => 'Hasil timbang dan ongkir disetujui.', 'actual_total' => 52500, 'pickup_fee' => 5000, 'delivery_fee' => 7500, 'revision' => 1, 'is_demo' => false], $changes));
        foreach ([[$this->kg, 2310, 3000, 12, 22500], [$this->piece, 2000, 2000, 2, 30000]] as [$tariff,$quantity,$billable,$pieces,$amount]) {
            $order->items()->create(['laundry_tariff_id' => $tariff->id, 'bag_code' => $order->code.'-'.$tariff->id, 'bag_label' => $tariff->name, 'tariff_snapshot' => $tariff->snapshot(), 'received_pieces' => $pieces, 'condition_notes' => 'Jumlah dan kondisi diperiksa.', 'actual_milli' => $quantity, 'actual_billable_milli' => $billable, 'actual_pieces' => $pieces, 'actual_amount' => $amount]);
        }

        return $order;
    }

    private function payment(LaundryOrder $order, int $amount = 15000, array $changes = []): LaundryPayment
    {
        return $order->payments()->create(array_replace(['entry_key' => (string) Str::uuid(), 'input_hash' => hash('sha256', Str::random()), 'amount' => $amount, 'method' => 'transfer', 'paid_at' => '2026-10-31 10:00:00', 'payer_name' => 'Bunda Pembayar', 'reference' => 'RPT-TRF', 'created_by' => $this->admin->id], $changes));
    }

    private function entry(array $changes = []): LaundryPublicRequest
    {
        return LaundryPublicRequest::create(array_replace(['public_id' => (string) Str::uuid(), 'entry_key' => (string) Str::uuid(), 'input_hash' => str_repeat('a', 64), 'name' => 'Pemesan portal', 'whatsapp_number' => '6280000021001', 'fulfilment' => 'dropoff', 'items' => [], 'estimate' => [], 'outlet_snapshot' => [], 'status' => 'pending', 'portal_expires_at' => now()->addDays(30), 'is_demo' => false], $changes));
    }

    private function report(string $start = '2026-10-01', string $end = '2026-10-31', bool $demo = false): array
    {
        return app(LaundryReportService::class)->report(Carbon::parse($start), Carbon::parse($end), $demo);
    }

    private function csv(string $type, array $filters = []): array
    {
        $response = $this->get(route('admin.laundry.reports.export', array_replace(['type' => $type, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31'], $filters)))->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $file = fopen('php://temp', 'w+');
        fwrite($file, substr($content, 3));
        rewind($file);
        $rows = [];
        while (($row = fgetcsv($file, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($file);

        return $rows;
    }

    public function test_report_separates_charges_transport_paid_receivables_and_physical_units(): void
    {
        $order = $this->order();
        $this->payment($order);
        $entry = $this->entry(['laundry_order_id' => $order->id]);
        $order->paymentProofs()->create(['laundry_public_request_id' => $entry->id, 'entry_key' => (string) Str::uuid(), 'input_hash' => str_repeat('b', 64), 'pricing_fingerprint' => str_repeat('c', 64), 'amount' => 10000, 'paid_at' => now(), 'payer_name' => 'Pengunggah', 'reference' => 'BELUM-DIPERIKSA', 'file_path' => 'unused.pdf', 'mime_type' => 'application/pdf', 'status' => 'pending']);
        $report = $this->report();
        $this->assertSame(1, $report['period']['orders']);
        $this->assertSame(52500, $report['period']['service']);
        $this->assertSame(5000, $report['period']['pickup']);
        $this->assertSame(7500, $report['period']['delivery']);
        $this->assertSame(65000, $report['period']['charges']);
        $this->assertSame(15000, $report['period']['paid']);
        $this->assertSame(50000, $report['current']['balance']);
        $this->assertSame(2310, $report['period']['kg_milli']);
        $this->assertSame(2, $report['period']['piece_count']);
        $this->assertSame(1, $report['current']['pending_proofs']);
        $this->assertSame(15000, $report['methods']['transfer']);
        $this->assertCount(2, $report['services']);
        $this->get(route('admin.laundry.dashboard'))->assertOk()->assertSee('Dashboard Laundry')->assertSee('Rp 65.000')->assertSee('2,310 kg')->assertSee('2 potong');
        $this->get(route('admin.laundry.reports'))->assertOk()->assertSee($order->code)->assertSee('Rp 50.000');
        $this->assertSame(0, Booking::count() + RentalOrder::count() + TherapistFee::count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_month_end_and_midnight_boundaries_use_received_and_paid_dates_independently(): void
    {
        $old = $this->order(['received_at' => '2026-09-30 23:59:59']);
        $first = $this->order(['received_at' => '2026-10-01 00:00:00']);
        $last = $this->order(['received_at' => '2026-10-31 23:59:59']);
        $future = $this->order(['received_at' => '2026-11-01 00:00:00']);
        $this->payment($old, 12000, ['paid_at' => '2026-10-01 00:00:00']);
        $this->payment($last, 13000, ['paid_at' => '2026-10-31 23:59:59']);
        $this->payment($first, 1000, ['paid_at' => '2026-09-30 23:59:59']);
        $this->payment($future, 2000, ['paid_at' => '2026-11-01 00:00:00']);
        $report = $this->report();
        $this->assertSame(2, $report['period']['orders']);
        $this->assertSame(130000, $report['period']['charges']);
        $this->assertSame(25000, $report['period']['paid']);
        $this->assertSame(12000, $report['days'][0]['paid']);
        $this->assertSame(13000, $report['days'][30]['paid']);
        $this->assertSame(1, $report['days'][0]['orders']);
        $this->assertSame(1, $report['days'][30]['orders']);
        $this->assertSame(4, $report['current']['receivable_orders']);
    }

    public function test_unapproved_cancelled_and_unweighed_values_are_not_final_charges(): void
    {
        $this->order(['status' => 'awaiting_approval', 'approved_at' => null]);
        $this->order(['status' => 'received', 'actual_total' => null, 'approved_at' => null]);
        $this->order(['status' => 'cancelled', 'workflow_status' => 'cancelled', 'approved_at' => null]);
        $report = $this->report();
        $this->assertSame(3, $report['period']['orders']);
        $this->assertSame(0, $report['period']['charges']);
        $this->assertSame(0, $report['current']['balance']);
        $this->assertSame(0, $report['period']['kg_milli']);
        $this->assertSame(0, $report['period']['piece_count']);
        $this->assertSame(1, $report['current']['awaiting']);
        $this->assertSame(1, $report['current']['unweighed']);
        $this->assertSame(2, $report['current']['open']);
        $this->assertSame([], $report['services']);
    }

    public function test_demo_records_excluded_from_all_sections_and_all_exports_by_default(): void
    {
        $demo = $this->order(['is_demo' => true, 'code' => 'DEMO-REPORT-ONLY', 'workflow_status' => 'ready']);
        $this->payment($demo, 65000);
        $this->entry(['is_demo' => true]);
        $report = $this->report();
        $this->assertSame(0, $report['period']['orders']);
        $this->assertSame(0, $report['period']['paid']);
        $this->assertSame(0, $report['current']['ready']);
        $this->assertSame(0, $report['current']['pending_requests']);
        foreach (['orders', 'payments', 'balances', 'corrections', 'services'] as $type) {
            $this->assertCount(1, $this->csv($type));
        }
        $this->get(route('admin.laundry.dashboard'))->assertOk()->assertDontSee('DEMO-REPORT-ONLY');
        $included = $this->report(demo: true);
        $this->assertSame(1, $included['period']['orders']);
        $this->assertSame(65000, $included['period']['paid']);
        $this->assertSame(1, $included['current']['ready']);
        $this->get(route('admin.laundry.reports', ['include_demo' => 1]))->assertOk()->assertSee('DEMO-REPORT-ONLY')->assertSee('DATA DEMO');
        $this->assertCount(2, $this->csv('orders', ['include_demo' => 1]));
    }

    public function test_current_attention_and_balances_include_old_orders_outside_period(): void
    {
        $old = $this->order(['received_at' => '2026-09-01', 'estimated_ready_at' => '2026-09-02', 'workflow_status' => 'washing']);
        $this->payment($old, 25000, ['paid_at' => '2026-09-01']);
        $this->order(['received_at' => '2026-09-01', 'estimated_ready_at' => '2026-09-02', 'workflow_status' => 'ready']);
        $done = $this->order(['received_at' => '2026-09-01', 'workflow_status' => 'handed_over', 'handed_over_at' => '2026-09-03']);
        $this->payment($done, 65000, ['paid_at' => '2026-09-03']);
        $report = $this->report('2026-10-10', '2026-10-12');
        $this->assertSame(0, $report['period']['orders']);
        $this->assertSame(0, $report['period']['paid']);
        $this->assertSame(105000, $report['current']['balance']);
        $this->assertSame(1, $report['current']['overdue']);
        $this->assertSame(1, $report['current']['ready']);
        $this->assertSame(2, $report['current']['open']);
        $this->get(route('admin.laundry.reports', ['tab' => 'balances', 'start_date' => '2026-10-10', 'end_date' => '2026-10-12']))->assertOk()->assertSee($old->code)->assertDontSee($done->code);
        $this->assertCount(3, $this->csv('balances', ['start_date' => '2026-10-10', 'end_date' => '2026-10-12']));
    }

    public function test_voided_payments_are_excluded_once_and_correction_period_uses_void_time(): void
    {
        $order = $this->order();
        $this->payment($order, 20000);
        $this->payment($order, 10000, ['paid_at' => '2026-09-20', 'voided_at' => '2026-10-15 08:00:00', 'void_reason' => 'Koreksi pencatatan lama.']);
        $laterVoid = $this->payment($order, 5000, ['voided_at' => '2026-11-01 09:00:00', 'void_reason' => 'Koreksi sesudah periode.']);
        $report = $this->report();
        $this->assertSame(20000, $report['period']['paid']);
        $this->assertSame(45000, $report['current']['balance']);
        $this->assertSame(1, $report['period']['corrections']);
        $this->assertSame(10000, $report['period']['correction_amount']);
        $cash = $this->csv('payments');
        $this->assertCount(2, $cash);
        $this->assertSame('20000', $cash[1][5]);
        $corrections = $this->csv('corrections');
        $this->assertCount(2, $corrections);
        $this->assertSame('10000', $corrections[1][5]);
        $this->assertSame('2026-10-15 08:00:00', $corrections[1][9]);
    }

    public function test_completion_follows_handover_time_not_intake_time(): void
    {
        $this->order(['received_at' => '2026-09-15', 'workflow_status' => 'handed_over', 'handed_over_at' => '2026-10-31 23:59:59']);
        $this->order(['received_at' => '2026-10-15', 'workflow_status' => 'handed_over', 'handed_over_at' => '2026-11-01 00:00:00']);
        $report = $this->report();
        $this->assertSame(1, $report['period']['orders']);
        $this->assertSame(1, $report['period']['completed']);
        $this->assertSame(1, $report['days'][30]['completed']);
    }

    public function test_multiple_payments_do_not_multiply_charges_service_volume_or_order_counts(): void
    {
        $order = $this->order();
        $this->payment($order, 10000);
        $this->payment($order, 15000);
        $this->payment($order, 20000, ['method' => 'cash']);
        $report = $this->report();
        $this->assertSame(1, $report['period']['orders']);
        $this->assertSame(65000, $report['period']['charges']);
        $this->assertSame(2310, $report['period']['kg_milli']);
        $this->assertSame(45000, $report['period']['paid']);
        $this->assertSame(20000, $report['current']['balance']);
        $this->assertSame(25000, $report['methods']['transfer']);
        $this->assertSame(20000, $report['methods']['cash']);
    }

    public function test_service_ranking_uses_snapshot_name_and_quantities_not_current_catalogue(): void
    {
        $order = $this->order();
        $this->kg->update(['name' => 'NAMA BARU', 'price' => 100000, 'is_active' => false]);
        $lines = $this->report()['services'];
        $this->assertSame('Selimut Bayi', $lines[0]['name']);
        $this->assertSame(30000, $lines[0]['service_total']);
        $this->assertSame('Cuci Bayi', $lines[1]['name']);
        $this->assertSame(2310, $lines[1]['quantity_milli']);
        $this->assertSame(3000, $lines[1]['billable_milli']);
        $this->assertSame(12, $lines[1]['pieces']);
        $this->get(route('admin.laundry.reports', ['tab' => 'services']))->assertOk()->assertSee('Cuci Bayi')->assertDontSee('NAMA BARU');
    }

    public function test_all_report_tabs_and_csv_types_reconcile_and_are_private(): void
    {
        $order = $this->order();
        $this->payment($order);
        foreach (['orders', 'payments', 'services', 'daily', 'balances', 'corrections'] as $tab) {
            $response = $this->get(route('admin.laundry.reports', ['tab' => $tab]))->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->csv($tab);
        }
        $orders = $this->csv('orders');
        $this->assertCount(2, $orders);
        $this->assertSame('52500', $orders[1][7]);
        $this->assertSame('5000', $orders[1][8]);
        $this->assertSame('7500', $orders[1][9]);
        $this->assertSame('65000', $orders[1][10]);
        $this->assertSame('15000', $orders[1][11]);
        $this->assertSame('50000', $orders[1][12]);
        $daily = $this->csv('daily');
        $this->assertCount(32, $daily);
        $this->assertSame('2026-10-31', $daily[31][0]);
        $this->assertSame('65000', $daily[31][6]);
        $this->assertSame('15000', $daily[31][7]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'laundry.report.export']);
    }

    public function test_csv_handles_formula_injection_quotes_utf8_and_omits_private_access_data(): void
    {
        $name = '=HYPERLINK("https://invalid.test","Bunda, bayi")';
        $order = $this->order(['customer_snapshot' => ['name' => $name, 'whatsapp_number' => '628111222000', 'portal_token_secret' => 'SECRET-NEVER-EXPORT']]);
        $this->payment($order, 10000, ['payer_name' => '+RUMUS', 'reference' => "\t=1+1"]);
        $orders = $this->csv('orders');
        $this->assertSame("'".$name, $orders[1][1]);
        $payments = $this->csv('payments');
        $this->assertSame("'+RUMUS", $payments[1][6]);
        $this->assertSame("'\t=1+1", $payments[1][7]);
        $this->assertStringNotContainsString('SECRET-NEVER-EXPORT', json_encode($orders));
        $this->assertStringNotContainsString('628111222000', json_encode($orders));
    }

    public function test_filters_validate_ranges_formats_demo_flags_and_report_types(): void
    {
        foreach ([['start_date' => '2026-10-31', 'end_date' => '2026-10-01'], ['end_date' => '2026-11-01'], ['start_date' => '2025-10-30'], ['start_date' => '2026-02-30'], ['include_demo' => 'yes'], ['tab' => 'unknown']] as $filters) {
            $this->getJson(route('admin.laundry.reports', $filters))->assertUnprocessable();
        }
        $this->getJson(route('admin.laundry.reports.export', ['type' => 'unknown']))->assertUnprocessable();
        $this->getJson(route('admin.laundry.reports.export'))->assertUnprocessable();
        $this->get(route('admin.laundry.reports', ['start_date' => '2025-10-31', 'end_date' => '2026-10-31', 'tab' => 'daily']))->assertOk();
    }

    public function test_empty_report_and_single_day_default_have_zero_values_and_no_side_effects(): void
    {
        $report = $this->report('2026-10-31', '2026-10-31');
        $this->assertCount(1, $report['days']);
        $this->assertSame(0, $report['period']['paid']);
        $this->assertSame(0, $report['current']['balance']);
        $this->get(route('admin.laundry.dashboard'))->assertOk()->assertSee('Belum ada tagihan disetujui');
        $this->assertDatabaseCount('laundry_orders', 0);
        $this->assertDatabaseCount('laundry_payments', 0);
        $this->assertDatabaseCount('laundry_documents', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_dashboard_reports_and_exports_are_restricted_to_active_laundry_admins(): void
    {
        foreach (['sales', 'therapist'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active']);
            foreach (['admin.laundry.dashboard', 'admin.laundry.reports', 'admin.laundry.reports.export'] as $route) {
                $this->actingAs($user)->get(route($route, ['type' => 'orders']))->assertForbidden();
            }
        }
        foreach (['owner', 'manager', 'admin'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active']);
            $this->actingAs($user)->get(route('admin.laundry.dashboard'))->assertOk();
        }
        $this->admin->update(['status' => 'inactive']);
        $this->actingAs($this->admin)->get(route('admin.laundry.reports'))->assertRedirect(route('login'));
        $this->app['auth']->forgetGuards();
        $this->get(route('admin.laundry.dashboard'))->assertRedirect(route('login'));
    }

    public function test_csv_paginates_large_order_set_without_losing_or_duplicating_rows(): void
    {
        $order = $this->order();
        $rows = [];
        foreach (range(1, 205) as $index) {
            $row = $order->getAttributes();
            unset($row['id']);
            $row['entry_key'] = (string) Str::uuid();
            $row['code'] = 'BULK-LND-'.$index;
            $rows[] = $row;
        }
        DB::table('laundry_orders')->insert($rows);
        $csv = $this->csv('orders');
        $this->assertCount(207, $csv);
        $codes = array_column(array_slice($csv, 1), 0);
        $this->assertCount(206, array_unique($codes));
        $this->assertContains('BULK-LND-205', $codes);
    }
}
