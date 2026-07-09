<?php

namespace Tests\Feature;

use App\Models\AiAutomationApproval;
use App\Models\AiExtractedData;
use App\Models\AvailabilitySlot;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Therapist;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Support\AvailabilitySlotStatus;
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

    public function test_admin_can_delete_booking_from_list_and_release_slot(): void
    {
        [$admin, $customer, $branch, $service, $therapist] = $this->seedBookingData();
        $slot = AvailabilitySlot::create([
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'therapist_id' => $therapist->id,
            'slot_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'capacity' => 1,
            'booked_count' => 1,
            'status' => AvailabilitySlotStatus::FULL,
        ]);
        $booking = Booking::create([
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'therapist_id' => $therapist->id,
            'availability_slot_id' => $slot->id,
            'created_by' => $admin->id,
            'booking_code' => 'BK-DELETE-001',
            'booking_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => BookingStatus::CONFIRMED,
            'payment_status' => PaymentStatus::UNPAID,
            'source' => 'manual',
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.bookings.destroy', $booking))
            ->assertRedirect(route('admin.bookings.index'));

        $this->assertDatabaseMissing('bookings', ['id' => $booking->id]);
        $this->assertSame(0, $slot->fresh()->booked_count);
        $this->assertSame(AvailabilitySlotStatus::AVAILABLE, $slot->fresh()->status);
    }

    public function test_booking_list_shows_preview_approval_action(): void
    {
        [$admin, $customer, $branch, $service, $therapist] = $this->seedBookingData();
        $booking = Booking::create([
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'therapist_id' => $therapist->id,
            'created_by' => $admin->id,
            'booking_code' => 'BK-PREVIEW-001',
            'booking_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => BookingStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID,
            'source' => 'ai_flow',
        ]);
        $extracted = AiExtractedData::create([
            'customer_id' => $customer->id,
            'intent' => 'booking_reschedule_request',
            'confidence_score' => 0.95,
            'status' => 'pending_approval',
        ]);
        $approval = AiAutomationApproval::create([
            'ai_extracted_data_id' => $extracted->id,
            'customer_id' => $customer->id,
            'target_entity' => 'booking',
            'action' => 'reschedule_booking',
            'mode' => 'need_confirmation',
            'proposed_data' => ['booking' => ['booking_id' => $booking->id, 'booking_code' => $booking->booking_code]],
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.bookings.index'))
            ->assertOk()
            ->assertSee('Preview Approval')
            ->assertSee(route('admin.ai.data-automation.approvals.show', $approval), false);
    }

    public function test_approval_reschedule_and_booking_status_updates_send_whatsapp_notifications(): void
    {
        config()->set('waha.base_url', 'http://waha.test');
        Http::fake(['http://waha.test/api/sendText' => Http::sequence()
            ->push(['id' => 'wamid-booking-rescheduled'], 200)
            ->push(['id' => 'wamid-booking-cancelled'], 200)]);

        [$admin, $customer, $branch, $service, $therapist] = $this->seedBookingData();
        $session = WhatsAppSession::create(['session_name' => 'default', 'status' => 'working']);
        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_session_id' => $session->id,
            'wa_chat_id' => $customer->whatsapp_number.'@c.us',
            'channel' => 'whatsapp',
            'status' => 'open',
            'ai_enabled' => true,
        ]);
        $booking = Booking::create([
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'therapist_id' => $therapist->id,
            'conversation_id' => $conversation->id,
            'created_by' => $admin->id,
            'booking_code' => 'BK-NOTIFY-001',
            'booking_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => BookingStatus::CONFIRMED,
            'payment_status' => PaymentStatus::UNPAID,
            'source' => 'manual',
        ]);
        $extracted = AiExtractedData::create([
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'intent' => 'booking_reschedule_request',
            'confidence_score' => 0.95,
            'status' => 'pending_approval',
        ]);
        $approval = AiAutomationApproval::create([
            'ai_extracted_data_id' => $extracted->id,
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'target_entity' => 'booking',
            'action' => 'reschedule_booking',
            'mode' => 'need_confirmation',
            'proposed_data' => ['booking' => [
                'booking_id' => $booking->id,
                'booking_code' => $booking->booking_code,
                'service_id' => $service->id,
                'booking_date' => now()->addDays(2)->toDateString(),
                'start_time' => '14:00:00',
            ]],
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.ai.data-automation.approvals.approve', $approval))
            ->assertRedirect(route('admin.ai.data-automation.approvals.index'));

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'content' => 'Baik Bunda, jadwal booking Baby Spa Premium sudah kami ubah dari '.now()->addDay()->format('d M Y').' pukul 10:00 menjadi '.now()->addDays(2)->format('d M Y').' pukul 14:00. Mohon hadir 10 menit sebelum jadwal baru ya Bunda.',
        ]);

        $this->actingAs($admin)
            ->put(route('admin.bookings.update', $booking->fresh()), [
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'service_id' => $service->id,
                'therapist_id' => $therapist->id,
                'conversation_id' => $conversation->id,
                'booking_date' => now()->addDays(2)->toDateString(),
                'start_time' => '14:00',
                'status' => BookingStatus::CANCELLED,
                'payment_status' => PaymentStatus::UNPAID,
            ])
            ->assertRedirect(route('admin.bookings.index'));

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'content' => 'Baik Bunda, booking Baby Spa Premium untuk '.now()->addDays(2)->format('d M Y').' pukul 14:00 sudah dibatalkan. Jika Bunda ingin membuat jadwal baru, silakan chat kami kembali kapan saja ya.',
        ]);
    }

    public function test_review_request_cancel_approval_updates_booking_status(): void
    {
        config()->set('waha.base_url', 'http://waha.test');
        Http::fake(['http://waha.test/api/sendText' => Http::response(['id' => 'wamid-booking-cancelled-review'], 200)]);

        [$admin, $customer, $branch, $service, $therapist] = $this->seedBookingData();
        $session = WhatsAppSession::create(['session_name' => 'default', 'status' => 'working']);
        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_session_id' => $session->id,
            'wa_chat_id' => $customer->whatsapp_number.'@c.us',
            'channel' => 'whatsapp',
            'status' => 'open',
            'ai_enabled' => true,
        ]);
        $booking = Booking::create([
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'therapist_id' => $therapist->id,
            'conversation_id' => $conversation->id,
            'created_by' => $admin->id,
            'booking_code' => 'BK-REVIEW-CANCEL-001',
            'booking_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => BookingStatus::CONFIRMED,
            'payment_status' => PaymentStatus::UNPAID,
            'source' => 'manual',
        ]);
        $extracted = AiExtractedData::create([
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'intent' => 'booking_cancel_request',
            'confidence_score' => 0.95,
            'status' => 'pending_approval',
        ]);
        $approval = AiAutomationApproval::create([
            'ai_extracted_data_id' => $extracted->id,
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'target_entity' => 'booking',
            'action' => 'cancel_booking',
            'mode' => 'need_confirmation',
            'proposed_data' => ['booking' => [
                'booking_code' => $booking->booking_code,
                'service_name' => $service->name,
                'booking_date' => $booking->booking_date?->toDateString(),
                'start_time' => $booking->start_time,
            ]],
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.ai.data-automation.approvals.approve', $approval))
            ->assertRedirect(route('admin.ai.data-automation.approvals.index'));

        $this->assertSame('approved', $approval->fresh()->status);
        $this->assertSame(BookingStatus::CANCELLED, $booking->fresh()->status);
    }

    public function test_approval_reschedule_allows_manual_customer_booking_on_lid_conversation(): void
    {
        config()->set('waha.base_url', 'http://waha.test');
        Http::fake(['http://waha.test/api/sendText' => Http::response(['id' => 'wamid-lid-reschedule'], 200)]);

        [$admin, $manualCustomer, $branch, $service, $therapist] = $this->seedBookingData();
        $manualCustomer->update([
            'phone' => '629984970786',
            'whatsapp_number' => '629984970786',
        ]);
        $lidCustomer = Customer::create([
            'name' => 'Customer LID',
            'phone' => '151152817635490',
            'whatsapp_number' => '151152817635490',
            'status' => CustomerStatus::LEAD,
        ]);
        $session = WhatsAppSession::create(['session_name' => 'default', 'status' => 'working']);
        $conversation = Conversation::create([
            'customer_id' => $lidCustomer->id,
            'whatsapp_session_id' => $session->id,
            'wa_chat_id' => '151152817635490@lid',
            'channel' => 'whatsapp',
            'status' => 'open',
            'ai_enabled' => true,
        ]);
        $booking = Booking::create([
            'customer_id' => $manualCustomer->id,
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'therapist_id' => $therapist->id,
            'conversation_id' => $conversation->id,
            'created_by' => $admin->id,
            'booking_code' => 'BK-LID-APPROVAL',
            'booking_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => BookingStatus::CONFIRMED,
            'payment_status' => PaymentStatus::UNPAID,
            'source' => 'ai_flow',
        ]);
        $extracted = AiExtractedData::create([
            'customer_id' => $lidCustomer->id,
            'conversation_id' => $conversation->id,
            'intent' => 'booking_reschedule_request',
            'confidence_score' => 0.95,
            'status' => 'pending_approval',
        ]);
        $approval = AiAutomationApproval::create([
            'ai_extracted_data_id' => $extracted->id,
            'customer_id' => $lidCustomer->id,
            'conversation_id' => $conversation->id,
            'target_entity' => 'booking',
            'action' => 'reschedule_booking',
            'mode' => 'need_confirmation',
            'proposed_data' => ['booking' => [
                'booking_id' => $booking->id,
                'booking_code' => $booking->booking_code,
                'service_id' => $service->id,
                'booking_date' => now()->addDays(2)->toDateString(),
                'start_time' => '14:00:00',
            ]],
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.ai.data-automation.approvals.approve', $approval))
            ->assertRedirect(route('admin.ai.data-automation.approvals.index'));

        $booking->refresh();

        $this->assertSame($manualCustomer->id, $booking->customer_id);
        $this->assertSame(now()->addDays(2)->toDateString(), $booking->booking_date?->toDateString());
        Http::assertSent(fn ($request) => $request->url() === 'http://waha.test/api/sendText'
            && data_get($request->data(), 'chatId') === '151152817635490@lid');
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'customer_id' => $lidCustomer->id,
            'wa_message_id' => 'wamid-lid-reschedule',
        ]);
    }

    public function test_completed_booking_update_sends_whatsapp_feedback_request(): void
    {
        config()->set('waha.base_url', 'http://waha.test');
        Http::fake(['http://waha.test/api/sendText' => Http::response(['id' => 'wamid-booking-completed'], 200)]);

        [$admin, $customer, $branch, $service, $therapist] = $this->seedBookingData();
        $session = WhatsAppSession::create(['session_name' => 'default', 'status' => 'working']);
        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_session_id' => $session->id,
            'wa_chat_id' => $customer->whatsapp_number.'@c.us',
            'channel' => 'whatsapp',
            'status' => 'open',
            'ai_enabled' => true,
        ]);
        $booking = Booking::create([
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'therapist_id' => $therapist->id,
            'conversation_id' => $conversation->id,
            'created_by' => $admin->id,
            'booking_code' => 'BK-COMPLETED-001',
            'booking_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => BookingStatus::CONFIRMED,
            'payment_status' => PaymentStatus::UNPAID,
            'source' => 'manual',
        ]);

        $this->actingAs($admin)
            ->put(route('admin.bookings.update', $booking), [
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'service_id' => $service->id,
                'therapist_id' => $therapist->id,
                'conversation_id' => $conversation->id,
                'booking_date' => now()->addDay()->toDateString(),
                'start_time' => '10:00',
                'status' => BookingStatus::COMPLETED,
                'payment_status' => PaymentStatus::UNPAID,
            ])
            ->assertRedirect(route('admin.bookings.index'));

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'content' => 'Terima kasih Bunda sudah berkunjung dan treatment di Gayatri. Jika ada saran atau masukan, silakan hubungi kami melalui chat ini ya. Bunda juga boleh beri rating pengalaman hari ini dari 1-5.',
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
