<?php

namespace Tests\Feature;

use App\Jobs\SendBookingReminderJob;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Reminder;
use App\Models\Service;
use App\Models\Therapist;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Services\WhatsApp\WahaService;
use App\Support\BookingStatus;
use App\Support\CustomerStatus;
use App\Support\PaymentStatus;
use App\Support\ReminderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReminderAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_booking_creates_h1_and_h0_reminders(): void
    {
        Http::fake(['http://waha.test/api/sendText' => Http::response(['id' => 'confirm-msg'], 200)]);
        config()->set('waha.base_url', 'http://waha.test');
        [$admin, $customer, $branch, $service, $therapist, $conversation] = $this->seedBookingData();

        $this->actingAs($admin)->post(route('admin.bookings.store'), [
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'therapist_id' => $therapist->id,
            'conversation_id' => $conversation->id,
            'booking_date' => now()->addDays(2)->toDateString(),
            'start_time' => '10:00',
            'status' => BookingStatus::CONFIRMED,
            'payment_status' => PaymentStatus::UNPAID,
        ])->assertRedirect(route('admin.bookings.index'));

        $booking = Booking::where('customer_id', $customer->id)->firstOrFail();

        $this->assertDatabaseHas('reminders', ['booking_id' => $booking->id, 'type' => 'h1', 'status' => ReminderStatus::SCHEDULED]);
        $this->assertDatabaseHas('reminders', ['booking_id' => $booking->id, 'type' => 'h0', 'status' => ReminderStatus::SCHEDULED]);
    }

    public function test_due_reminder_job_sends_whatsapp_and_marks_sent(): void
    {
        config()->set('waha.base_url', 'http://waha.test');
        Http::fake(['http://waha.test/api/sendText' => Http::response(['id' => 'reminder-msg-001'], 200)]);
        [, $customer, , $service, , $conversation] = $this->seedBookingData();
        $booking = $this->booking($customer, $service, $conversation);
        $reminder = Reminder::create([
            'booking_id' => $booking->id,
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'type' => 'h1',
            'channel' => 'whatsapp',
            'status' => ReminderStatus::SCHEDULED,
            'scheduled_at' => now()->subMinute(),
        ]);

        (new SendBookingReminderJob($reminder->id))->handle(app(WahaService::class));

        $this->assertDatabaseHas('reminders', ['id' => $reminder->id, 'status' => ReminderStatus::SENT]);
        $this->assertDatabaseHas('messages', ['wa_message_id' => 'reminder-msg-001', 'sender_type' => 'system']);
    }

    public function test_failed_reminder_is_marked_failed_and_can_be_retried(): void
    {
        config()->set('waha.base_url', 'http://waha.test');
        Http::fake([
            'http://waha.test/api/sendText' => Http::sequence()
                ->push([], 500)
                ->push(['id' => 'retry-msg-001'], 200),
        ]);
        [$admin, $customer, , $service, , $conversation] = $this->seedBookingData();
        $booking = $this->booking($customer, $service, $conversation);
        $reminder = Reminder::create([
            'booking_id' => $booking->id,
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'type' => 'h0',
            'channel' => 'whatsapp',
            'status' => ReminderStatus::SCHEDULED,
            'scheduled_at' => now()->subMinute(),
        ]);

        (new SendBookingReminderJob($reminder->id))->handle(app(WahaService::class));

        $this->assertSame(ReminderStatus::FAILED, $reminder->fresh()->status);
        $this->assertNotNull($reminder->fresh()->failed_reason);

        $this->actingAs($admin)->post(route('admin.reminders.retry', $reminder))->assertRedirect();

        $this->assertDatabaseHas('reminders', ['id' => $reminder->id, 'status' => ReminderStatus::SENT]);
        $this->assertDatabaseHas('messages', ['wa_message_id' => 'retry-msg-001', 'sender_type' => 'system']);
    }

    public function test_due_reminder_command_dispatches_due_reminders(): void
    {
        config()->set('waha.base_url', 'http://waha.test');
        Http::fake(['http://waha.test/api/sendText' => Http::response(['id' => 'command-reminder-001'], 200)]);
        [, $customer, , $service, , $conversation] = $this->seedBookingData('628123450777');
        $booking = $this->booking($customer, $service, $conversation);
        Reminder::create([
            'booking_id' => $booking->id,
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'type' => 'h1',
            'channel' => 'whatsapp',
            'status' => ReminderStatus::SCHEDULED,
            'scheduled_at' => now()->subMinute(),
        ]);

        $this->artisan('crm:send-due-reminders')->assertSuccessful();

        $this->assertDatabaseHas('messages', ['wa_message_id' => 'command-reminder-001']);
    }

    private function seedBookingData(string $phone = '628123450333'): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $branch = Branch::create(['name' => 'Gayatri Jakarta', 'code' => 'REM', 'status' => 'active']);
        $customer = Customer::create(['branch_id' => $branch->id, 'name' => 'Bunda Reminder', 'phone' => $phone, 'whatsapp_number' => $phone, 'status' => CustomerStatus::ACTIVE]);
        $service = Service::create(['branch_id' => $branch->id, 'name' => 'Baby Spa Reminder', 'duration_minutes' => 60, 'price' => 250000, 'is_active' => true]);
        $therapist = Therapist::create(['branch_id' => $branch->id, 'name' => 'Terapis Reminder', 'status' => 'active']);
        $session = WhatsAppSession::create(['session_name' => 'default', 'status' => 'working']);
        $conversation = Conversation::create(['customer_id' => $customer->id, 'whatsapp_session_id' => $session->id, 'wa_chat_id' => $phone.'@c.us', 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true]);

        return [$admin, $customer, $branch, $service, $therapist, $conversation];
    }

    private function booking(Customer $customer, Service $service, Conversation $conversation): Booking
    {
        return Booking::create([
            'customer_id' => $customer->id,
            'branch_id' => $service->branch_id,
            'service_id' => $service->id,
            'conversation_id' => $conversation->id,
            'booking_code' => 'BK-REM-'.uniqid(),
            'booking_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => BookingStatus::CONFIRMED,
            'payment_status' => PaymentStatus::UNPAID,
            'source' => 'manual',
        ]);
    }
}
