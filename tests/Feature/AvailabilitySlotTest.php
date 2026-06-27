<?php

namespace Tests\Feature;

use App\Models\AiPersona;
use App\Models\AvailabilitySlot;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Therapist;
use App\Models\User;
use App\Services\AI\AiService;
use App\Support\AvailabilitySlotStatus;
use App\Support\BookingStatus;
use App\Support\CustomerStatus;
use App\Support\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvailabilitySlotTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_and_bulk_generate_availability_slots(): void
    {
        [$admin, , $branch, $service, $therapist] = $this->seedData();

        $this->actingAs($admin)
            ->get(route('admin.availability.index'))
            ->assertOk()
            ->assertSee('Jadwal Tersedia');

        $this->actingAs($admin)
            ->post(route('admin.availability.store'), [
                'branch_id' => $branch->id,
                'service_id' => $service->id,
                'therapist_id' => $therapist->id,
                'slot_date' => now()->addDay()->toDateString(),
                'start_time' => '10:00',
                'end_time' => '11:00',
                'capacity' => 1,
                'status' => AvailabilitySlotStatus::AVAILABLE,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('availability_slots', [
            'service_id' => $service->id,
            'start_time' => '10:00',
            'status' => AvailabilitySlotStatus::AVAILABLE,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.availability.bulk-generate'), [
                'branch_id' => $branch->id,
                'service_id' => $service->id,
                'therapist_id' => $therapist->id,
                'slot_date' => now()->addDays(2)->toDateString(),
                'start_time' => '09:00',
                'end_time' => '12:00',
                'interval_minutes' => 60,
                'capacity' => 1,
            ])
            ->assertRedirect();

        $this->assertSame(3, AvailabilitySlot::whereDate('slot_date', now()->addDays(2)->toDateString())->count());
    }

    public function test_booking_uses_slot_capacity_and_rejects_full_slot(): void
    {
        [$admin, $customer, $branch, $service, $therapist] = $this->seedData();
        $slot = AvailabilitySlot::create([
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'therapist_id' => $therapist->id,
            'slot_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'capacity' => 1,
            'booked_count' => 0,
            'status' => AvailabilitySlotStatus::AVAILABLE,
        ]);

        $payload = [
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'therapist_id' => $therapist->id,
            'availability_slot_id' => $slot->id,
            'booking_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00',
            'status' => BookingStatus::DRAFT,
            'payment_status' => PaymentStatus::UNPAID,
        ];

        $this->actingAs($admin)->post(route('admin.bookings.store'), $payload)->assertRedirect(route('admin.bookings.index'));

        $this->assertDatabaseHas('availability_slots', [
            'id' => $slot->id,
            'booked_count' => 1,
            'status' => AvailabilitySlotStatus::FULL,
        ]);

        $secondCustomer = Customer::create([
            'branch_id' => $branch->id,
            'name' => 'Bunda Kedua',
            'phone' => '628123450222',
            'whatsapp_number' => '628123450222',
            'status' => CustomerStatus::ACTIVE,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.bookings.store'), array_replace($payload, ['customer_id' => $secondCustomer->id]))
            ->assertSessionHasErrors('booking');
    }

    public function test_ai_answers_schedule_from_real_availability_slots(): void
    {
        [, , $branch, $service, $therapist] = $this->seedData();
        config()->set('ai.provider', 'local');
        AiPersona::create([
            'name' => 'Gayatri AI',
            'slug' => 'gayatri-ai',
            'prompt' => 'Jawab ramah.',
            'tone' => 'warm',
            'is_active' => true,
        ]);
        AvailabilitySlot::create([
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'therapist_id' => $therapist->id,
            'slot_date' => now()->addDay()->toDateString(),
            'start_time' => '15:00:00',
            'end_time' => '16:00:00',
            'capacity' => 2,
            'booked_count' => 0,
            'status' => AvailabilitySlotStatus::AVAILABLE,
        ]);

        $result = app(AiService::class)->simulate('Besok ada jadwal yang ready?');

        $this->assertStringContainsString('15:00', $result['reply']);
        $this->assertStringContainsString('Baby Spa Premium', $result['reply']);
    }

    private function seedData(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $branch = Branch::create(['name' => 'Gayatri Jakarta', 'code' => 'GJKT', 'status' => 'active']);
        $customer = Customer::create([
            'branch_id' => $branch->id,
            'name' => 'Bunda Slot',
            'phone' => '628123450111',
            'whatsapp_number' => '628123450111',
            'status' => CustomerStatus::ACTIVE,
        ]);
        $service = Service::create([
            'branch_id' => $branch->id,
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
        $therapist = Therapist::create(['branch_id' => $branch->id, 'name' => 'Terapis Slot', 'status' => 'active']);

        return [$admin, $customer, $branch, $service, $therapist];
    }
}
