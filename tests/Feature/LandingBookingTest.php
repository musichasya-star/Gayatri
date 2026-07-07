<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\AvailabilitySlot;
use App\Models\Customer;
use App\Models\LandingPageSetting;
use App\Models\Service;
use App\Models\User;
use App\Support\AvailabilitySlotStatus;
use App\Support\BookingStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_booking_saves_whatsapp_number_to_customer_database(): void
    {
        [$branch, $service] = $this->seedBranchAndService();
        $slot = $this->slot($branch, $service, now()->addDay()->toDateString(), '10:00:00');

        $response = $this->post(route('landing.booking'), [
            'name' => 'Bunda Test WA',
            'whatsapp_number' => '0812-3456-7890',
            'email' => 'bunda@example.test',
            'baby_name' => 'Aira',
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'availability_slot_id' => $slot->id,
            'booking_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00',
            'notes' => 'Booking dari test landing page.',
        ]);

        $response->assertRedirect(route('landing.booking-page'));
        $response->assertSessionHas('landing_booking_id');

        $this->assertDatabaseHas('customers', [
            'name' => 'Bunda Test WA',
            'phone' => '0812-3456-7890',
            'whatsapp_number' => '6281234567890',
            'baby_name' => 'Aira',
            'email' => 'bunda@example.test',
        ]);

        $this->assertDatabaseHas('bookings', [
            'service_id' => $service->id,
            'availability_slot_id' => $slot->id,
            'booking_date' => $slot->slot_date->format('Y-m-d H:i:s'),
            'start_time' => '10:00:00',
            'branch_id' => $branch->id,
            'status' => BookingStatus::PENDING_CONFIRMATION,
            'source' => 'landing_page',
        ]);
    }

    public function test_landing_booking_reuses_existing_customer_by_whatsapp_number(): void
    {
        [$branch, $service] = $this->seedBranchAndService();
        $slot = $this->slot($branch, $service, now()->addDay()->toDateString(), '11:00:00');

        $existingCustomer = Customer::create([
            'branch_id' => $branch->id,
            'name' => 'Bunda Lama',
            'phone' => '081234567890',
            'whatsapp_number' => '6281234567890',
            'email' => 'lama@example.test',
            'baby_name' => 'Mia',
            'status' => 'lead',
        ]);

        $response = $this->post(route('landing.booking'), [
            'name' => 'Bunda Baru',
            'whatsapp_number' => '0812-3456-7890',
            'email' => 'baru@example.test',
            'baby_name' => 'Lia',
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'availability_slot_id' => $slot->id,
            'booking_date' => now()->addDay()->toDateString(),
            'start_time' => '11:00',
            'notes' => 'Booking duplikasi WA test.',
        ]);

        $response->assertRedirect(route('landing.booking-page'));
        $response->assertSessionHas('landing_booking_id');

        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseHas('customers', [
            'id' => $existingCustomer->id,
            'name' => 'Bunda Baru',
            'phone' => '0812-3456-7890',
            'whatsapp_number' => '6281234567890',
            'email' => 'baru@example.test',
            'baby_name' => 'Lia',
        ]);

        $this->assertDatabaseHas('bookings', [
            'customer_id' => $existingCustomer->id,
            'service_id' => $service->id,
            'availability_slot_id' => $slot->id,
            'branch_id' => $branch->id,
            'status' => BookingStatus::PENDING_CONFIRMATION,
            'source' => 'landing_page',
        ]);
    }

    public function test_landing_booking_rejects_request_when_selected_time_slot_is_not_available(): void
    {
        [$branch, $service] = $this->seedBranchAndService();
        $bookingDate = now()->addDay()->toDateString();

        $slot = AvailabilitySlot::create([
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'slot_date' => $bookingDate,
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'capacity' => 1,
            'booked_count' => 1,
            'status' => AvailabilitySlotStatus::FULL,
        ]);

        $response = $this->post(route('landing.booking'), [
            'name' => 'Bunda No Slot',
            'whatsapp_number' => '0812-5555-1111',
            'email' => 'noslot@example.test',
            'baby_name' => 'Ari',
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'availability_slot_id' => $slot->id,
            'booking_date' => $bookingDate,
            'start_time' => '10:00',
            'notes' => 'Booking jam tidak tersedia.',
        ]);

        $response->assertSessionHasErrors('booking');
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_landing_booking_rejects_inactive_service(): void
    {
        $branch = Branch::create([
            'name' => 'Gayatri Jakarta',
            'code' => 'GAY-TEST',
            'phone' => '0215550123',
            'address' => 'Jl. Test No. 1',
            'city' => 'Jakarta',
        ]);
        $service = Service::create([
            'branch_id' => $branch->id,
            'name' => 'Baby Spa Inactive',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => false,
        ]);
        $slot = AvailabilitySlot::create([
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'slot_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'capacity' => 1,
            'booked_count' => 0,
            'status' => AvailabilitySlotStatus::AVAILABLE,
        ]);

        $response = $this->post(route('landing.booking'), [
            'name' => 'Bunda Test WA',
            'whatsapp_number' => '0812-7777-3333',
            'email' => 'inactive@example.test',
            'baby_name' => 'Aila',
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'availability_slot_id' => $slot->id,
            'booking_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00',
            'notes' => 'Booking service non-aktif test.',
        ]);

        $response->assertSessionHasErrors('booking');
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_admin_can_update_booking_page_content_from_landing_settings_tab(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($admin)
            ->get(route('admin.landing.index'))
            ->assertOk()
            ->assertSee('Booking Page')
            ->assertSee('Konten Halaman Booking');

        $this->actingAs($admin)
            ->post(route('admin.landing.settings.update'), [
                'site_name' => 'Gayatri Mom & Baby SPA',
                'page_settings' => [
                    'booking_page' => [
                        'headline' => 'Booking Treatment Gayatri Custom',
                        'lead' => 'Pilih slot treatment yang sudah tersedia dari admin.',
                        'submit_button' => 'Kirim Reservasi Custom',
                    ],
                ],
            ])
            ->assertRedirect();

        $settings = LandingPageSetting::where('slug', 'home')->firstOrFail()->page_settings;

        $this->assertSame('Booking Treatment Gayatri Custom', data_get($settings, 'booking_page.headline'));
        $this->assertSame('Kirim Reservasi Custom', data_get($settings, 'booking_page.submit_button'));

        $this->get(route('landing.booking-page'))
            ->assertOk()
            ->assertSee('Booking Treatment Gayatri Custom')
            ->assertSee('Kirim Reservasi Custom');
    }

    private function seedBranchAndService(bool $serviceActive = true): array
    {
        $branch = Branch::create([
            'name' => 'Gayatri Jakarta',
            'code' => $serviceActive ? 'GAY-TEST' : 'GAY-TEST-INACTIVE',
            'phone' => '0215550123',
            'address' => 'Jl. Test No. 1',
            'city' => 'Jakarta',
        ]);

        $service = Service::create([
            'branch_id' => $branch->id,
            'name' => $serviceActive ? 'Baby Spa Test' : 'Baby Spa Inactive',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => $serviceActive,
        ]);

        return [$branch, $service];
    }

    private function slot(Branch $branch, Service $service, string $date, string $startTime): AvailabilitySlot
    {
        $endTime = now()->setTimeFromTimeString($startTime)->addMinutes((int) $service->duration_minutes)->format('H:i:s');

        return AvailabilitySlot::create([
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'slot_date' => $date,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'capacity' => 1,
            'booked_count' => 0,
            'status' => AvailabilitySlotStatus::AVAILABLE,
        ]);
    }
}
