<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Therapist;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Support\BookingStatus;
use App\Support\CustomerStatus;
use App\Support\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BookingManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_booking_and_it_appears_on_calendar(): void
    {
        [$admin, $customer, $branch, $service, $therapist] = $this->seedBookingData();

        $this->actingAs($admin)
            ->post(route('admin.bookings.store'), [
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'service_id' => $service->id,
                'therapist_id' => $therapist->id,
                'booking_date' => now()->addDay()->toDateString(),
                'start_time' => '10:00',
                'status' => BookingStatus::DRAFT,
                'payment_status' => PaymentStatus::UNPAID,
                'notes' => 'Booking manual test.',
            ])
            ->assertRedirect(route('admin.bookings.index'));

        $this->assertDatabaseHas('bookings', [
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'therapist_id' => $therapist->id,
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.bookings.calendar', ['date' => now()->addDay()->toDateString()]))
            ->assertOk()
            ->assertSee('Booking Calendar')
            ->assertSee($customer->name);
    }

    public function test_booking_rejects_conflicting_therapist_schedule(): void
    {
        [$admin, $customer, $branch, $service, $therapist] = $this->seedBookingData();

        Booking::create([
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'therapist_id' => $therapist->id,
            'created_by' => $admin->id,
            'booking_code' => 'BK-EXISTING-001',
            'booking_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => BookingStatus::CONFIRMED,
            'payment_status' => PaymentStatus::UNPAID,
            'source' => 'manual',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.bookings.store'), [
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'service_id' => $service->id,
                'therapist_id' => $therapist->id,
                'booking_date' => now()->addDay()->toDateString(),
                'start_time' => '10:30',
                'status' => BookingStatus::DRAFT,
                'payment_status' => PaymentStatus::UNPAID,
            ])
            ->assertSessionHasErrors('booking');
    }

    public function test_booking_rejects_past_date(): void
    {
        [$admin, $customer, $branch, $service, $therapist] = $this->seedBookingData();

        $this->actingAs($admin)
            ->post(route('admin.bookings.store'), [
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'service_id' => $service->id,
                'therapist_id' => $therapist->id,
                'booking_date' => now()->subDay()->toDateString(),
                'start_time' => '10:00',
                'status' => BookingStatus::DRAFT,
                'payment_status' => PaymentStatus::UNPAID,
            ])
            ->assertSessionHasErrors('booking');
    }

    public function test_booking_rejects_outside_operating_hours(): void
    {
        [$admin, $customer, $branch, $service, $therapist] = $this->seedBookingData();

        $this->actingAs($admin)
            ->post(route('admin.bookings.store'), [
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'service_id' => $service->id,
                'therapist_id' => $therapist->id,
                'booking_date' => now()->addDay()->toDateString(),
                'start_time' => '18:30',
                'status' => BookingStatus::DRAFT,
                'payment_status' => PaymentStatus::UNPAID,
            ])
            ->assertSessionHasErrors('booking');
    }

    public function test_create_booking_page_prefills_customer_and_conversation(): void
    {
        [$admin, $customer] = $this->seedBookingData();
        $session = WhatsAppSession::create(['session_name' => 'default', 'status' => 'working']);
        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_session_id' => $session->id,
            'wa_chat_id' => $customer->whatsapp_number.'@c.us',
            'channel' => 'whatsapp',
            'status' => 'open',
            'ai_enabled' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.bookings.create', ['customer_id' => $customer->id, 'conversation_id' => $conversation->id]))
            ->assertOk()
            ->assertSee('Tambah Booking')
            ->assertSee('value="'.$conversation->id.'"', false)
            ->assertSee('selected', false);
    }

    public function test_confirmed_booking_sends_whatsapp_confirmation(): void
    {
        config()->set('waha.base_url', 'http://waha.test');
        Http::fake([
            'http://waha.test/api/sendText' => Http::response(['id' => 'wamid-booking-confirm-001'], 200),
        ]);

        [$admin, $customer, $branch, $service, $therapist] = $this->seedBookingData();
        $customer->update(['name' => 'ingin baby spa hari ini jam']);
        $session = WhatsAppSession::create(['session_name' => 'default', 'status' => 'working']);
        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_session_id' => $session->id,
            'wa_chat_id' => $customer->whatsapp_number.'@c.us',
            'channel' => 'whatsapp',
            'status' => 'open',
            'ai_enabled' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.bookings.store'), [
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'service_id' => $service->id,
                'therapist_id' => $therapist->id,
                'conversation_id' => $conversation->id,
                'booking_date' => now()->addDay()->toDateString(),
                'start_time' => '10:00',
                'status' => BookingStatus::CONFIRMED,
                'payment_status' => PaymentStatus::UNPAID,
            ])
            ->assertRedirect(route('admin.bookings.index'));

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'wa_message_id' => 'wamid-booking-confirm-001',
            'direction' => 'outgoing',
            'sender_type' => 'system',
        ]);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'content' => 'Baik Bunda, booking Baby Spa Premium sudah dikonfirmasi untuk '.now()->addDay()->format('d M Y').' pukul 10:00. Mohon hadir 10 menit sebelum jadwal ya Bunda.',
        ]);
        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversation->id,
            'content' => 'Baik Bunda ingin baby spa hari ini jam, booking Baby Spa Premium sudah dikonfirmasi untuk '.now()->addDay()->format('d M Y').' pukul 10:00. Mohon hadir 10 menit sebelum jadwal ya Bunda.',
        ]);
    }

    public function test_admin_can_update_booking_without_self_conflict(): void
    {
        [$admin, $customer, $branch, $service, $therapist] = $this->seedBookingData();
        $booking = Booking::create([
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'therapist_id' => $therapist->id,
            'created_by' => $admin->id,
            'booking_code' => 'BK-UPDATE-001',
            'booking_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => BookingStatus::DRAFT,
            'payment_status' => PaymentStatus::UNPAID,
            'source' => 'manual',
        ]);

        $this->actingAs($admin)
            ->put(route('admin.bookings.update', $booking), [
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'service_id' => $service->id,
                'therapist_id' => $therapist->id,
                'booking_date' => now()->addDay()->toDateString(),
                'start_time' => '12:00',
                'status' => BookingStatus::CONFIRMED,
                'payment_status' => PaymentStatus::UNPAID,
                'notes' => 'Updated.',
            ])
            ->assertRedirect(route('admin.bookings.index'));

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'start_time' => '12:00:00',
            'end_time' => '13:00:00',
            'status' => BookingStatus::CONFIRMED,
        ]);
    }

    private function seedBookingData(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $branch = Branch::create([
            'name' => 'Gayatri Jakarta',
            'code' => 'GJKT',
            'status' => 'active',
        ]);
        $customer = Customer::create([
            'branch_id' => $branch->id,
            'name' => 'Bunda Booking',
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
        $therapist = Therapist::create([
            'branch_id' => $branch->id,
            'name' => 'Terapis Booking',
            'status' => 'active',
        ]);

        return [$admin, $customer, $branch, $service, $therapist];
    }
}
