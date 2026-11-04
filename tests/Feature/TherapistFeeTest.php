<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\AvailabilitySlot;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Therapist;
use App\Models\TherapistFee;
use App\Models\User;
use App\Services\CRM\BookingService;
use App\Services\CRM\TherapistFeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TherapistFeeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Service $service;

    private Therapist $therapist;

    private Customer $customer;

    private TherapistFeeService $fees;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 29)->setTime(10, 0));
        Http::preventStrayRequests();
        Queue::fake();
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($this->admin);
        $this->service = Service::create(['name' => 'Treatment Fee', 'duration_minutes' => 60, 'price' => 250000, 'is_active' => true]);
        $this->therapist = Therapist::create(['name' => 'Terapis Fee', 'status' => 'active']);
        $this->customer = Customer::create(['name' => 'Pelanggan Fee', 'status' => 'lead']);
        $this->fees = app(TherapistFeeService::class);
        $this->configure();
    }

    private function configure(array $changes = []): void
    {
        $this->fees->saveSettings(array_replace(['enabled' => true, 'start_date' => '2026-10-01', 'spa_percent' => '30', 'lactation_percent' => '40'], $changes), $this->admin, Request::create('/'));
    }

    private function booking(array $changes = []): Booking
    {
        return Booking::create(array_replace(['customer_id' => $this->customer->id, 'service_id' => $this->service->id, 'therapist_id' => $this->therapist->id,
            'booking_code' => 'FEE-'.str_pad((string) (Booking::count() + 1), 5, '0', STR_PAD_LEFT), 'booking_date' => '2026-10-29', 'start_time' => '10:00', 'end_time' => '11:00',
            'status' => 'completed', 'payment_status' => 'paid', 'service_price_snapshot' => 250000, 'promo_discount' => 25000], $changes));
    }

    private function sync(Booking $booking): ?TherapistFee
    {
        return $this->fees->sync($booking, $this->admin, Request::create('/'));
    }

    private function filters(array $changes = []): array
    {
        return array_replace(['start_date' => '2026-10-01', 'end_date' => '2026-10-29'], $changes);
    }

    private function expectValidation(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected validation failure.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    public function test_fee_uses_net_treatment_and_addons_without_home_travel_and_rounds_per_booking(): void
    {
        $booking = $this->booking(['service_mode' => 'home', 'travel_fee' => 100000]);
        $booking->addOns()->create(['name' => 'Tambahan', 'duration_minutes' => 15, 'price' => 50000]);
        $fee = $this->sync($booking);
        $this->assertSame(27500000, $fee->base_cents);
        $this->assertSame(8250000, $fee->fee_cents);
        $this->assertSame(10000000, $fee->snapshot['travel_excluded_cents']);
        $this->therapist->forceFill(['fee_spa_percent' => '50'])->save();
        $rounded = $this->sync($this->booking(['service_price_snapshot' => '101.00', 'promo_discount' => 0]));
        $this->assertSame(5100, $rounded->fee_cents);
        $zero = $this->sync($this->booking(['promo_discount' => 500000]));
        $this->assertSame(0, $zero->base_cents);
        $this->assertSame(0, $zero->fee_cents);
    }

    public function test_only_completed_paid_assigned_nonfuture_bookings_after_policy_start_earn_fees(): void
    {
        foreach ([['status' => 'confirmed'], ['status' => 'cancelled'], ['payment_status' => 'unpaid'], ['payment_status' => 'partial'], ['payment_status' => 'refunded'], ['therapist_id' => null], ['service_id' => null], ['booking_date' => '2026-10-30'], ['booking_date' => '2026-09-30']] as $change) {
            $this->assertNull($this->sync($this->booking($change)));
        }
        $this->assertNotNull($this->sync($this->booking(['booking_date' => '2026-10-01'])));
        $this->assertSame(1, TherapistFee::count());
    }

    public function test_explicit_zero_override_differs_from_empty_and_each_group_has_its_own_rate(): void
    {
        $this->put(route('admin.therapist-fees.therapist.update', $this->therapist), ['fee_spa_percent' => 0, 'fee_lactation_percent' => '45.25', 'name' => 'Must not change'])->assertSessionHasNoErrors();
        $this->assertSame('Terapis Fee', $this->therapist->fresh()->name);
        $this->assertSame(0, $this->sync($this->booking())->fee_cents);
        $asi = Service::create(['name' => 'Konsultasi ASI Fee', 'service_group' => 'lactation', 'duration_minutes' => 60, 'price' => 250000, 'is_active' => true]);
        $this->assertSame(4525, $this->sync($this->booking(['service_id' => $asi->id]))->rate_bps);
        $this->put(route('admin.therapist-fees.therapist.update', $this->therapist), ['fee_spa_percent' => '', 'fee_lactation_percent' => ''])->assertSessionHasNoErrors();
        $this->assertNull($this->therapist->fresh()->fee_spa_percent);
        $this->assertSame(3000, $this->sync($this->booking())->rate_bps);
    }

    public function test_booking_service_records_after_addons_and_keeps_clinic_price_snapshot_on_later_edits(): void
    {
        $slot = AvailabilitySlot::create(['service_id' => $this->service->id, 'slot_date' => today(), 'start_time' => '10:00', 'end_time' => '11:00', 'capacity' => 10, 'booked_count' => 0, 'status' => 'available']);
        $addon = $this->service->addOns()->create(['addon_name' => 'Tambahan otomatis', 'duration_minutes' => 15, 'price_adjustment' => 50000, 'is_active' => true]);
        $data = ['customer_id' => $this->customer->id, 'service_id' => $this->service->id, 'therapist_id' => $this->therapist->id, 'availability_slot_id' => $slot->id,
            'booking_date' => today()->toDateString(), 'start_time' => '10:00', 'status' => 'completed', 'payment_status' => 'paid', 'addons' => [$addon->id]];
        $booking = app(BookingService::class)->create($data, $this->admin->id);
        $this->assertSame(9000000, $booking->therapistFee->fee_cents);
        $this->service->update(['price' => 999999]);
        $this->configure(['spa_percent' => '90']);
        app(BookingService::class)->update($booking, $data + ['notes' => 'Koreksi catatan saja']);
        $this->assertSame('250000.00', $booking->fresh()->service_price_snapshot);
        $this->assertSame(9000000, $booking->therapistFee()->sole()->fee_cents);
        $this->assertSame(1, TherapistFee::count());
        Http::assertNothingSent();
    }

    public function test_rate_is_frozen_but_financial_corrections_are_audited_and_new_therapist_uses_their_rate(): void
    {
        $booking = $this->booking();
        $fee = $this->sync($booking);
        $this->configure(['spa_percent' => '80']);
        $this->sync($booking);
        $this->sync($booking);
        $this->assertSame(1, TherapistFee::count());
        $this->assertSame(1, AuditLog::where('action', 'therapist_fee.accrued')->count());
        $booking->update(['promo_discount' => 50000]);
        $this->assertSame(6000000, $this->sync($booking)->fee_cents);
        $this->assertSame(3000, $fee->fresh()->rate_bps);
        $second = Therapist::create(['name' => 'Terapis Kedua', 'status' => 'active']);
        $second->forceFill(['fee_spa_percent' => 50])->save();
        $booking->update(['therapist_id' => $second->id]);
        $this->assertSame(10000000, $this->sync($booking)->fee_cents);
        $this->assertSame($second->id, $fee->fresh()->therapist_id);
        $this->assertSame(2, AuditLog::where('action', 'therapist_fee.corrected')->count());
    }

    public function test_disabling_preserves_records_but_refund_voids_fee_and_restoration_is_idempotent(): void
    {
        $booking = $this->booking();
        $fee = $this->sync($booking);
        $this->configure(['enabled' => false]);
        $this->assertNull($this->sync($this->booking()));
        $this->assertSame('accrued', $this->sync($booking)->status);
        $booking->update(['payment_status' => 'refunded']);
        $this->assertSame('void', $this->sync($booking)->status);
        $booking->update(['payment_status' => 'paid']);
        $this->assertSame('accrued', $this->sync($booking)->status);
        $this->assertSame($fee->id, $booking->therapistFee()->sole()->id);
    }

    public function test_legacy_history_requires_preview_acknowledgement_then_freezes_catalogue_price_once(): void
    {
        $booking = $this->booking(['service_price_snapshot' => null]);
        $this->assertNull($this->sync($booking));
        $response = $this->post(route('admin.therapist-fees.preview'), $this->filters())->assertOk()->assertSee('Katalog saat ini');
        $this->assertSame(0, TherapistFee::count());
        $token = $response->viewData('preview')['token'];
        $this->post(route('admin.therapist-fees.process'), ['token' => $token])->assertSessionHasErrors('acknowledged');
        $this->post(route('admin.therapist-fees.process'), ['token' => $token, 'acknowledged' => 1])->assertSessionHasNoErrors();
        $fee = $booking->therapistFee()->sole();
        $this->assertSame('legacy_catalog_confirmed', $fee->snapshot['price_source']);
        $this->assertSame('250000.00', $booking->fresh()->service_price_snapshot);
        $this->post(route('admin.therapist-fees.process'), ['token' => $token, 'acknowledged' => 1])->assertSessionHasNoErrors();
        $this->assertSame(1, TherapistFee::count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_booking_cancellation_voids_earned_fee_without_deleting_its_audit_history(): void
    {
        $booking = $this->booking();
        $this->sync($booking);
        app(BookingService::class)->cancel($booking, 'cancelled', 'Koreksi operasional');
        $this->assertSame('void', $booking->therapistFee()->sole()->status);
        $this->assertSame(1, AuditLog::where('action', 'therapist_fee.void')->count());
        $this->get(route('admin.therapist-fees.index', $this->filters()))->assertOk()->assertViewHas('summary', fn ($s) => $s['count'] === 0 && $s['void'] === 1);
    }

    public function test_historical_preview_rejects_stale_amounts_policy_rates_and_expired_or_forged_tokens_atomically(): void
    {
        $one = $this->booking();
        $two = $this->booking();
        $token = $this->fees->preview($this->filters())['token'];
        $two->update(['promo_discount' => 100]);
        $this->expectValidation(fn () => $this->fees->process($token, $this->admin, Request::create('/')));
        $this->assertSame(0, TherapistFee::count());
        $token = $this->fees->preview($this->filters())['token'];
        $this->therapist->forceFill(['fee_spa_percent' => 20])->save();
        $this->expectValidation(fn () => $this->fees->process($token, $this->admin, Request::create('/')));
        $token = $this->fees->preview($this->filters())['token'];
        $this->configure(['spa_percent' => '50']);
        $this->expectValidation(fn () => $this->fees->process($token, $this->admin, Request::create('/')));
        $token = $this->fees->preview($this->filters())['token'];
        $this->travel(16)->minutes();
        $this->expectValidation(fn () => $this->fees->process($token, $this->admin, Request::create('/')));
        $this->expectValidation(fn () => $this->fees->process('forged', $this->admin, Request::create('/')));
    }

    public function test_history_processes_at_most_100_then_remaining_without_duplicates(): void
    {
        for ($i = 0; $i < 101; $i++) {
            $this->booking();
        }
        $preview = $this->fees->preview($this->filters());
        $this->assertCount(100, $preview['rows']);
        $this->assertSame(100, $this->fees->process($preview['token'], $this->admin, Request::create('/')));
        $next = $this->fees->preview($this->filters());
        $this->assertCount(1, $next['rows']);
        $this->assertSame(1, $this->fees->process($next['token'], $this->admin, Request::create('/')));
        $this->assertSame(101, TherapistFee::count());
    }

    public function test_report_includes_last_day_has_full_csv_and_excludes_demo_and_void_from_totals(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->sync($this->booking());
        }
        $demo = $this->booking(['notes' => '[DEMO] Simulasi']);
        $this->sync($demo);
        $voided = $this->booking();
        $this->sync($voided);
        $voided->update(['payment_status' => 'refunded']);
        $this->sync($voided);
        $filters = ['start_date' => '2026-10-29', 'end_date' => '2026-10-29'];
        $response = $this->get(route('admin.therapist-fees.index', $filters))->assertOk();
        $this->assertSame(25, $response->viewData('summary')['count']);
        $this->assertEquals(25 * 6750000, $response->viewData('summary')['fee']);
        $this->assertSame(26, $response->viewData('rows')->total());
        $this->assertSame(20, $response->viewData('rows')->count());
        $export = $this->get(route('admin.therapist-fees.export', $filters))->assertOk()->streamedContent();
        $this->assertCount(27, array_filter(explode("\n", trim($export))));
        $this->assertStringNotContainsString($demo->booking_code, $export);
        $this->get(route('admin.therapist-fees.index', $filters + ['include_demo' => 1]))->assertViewHas('summary', fn ($s) => $s['count'] === 26);
    }

    public function test_report_filters_group_branch_and_therapist_and_csv_escapes_formula_text(): void
    {
        $branch = Branch::create(['name' => 'Cabang Fee', 'code' => 'FEE-BR', 'status' => 'active']);
        $asi = Service::create(['name' => '=FORMULA("ASI")', 'service_group' => 'lactation', 'duration_minutes' => 60, 'price' => 250000, 'is_active' => true]);
        $fee = $this->sync($this->booking(['service_id' => $asi->id, 'branch_id' => $branch->id]));
        $this->sync($this->booking());
        $filters = $this->filters(['branch_id' => $branch->id, 'service_group' => 'lactation', 'therapist_id' => $this->therapist->id]);
        $this->get(route('admin.therapist-fees.index', $filters))->assertOk()->assertViewHas('summary', fn ($s) => $s['count'] === 1 && (int) $s['fee'] === $fee->fee_cents);
        $csv = $this->get(route('admin.therapist-fees.export', $filters))->assertOk()->streamedContent();
        $lines = explode("\n", trim($csv));
        $row = str_getcsv($lines[1], ',', '"', '');
        $this->assertSame("'=FORMULA(\"ASI\")", $row[4]);
        $this->assertSame('225000.00', $row[6]);
        $this->assertSame('40.00', $row[7]);
        $this->assertSame('90000.00', $row[8]);
    }

    public function test_fee_history_prevents_deleting_booking_or_customer_and_survives_therapist_archival(): void
    {
        $booking = $this->booking();
        $this->sync($booking);
        $this->delete(route('admin.bookings.destroy', $booking))->assertSessionHasErrors('booking');
        $this->delete(route('admin.customers.destroy', $this->customer))->assertForbidden();
        $owner = User::factory()->create(['role' => 'owner', 'status' => 'active']);
        $this->actingAs($owner)->delete(route('admin.customers.destroy', $this->customer))->assertSessionHasErrors('customer');
        $this->actingAs($this->admin);
        $this->post(route('admin.therapists.archive', $this->therapist))->assertSessionHasNoErrors();
        $this->assertSame(1, TherapistFee::count());
        $this->assertSame(1, Booking::count());
    }

    public function test_percentage_validation_and_privileged_roles_are_enforced_for_all_fee_endpoints(): void
    {
        foreach (['-1', '100.01', '33.333', 'not-number'] as $rate) {
            $this->put(route('admin.therapist-fees.settings.update'), ['enabled' => 1, 'start_date' => '2026-10-01', 'spa_percent' => $rate, 'lactation_percent' => 40])->assertSessionHasErrors('spa_percent');
        }
        foreach (['owner', 'manager', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'active']))->get(route('admin.therapist-fees.settings'))->assertOk();
        }
        foreach (['sales', 'therapist'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'active']));
            foreach (['index', 'settings', 'export'] as $endpoint) {
                $this->get(route('admin.therapist-fees.'.$endpoint))->assertForbidden();
            }
            foreach (['preview', 'process'] as $endpoint) {
                $this->post(route('admin.therapist-fees.'.$endpoint), [])->assertForbidden();
            }
            $this->put(route('admin.therapist-fees.settings.update'), [])->assertForbidden();
            $this->put(route('admin.therapist-fees.therapist.update', $this->therapist), [])->assertForbidden();
        }
        $this->app['auth']->guard('web')->logout();
        $this->get(route('admin.therapist-fees.index'))->assertRedirect(route('login'));
    }
}
