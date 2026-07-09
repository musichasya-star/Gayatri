<?php

namespace Tests\Feature;

use App\Models\AvailabilitySlot;
use App\Models\Customer;
use App\Models\Service;
use App\Services\CRM\BookingService;
use App\Services\CRM\ServiceCatalogService;
use App\Support\AvailabilitySlotStatus;
use App\Support\BookingStatus;
use App\Support\CustomerStatus;
use App\Support\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class BookingAddonTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_catalog_syncs_addon_services(): void
    {
        $base = Service::create(['name' => 'Baby Spa Premium', 'duration_minutes' => 60, 'price' => 250000, 'is_active' => true]);
        $addon = Service::create(['name' => 'Pijat Bayi Balita', 'duration_minutes' => 45, 'price' => 150000, 'is_active' => true]);

        app(ServiceCatalogService::class)->update($base, [
            'name' => $base->name,
            'category' => $base->category,
            'duration_minutes' => $base->duration_minutes,
            'price' => $base->price,
            'is_active' => true,
            'description' => $base->description,
            'addons' => [[
                'addon_service_id' => $addon->id,
                'duration_minutes' => 15,
                'price_adjustment' => 50000,
                'is_active' => true,
            ]],
        ], null, Request::create('/admin/services/'.$base->id, 'PUT'));

        $this->assertDatabaseHas('service_addons', [
            'service_id' => $base->id,
            'addon_service_id' => $addon->id,
            'duration_minutes' => 15,
            'price_adjustment' => 50000,
            'is_active' => true,
        ]);
    }

    public function test_booking_with_addon_stores_snapshot_and_extends_duration(): void
    {
        $customer = Customer::create([
            'name' => 'Bunda Addon',
            'whatsapp_number' => '628123456700',
            'status' => CustomerStatus::LEAD,
        ]);
        $base = Service::create(['name' => 'Baby Spa Premium', 'duration_minutes' => 60, 'price' => 250000, 'is_active' => true]);
        $addonService = Service::create(['name' => 'Pijat Bayi Balita', 'duration_minutes' => 45, 'price' => 150000, 'is_active' => true]);
        $addon = $base->addOns()->create([
            'addon_service_id' => $addonService->id,
            'duration_minutes' => 15,
            'price_adjustment' => 50000,
            'is_active' => true,
        ]);
        $slot = AvailabilitySlot::create([
            'service_id' => $base->id,
            'slot_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'capacity' => 1,
            'booked_count' => 0,
            'status' => AvailabilitySlotStatus::AVAILABLE,
        ]);

        $booking = app(BookingService::class)->create([
            'customer_id' => $customer->id,
            'service_id' => $base->id,
            'availability_slot_id' => $slot->id,
            'booking_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00',
            'status' => BookingStatus::DRAFT,
            'payment_status' => PaymentStatus::UNPAID,
            'addons' => [$addon->id],
        ], null);

        $this->assertSame('11:15:00', (string) $booking->end_time);
        $this->assertDatabaseHas('booking_addons', [
            'booking_id' => $booking->id,
            'service_addon_id' => $addon->id,
            'addon_service_id' => $addonService->id,
            'name' => 'Pijat Bayi Balita',
            'duration_minutes' => 15,
            'price' => 50000,
        ]);
        $this->assertSame(1, $slot->fresh()->booked_count);
    }
}
