<?php

namespace Tests\Feature;

use App\Jobs\SendHomeVisitAssignmentJob;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\AvailabilitySlot;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Service;
use App\Models\ServiceAddon;
use App\Models\Therapist;
use App\Models\User;
use App\Services\AppSettingService;
use App\Services\CRM\BookingService;
use App\Services\CRM\HomeService;
use App\Services\CRM\ReminderService;
use App\Services\WhatsApp\WhatsAppGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

class HomeServiceTest extends TestCase
{
    use RefreshDatabase;

    private Service $treatment;

    private Therapist $therapist;

    private Customer $customer;

    private array $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 26)->setTime(8, 0));
        Queue::fake();
        Http::preventStrayRequests();
        Cache::flush();
        $branch = Branch::create(['name' => 'Kantor', 'code' => 'TEST', 'status' => 'active']);
        $this->treatment = Service::create(['branch_id' => $branch->id, 'name' => 'Baby Spa', 'duration_minutes' => 60, 'price' => 150000, 'is_active' => true]);
        $this->therapist = Therapist::create(['branch_id' => $branch->id, 'name' => 'Terapis Test', 'phone' => '081234000111', 'status' => 'active']);
        $this->customer = Customer::create(['name' => 'Bunda Test', 'whatsapp_number' => '6281234000222', 'status' => 'lead']);
        $this->settings = array_replace(app(HomeService::class)->settings(), [
            'enabled' => true, 'office_address' => 'Kantor uji Kediri', 'latitude' => -7.816, 'longitude' => 112.012,
            'rate_per_km' => 5000, 'max_distance_km' => 20, 'base_fee' => 10000, 'free_km' => 2,
            'buffer_minutes' => 15, 'service_ids' => [$this->treatment->id], 'therapist_ids' => [$this->therapist->id],
        ]);
        $this->saveSettings();
    }

    private function saveSettings(): void
    {
        app(AppSettingService::class)->set('home_service.settings', json_encode($this->settings));
    }

    private function fakeRoute(float $distance = 6200, float $duration = 1200): void
    {
        Http::fake(['router.project-osrm.org/*' => Http::response(['code' => 'Ok', 'routes' => [['distance' => $distance, 'duration' => $duration]]])]);
    }

    private function data(array $overrides = []): array
    {
        return array_replace([
            'customer_id' => $this->customer->id, 'service_id' => $this->treatment->id,
            'therapist_id' => $this->therapist->id, 'booking_date' => '2026-10-27', 'start_time' => '10:00',
            'status' => 'pending_confirmation', 'payment_status' => 'unpaid', 'service_mode' => 'home',
            'visit_address' => 'Jalan Uji Nomor 10, Kediri', 'visit_landmark' => 'Pagar putih',
            'visit_latitude' => -7.83, 'visit_longitude' => 112.03,
        ], $overrides);
    }

    private function token(): string
    {
        return app(HomeService::class)->quote(-7.83, 112.03, $this->treatment->id)['token'];
    }

    private function booking(array $overrides = []): Booking
    {
        return app(BookingService::class)->create($this->data(array_replace(['home_quote_token' => $this->token()], $overrides)), null);
    }

    public function test_admin_can_set_tariff_radius_and_audit_settings(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)->get(route('admin.home-service.index'))->assertOk()->assertSee('Biaya per kilometer')->assertSee('Jarak layanan maksimal');
        $this->from(route('admin.home-service.index'))->put(route('admin.home-service.update'), array_replace($this->settings, ['rate_per_km' => 7000, 'max_distance_km' => 12.5]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals(7000, app(HomeService::class)->settings()['rate_per_km']);
        $this->assertEquals(12.5, app(HomeService::class)->settings()['max_distance_km']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'home_service.settings.update']);
        Http::assertNothingSent();
    }

    public function test_invalid_settings_cannot_be_enabled_and_endpoint_credentials_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'owner', 'status' => 'active']);
        $this->actingAs($admin)->put(route('admin.home-service.update'), array_replace($this->settings, ['rate_per_km' => -1, 'max_distance_km' => 0, 'latitude' => null, 'routing_base_url' => 'https://user:password@router.test']))->assertSessionHasErrors(['rate_per_km', 'max_distance_km', 'latitude', 'routing_base_url']);
        $this->assertNull(session()->getOldInput('routing_base_url'));
    }

    public function test_sales_and_therapist_cannot_access_settings(): void
    {
        foreach (['sales', 'therapist'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'active']))->get(route('admin.home-service.index'))->assertForbidden();
            $this->put(route('admin.home-service.update'), $this->settings)->assertForbidden();
        }
    }

    public function test_road_distance_free_km_and_rounding_determine_server_fee(): void
    {
        $this->fakeRoute();
        $this->postJson(route('landing.home-service.quote'), ['service_id' => $this->treatment->id, 'latitude' => -7.83, 'longitude' => 112.03])->assertOk()->assertJsonPath('quote.distance_meters', 6200)->assertJsonPath('quote.billable_km', 5)->assertJsonPath('quote.travel_fee', 35000);
    }

    public function test_exact_free_distance_costs_only_base_fee(): void
    {
        $this->fakeRoute(2000);
        $quote = app(HomeService::class)->quote(-7.83, 112.03, $this->treatment->id)['quote'];
        $this->assertSame(0, $quote['billable_km']);
        $this->assertSame(10000, $quote['travel_fee']);
    }

    public function test_out_of_area_rejects_even_when_straight_line_would_be_near(): void
    {
        $this->fakeRoute(20001);
        $this->postJson(route('landing.home-service.quote'), ['service_id' => $this->treatment->id, 'latitude' => -7.8161, 'longitude' => 112.0121])->assertUnprocessable()->assertJsonMissingPath('token');
    }

    public function test_route_outage_does_not_fall_back_to_free_quote(): void
    {
        Http::fake(['router.project-osrm.org/*' => Http::response([], 503)]);
        $this->postJson(route('landing.home-service.quote'), ['service_id' => $this->treatment->id, 'latitude' => -7.83, 'longitude' => 112.03])->assertUnprocessable()->assertJsonMissingPath('token');
    }

    public function test_disabled_home_service_is_rejected_without_route_request(): void
    {
        $this->settings['enabled'] = false;
        $this->saveSettings();
        $this->postJson(route('landing.home-service.quote'), ['service_id' => $this->treatment->id, 'latitude' => -7.83, 'longitude' => 112.03])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_private_osrm_routes_use_admin_endpoint(): void
    {
        $this->settings['routing_provider'] = 'osrm_private';
        $this->settings['routing_base_url'] = 'https://routing.test';
        $this->saveSettings();
        Http::fake(['routing.test/*' => Http::response(['code' => 'Ok', 'routes' => [['distance' => 6200, 'duration' => 1200]]])]);
        $result = app(HomeService::class)->quote(-7.83, 112.03, $this->treatment->id);
        $this->assertSame(35000, $result['quote']['travel_fee']);
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://routing.test/route/v1/driving/112.012,-7.816;112.03,-7.83'));
    }

    public function test_booking_saves_snapshot_round_trip_busy_window_and_ignores_forged_fee(): void
    {
        $this->fakeRoute();
        $booking = $this->booking(['travel_fee' => 1]);
        $this->assertEquals(35000, $booking->travel_fee);
        $this->assertEquals(185000, $booking->totalPrice());
        $this->assertSame('09:25', $booking->busy_from->format('H:i'));
        $this->assertSame('11:35', $booking->busy_until->format('H:i'));
        $this->assertSame('Pagar putih', $booking->visit_landmark);
    }

    public function test_tampered_quote_cannot_create_booking(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(BookingService::class)->create($this->data(['home_quote_token' => 'forged', 'travel_fee' => 0]), null);
    }

    public function test_expired_quote_requires_recalculation(): void
    {
        $this->fakeRoute();
        $token = $this->token();
        $this->travel(16)->minutes();
        $this->expectException(InvalidArgumentException::class);
        app(BookingService::class)->create($this->data(['home_quote_token' => $token]), null);
    }

    public function test_changed_destination_invalidates_quote(): void
    {
        $this->fakeRoute();
        $this->expectException(InvalidArgumentException::class);
        $this->booking(['visit_latitude' => -7.84]);
    }

    public function test_changed_settings_invalidates_unsubmitted_quote(): void
    {
        $this->fakeRoute();
        $token = $this->token();
        $this->settings['rate_per_km'] = 8000;
        $this->saveSettings();
        $this->expectException(InvalidArgumentException::class);
        app(BookingService::class)->create($this->data(['home_quote_token' => $token]), null);
    }

    public function test_existing_booking_keeps_original_tariff_after_settings_change_and_reschedule(): void
    {
        $this->fakeRoute();
        $booking = $this->booking();
        $this->settings['rate_per_km'] = 9000;
        $this->saveSettings();
        $this->treatment->update(['price' => 200000]);
        app(BookingService::class)->update($booking, $this->data(['start_time' => '12:00']));
        $this->assertEquals(35000, $booking->refresh()->travel_fee);
        $this->assertEquals(185000, $booking->totalPrice());
        $this->assertSame('11:25', $booking->busy_from->format('H:i'));
    }

    public function test_home_booking_blocks_clinic_booking_during_return_trip(): void
    {
        $this->fakeRoute();
        $this->booking();
        $this->expectException(InvalidArgumentException::class);
        app(BookingService::class)->create($this->data(['service_mode' => 'clinic', 'start_time' => '11:15']), null);
    }

    public function test_existing_clinic_booking_blocks_home_departure_time(): void
    {
        $this->fakeRoute();
        app(BookingService::class)->create($this->data(['service_mode' => 'clinic', 'start_time' => '09:00']), null);
        $this->expectException(InvalidArgumentException::class);
        $this->booking();
    }

    public function test_cancelled_visit_releases_travel_window(): void
    {
        $this->fakeRoute();
        $booking = $this->booking();
        app(BookingService::class)->cancel($booking);
        $this->assertFalse(app(BookingService::class)->hasConflict($this->therapist->id, '2026-10-27', '11:00', '12:00'));
    }

    public function test_home_confirmation_requires_eligible_therapist(): void
    {
        $this->fakeRoute();
        $this->expectException(InvalidArgumentException::class);
        $this->booking(['therapist_id' => null, 'status' => 'confirmed']);
    }

    public function test_home_slot_assigns_therapist_and_landing_saves_pending_booking(): void
    {
        $this->fakeRoute();
        $slot = AvailabilitySlot::create(['branch_id' => $this->treatment->branch_id, 'service_id' => $this->treatment->id, 'therapist_id' => $this->therapist->id, 'slot_date' => '2026-10-27', 'start_time' => '10:00', 'end_time' => '11:00', 'capacity' => 1, 'booked_count' => 0, 'status' => 'available']);
        $data = $this->data(['home_quote_token' => $this->token(), 'availability_slot_id' => $slot->id, 'branch_id' => $this->treatment->branch_id, 'name' => 'Bunda Test', 'whatsapp_number' => $this->customer->whatsapp_number]);
        unset($data['therapist_id']);
        $this->post(route('landing.booking'), $data)->assertRedirect(route('landing.booking-page'))->assertSessionHasNoErrors();
        $booking = Booking::firstOrFail();
        $this->assertSame($this->therapist->id, $booking->therapist_id);
        $this->assertSame('pending_confirmation', $booking->status);
        $this->get(route('landing.booking-page'))->assertOk()->assertSee('Jalan Uji Nomor 10')->assertSee('185.000');
        unset($data['home_quote_token']);
        $this->post(route('landing.booking.reschedule'), $data)->assertRedirect(route('landing.booking-page'))->assertSessionHasNoErrors();
        $this->assertSame(1, Booking::count());
        $this->assertEquals(35000, $booking->refresh()->travel_fee);
        $this->assertSame(1, $slot->refresh()->booked_count);
        Queue::assertNotPushed(SendHomeVisitAssignmentJob::class);
    }

    public function test_confirmation_queues_customer_notice_therapist_navigation_and_home_reminder(): void
    {
        $this->fakeRoute();
        $booking = $this->booking(['status' => 'confirmed']);
        Queue::assertPushed(SendWhatsAppMessageJob::class);
        Queue::assertPushed(SendHomeVisitAssignmentJob::class);
        $reminder = $booking->reminders()->where('type', 'h1')->firstOrFail();
        $message = app(ReminderService::class)->buildMessage($reminder);
        $this->assertStringContainsString('akan datang', $message);
        $this->assertStringNotContainsString('Mohon hadir', $message);
        $gateway = $this->mock(WhatsAppGateway::class);
        $gateway->shouldReceive('sendText')->once()->withArgs(fn ($session, $chat, $text) => $chat === '6281234000111@c.us' && str_contains($text, 'Pagar putih') && str_contains($text, 'maps/dir'));
        $job = new SendHomeVisitAssignmentJob($booking->id, $booking->homeVisitRevision());
        $job->handle($gateway);
        $job->handle($gateway);
    }

    public function test_unlisted_service_and_unlisted_therapist_are_rejected(): void
    {
        $otherService = Service::create(['name' => 'Clinic Only', 'duration_minutes' => 60, 'price' => 100000, 'is_active' => true]);
        $this->postJson(route('landing.home-service.quote'), ['service_id' => $otherService->id, 'latitude' => -7.83, 'longitude' => 112.03])->assertUnprocessable();
        Http::assertNothingSent();
        $this->fakeRoute();
        $otherStaff = Therapist::create(['name' => 'Clinic Only', 'status' => 'active']);
        $this->expectException(InvalidArgumentException::class);
        $this->booking(['therapist_id' => $otherStaff->id]);
    }

    public function test_quote_locks_treatment_and_addon_prices_and_update_keeps_them(): void
    {
        $this->fakeRoute();
        $addon = ServiceAddon::create(['service_id' => $this->treatment->id, 'addon_name' => 'Extra', 'duration_minutes' => 10, 'price_adjustment' => 20000, 'is_active' => true]);
        $token = $this->token();
        $addon->update(['price_adjustment' => 40000]);
        $this->treatment->update(['price' => 200000]);
        $booking = app(BookingService::class)->create($this->data(['home_quote_token' => $token, 'addons' => [$addon->id]]), null);
        $this->assertEquals(205000, $booking->totalPrice());
        app(BookingService::class)->update($booking, $this->data(['start_time' => '12:00', 'addons' => [$addon->id]]));
        $this->assertEquals(205000, $booking->refresh()->totalPrice());
    }

    public function test_reassignment_notifies_previous_staff_and_skips_stale_assignment_job(): void
    {
        $this->fakeRoute();
        $booking = $this->booking(['status' => 'confirmed']);
        $oldRevision = $booking->homeVisitRevision();
        $other = Therapist::create(['name' => 'Other Staff', 'phone' => '081234000333', 'status' => 'active']);
        $this->settings['therapist_ids'][] = $other->id;
        $this->saveSettings();
        app(BookingService::class)->update($booking, $this->data(['status' => 'confirmed', 'therapist_id' => $other->id]));
        Queue::assertPushed(SendHomeVisitAssignmentJob::class, fn ($job) => $job->withdraw && $job->previousTherapistId === $this->therapist->id);
        $gateway = $this->mock(WhatsAppGateway::class);
        $gateway->shouldReceive('sendText')->once()->withArgs(fn ($session, $chat, $text) => $chat === '6281234000111@c.us' && str_contains($text, 'dialihkan'));
        (new SendHomeVisitAssignmentJob($booking->id, $oldRevision))->handle($gateway);
        (new SendHomeVisitAssignmentJob($booking->id, $booking->homeVisitRevision(), $this->therapist->id, true))->handle($gateway);
    }

    public function test_cancellation_job_tells_staff_not_to_depart(): void
    {
        $this->fakeRoute();
        $booking = $this->booking(['status' => 'confirmed']);
        app(BookingService::class)->cancel($booking);
        $gateway = $this->mock(WhatsAppGateway::class);
        $gateway->shouldReceive('sendText')->once()->withArgs(fn ($session, $chat, $text) => str_contains($text, 'Jangan berangkat'));
        (new SendHomeVisitAssignmentJob($booking->id, $booking->homeVisitRevision()))->handle($gateway);
    }
}
