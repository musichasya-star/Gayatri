<?php

namespace Tests\Feature;

use App\Models\AvailabilitySlot;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use App\Services\CRM\ServiceCatalogService;
use App\Support\ServiceGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ServiceGroupTest extends TestCase
{
    use RefreshDatabase;

    private Service $spa;

    private Service $lactation;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->spa = Service::create(['name' => 'Treatment Pijat Pengujian', 'duration_minutes' => 60, 'price' => 200000, 'is_active' => true, 'service_group' => 'spa']);
        $this->lactation = Service::create(['name' => 'Konsultasi ASI Pengujian', 'duration_minutes' => 60, 'price' => 150000, 'is_active' => true, 'service_group' => 'lactation']);
    }

    private function slot(Service $service, string $time = '10:00:00'): AvailabilitySlot
    {
        return AvailabilitySlot::create(['service_id' => $service->id, 'slot_date' => today()->addDay(), 'start_time' => $time, 'end_time' => $time === '10:00:00' ? '11:00:00' : '15:00:00', 'capacity' => 2, 'booked_count' => 0, 'status' => 'available']);
    }

    private function data(Service $service, AvailabilitySlot $slot, string $group): array
    {
        return ['name' => 'Pelanggan '.$group, 'whatsapp_number' => $group === 'spa' ? '6280000011001' : '6280000011002', 'branch_id' => null, 'service_id' => $service->id, 'service_group' => $group, 'availability_slot_id' => $slot->id, 'booking_date' => $slot->slot_date->toDateString(), 'start_time' => substr($slot->start_time, 0, 5)];
    }

    public function test_migration_classifies_existing_lactation_names_without_changing_prices_or_booking_references(): void
    {
        $relaxation = Service::create(['name' => 'Relaksasi Bunda', 'category' => 'Inspirasi Bunda', 'duration_minutes' => 60, 'price' => 321000, 'is_active' => true]);
        $massage = Service::create(['name' => 'Pijat Laktasi', 'category' => 'mom-care', 'duration_minutes' => 60, 'price' => 190000, 'is_active' => true]);
        $customer = Customer::create(['name' => 'Riwayat Tetap', 'whatsapp_number' => '6280000011999', 'status' => 'lead']);
        $booking = Booking::create(['customer_id' => $customer->id, 'service_id' => $massage->id, 'booking_code' => 'GROUP-HISTORY', 'booking_date' => today(), 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'completed', 'payment_status' => 'paid']);
        $migration = require database_path('migrations/2026_10_29_000001_add_service_groups.php');
        $migration->down();
        $migration->up();
        $this->assertSame('lactation', $this->lactation->fresh()->service_group);
        $this->assertSame('lactation', $massage->fresh()->service_group);
        $this->assertSame('spa', $relaxation->fresh()->service_group);
        $this->assertSame('321000.00', $relaxation->fresh()->price);
        $this->assertSame($massage->id, $booking->fresh()->service_id);
        $this->assertSame('mom-care', $massage->fresh()->category);
    }

    public function test_public_booking_choices_and_embedded_slots_are_separate_and_inactive_services_are_hidden(): void
    {
        $spaSlot = $this->slot($this->spa);
        $asiSlot = $this->slot($this->lactation, '14:00:00');
        Service::create(['name' => 'Konsultasi Diarsipkan', 'service_group' => 'lactation', 'duration_minutes' => 60, 'price' => 100000, 'is_active' => false]);
        $response = $this->get(route('landing.booking-page', ['service_group' => 'lactation']))->assertOk()
            ->assertSee('Pendampingan menyusui untuk Bunda.')->assertSee($this->lactation->name)
            ->assertDontSee($this->spa->name)->assertDontSee('Konsultasi Diarsipkan')->assertDontSee('Private room');
        $this->assertSame([$this->lactation->id], $response->viewData('services')->modelKeys());
        $this->assertSame([$asiSlot->id], $response->viewData('availabilitySlots')->modelKeys());
        $this->get(route('landing.booking-page'))->assertOk()->assertSee($this->spa->name)->assertDontSee($this->lactation->name)->assertViewHas('availabilitySlots', fn ($slots) => $slots->modelKeys() === [$spaSlot->id]);
        $this->get(route('landing.booking-page', ['service_group' => 'unknown']))->assertNotFound();
        $this->get(route('landing.booking-page', ['service_group' => ['lactation']]))->assertNotFound();
        $this->post(route('landing.booking.reschedule'), ['service_group' => ['lactation']])->assertSessionHasErrors('service_group');
    }

    public function test_forged_cross_group_booking_is_rejected_before_customer_or_slot_is_changed(): void
    {
        $slot = $this->slot($this->spa);
        $this->post(route('landing.booking'), $this->data($this->spa, $slot, 'lactation'))->assertSessionHasErrors('booking');
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('bookings', 0);
        $this->assertSame(0, $slot->fresh()->booked_count);
    }

    public function test_one_browser_can_keep_separate_pending_requests_and_reschedule_inside_the_right_group(): void
    {
        $spaSlot = $this->slot($this->spa);
        $asiSlot = $this->slot($this->lactation, '14:00:00');
        $this->post(route('landing.booking'), $this->data($this->spa, $spaSlot, 'spa'))->assertSessionHasNoErrors()->assertRedirect(route('landing.booking-page'));
        $spaBooking = Booking::sole();
        $this->post(route('landing.booking'), $this->data($this->lactation, $asiSlot, 'lactation'))->assertSessionHasNoErrors()->assertRedirect(route('landing.booking-page', ['service_group' => 'lactation']));
        $asiBooking = Booking::where('service_id', $this->lactation->id)->sole();
        $this->get(route('landing.booking-page'))->assertOk()->assertViewHas('booking', fn ($booking) => $booking->id === $spaBooking->id)->assertDontSee($this->lactation->name);
        $this->get(route('landing.booking-page', ['service_group' => 'lactation']))->assertOk()->assertViewHas('booking', fn ($booking) => $booking->id === $asiBooking->id)->assertDontSee($this->spa->name);
        $this->post(route('landing.booking.reschedule'), $this->data($this->lactation, $asiSlot, 'lactation'))->assertSessionHasNoErrors()->assertRedirect(route('landing.booking-page', ['service_group' => 'lactation']));
        $this->assertSame(2, Booking::count());
        $this->assertSame($this->spa->id, $spaBooking->fresh()->service_id);
        $this->assertSame(1, $asiSlot->fresh()->booked_count);
        Http::assertNothingSent();
    }

    public function test_catalogue_group_can_be_managed_and_invalid_groups_are_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        $data = ['name' => 'Laktasi Baru', 'duration_minutes' => 60, 'price' => 175000, 'is_active' => true];
        $this->post(route('admin.services.store'), $data)->assertSessionHasNoErrors();
        $service = Service::where('name', 'Laktasi Baru')->sole();
        $this->assertSame(ServiceGroup::LACTATION, $service->service_group);
        $this->put(route('admin.services.update', $service), $data + ['service_group' => 'unsupported'])->assertSessionHasErrors('service_group');
        $this->get(route('admin.services.index', ['service_group' => 'lactation', 'search' => 'Pengujian']))->assertOk()->assertViewHas('services', fn ($services) => $services->getCollection()->modelKeys() === [$this->lactation->id]);
        $this->get(route('admin.services.create', ['service_group' => 'lactation']))->assertOk()->assertViewHas('service', fn ($service) => $service->service_group === 'lactation');
        $this->put(route('admin.services.update', $service), $data + ['service_group' => 'spa'])->assertSessionHasNoErrors();
        $this->assertSame('spa', $service->fresh()->service_group);
    }

    public function test_cross_group_addons_are_hidden_and_rejected_even_if_directly_inserted(): void
    {
        $addon = $this->spa->addOns()->create(['addon_service_id' => $this->lactation->id, 'duration_minutes' => 15, 'price_adjustment' => 50000, 'is_active' => true]);
        $this->assertCount(0, $this->spa->activeAddOns);
        $slot = $this->slot($this->spa);
        $this->post(route('landing.booking'), $this->data($this->spa, $slot, 'spa') + ['addons' => [$addon->id]])->assertSessionHasErrors('booking');
        $this->assertDatabaseCount('bookings', 0);
        $this->assertSame(0, $slot->fresh()->booked_count);
        $before = $this->spa->name;
        try {
            app(ServiceCatalogService::class)->update($this->spa, ['name' => 'Perubahan batal', 'addons' => [['addon_service_id' => $this->lactation->id, 'is_active' => true]]], null, Request::create('/'));
            $this->fail('Mixed group addon must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('addons', $exception->errors());
        }
        $this->assertSame($before, $this->spa->fresh()->name);
    }

    public function test_booking_list_and_calendar_filters_do_not_mix_groups(): void
    {
        foreach ([$this->spa, $this->lactation] as $service) {
            $slot = $this->slot($service);
            $this->post(route('landing.booking'), $this->data($service, $slot, $service->service_group))->assertSessionHasNoErrors();
        }
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        foreach (['admin.bookings.index', 'admin.bookings.calendar'] as $route) {
            $this->get(route($route, ['service_group' => 'lactation', 'date' => today()->addDay()->toDateString()]))->assertOk()->assertSee($this->lactation->name)->assertDontSee($this->spa->name)->assertViewHas('bookings', fn ($bookings) => $bookings->count() === 1);
        }
    }
}
