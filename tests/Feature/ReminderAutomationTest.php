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
use App\Support\ReminderType;
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

    public function test_due_reminder_uses_lid_conversation_even_when_customer_number_is_manual(): void
    {
        config()->set('waha.base_url', 'http://waha.test');
        Http::fake(['http://waha.test/api/sendText' => Http::response(['id' => 'reminder-lid-001'], 200)]);
        [, $customer, , $service] = $this->seedBookingData('629984970786');
        $lidCustomer = Customer::create([
            'name' => 'Customer LID',
            'phone' => '151152817635490',
            'whatsapp_number' => '151152817635490',
            'status' => CustomerStatus::LEAD,
        ]);
        $session = WhatsAppSession::firstOrCreate(['session_name' => 'default'], ['status' => 'working']);
        $conversation = Conversation::create([
            'customer_id' => $lidCustomer->id,
            'whatsapp_session_id' => $session->id,
            'wa_chat_id' => '151152817635490@lid',
            'channel' => 'whatsapp',
            'status' => 'open',
            'ai_enabled' => true,
        ]);
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

        $this->assertSame(ReminderStatus::SENT, $reminder->fresh()->status);
        Http::assertSent(fn ($request) => $request->url() === 'http://waha.test/api/sendText'
            && data_get($request->data(), 'chatId') === '151152817635490@lid');
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'wa_message_id' => 'reminder-lid-001',
        ]);
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

    public function test_admin_can_view_reminder_dashboard_and_filter_by_status(): void
    {
        [$admin, $customer, , $service, , $conversation] = $this->seedBookingData('628123451111');
        $booking = $this->booking($customer, $service, $conversation);

        Reminder::create([
            'booking_id' => $booking->id,
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'type' => ReminderType::PAYMENT,
            'channel' => 'whatsapp',
            'status' => ReminderStatus::FAILED,
            'scheduled_at' => now()->subMinutes(15),
            'message' => 'Reminder pembayaran untuk Bunda.',
            'failed_reason' => 'WAHA timeout',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.reminders.index', ['status' => ReminderStatus::FAILED]))
            ->assertOk()
            ->assertSee('Reminder Otomatis')
            ->assertSee('Reminder pembayaran untuk Bunda.')
            ->assertSee('WAHA timeout');
    }

    public function test_admin_can_create_manual_reminder_send_now_and_cancel(): void
    {
        config()->set('waha.base_url', 'http://waha.test');
        Http::fake(['http://waha.test/api/sendText' => Http::response(['id' => 'manual-reminder-001'], 200)]);

        [$admin, $customer, , $service, , $conversation] = $this->seedBookingData('628123452222');
        $booking = $this->booking($customer, $service, $conversation);

        $this->actingAs($admin)
            ->post(route('admin.reminders.store'), [
                'customer_id' => $customer->id,
                'booking_id' => $booking->id,
                'type' => ReminderType::FOLLOWUP,
                'channel' => 'whatsapp',
                'scheduled_at' => now()->addHour()->format('Y-m-d H:i:s'),
                'message' => 'Halo Bunda, jangan lupa treatment lanjutan minggu depan ya.',
            ])
            ->assertRedirect();

        $reminder = Reminder::where('customer_id', $customer->id)
            ->where('type', ReminderType::FOLLOWUP)
            ->firstOrFail();

        $this->assertSame('Halo Bunda, jangan lupa treatment lanjutan minggu depan ya.', $reminder->message);

        $this->actingAs($admin)
            ->post(route('admin.reminders.send-now', $reminder))
            ->assertRedirect();

        $this->assertDatabaseHas('reminders', [
            'id' => $reminder->id,
            'status' => ReminderStatus::SENT,
        ]);

        $this->assertDatabaseHas('messages', [
            'wa_message_id' => 'manual-reminder-001',
            'sender_type' => 'system',
        ]);

        $anotherReminder = Reminder::create([
            'booking_id' => $booking->id,
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'type' => ReminderType::RESCHEDULE,
            'channel' => 'whatsapp',
            'status' => ReminderStatus::SCHEDULED,
            'scheduled_at' => now()->addHours(2),
            'message' => 'Reminder reschedule.',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.reminders.cancel', $anotherReminder))
            ->assertRedirect();

        $this->assertDatabaseHas('reminders', [
            'id' => $anotherReminder->id,
            'status' => ReminderStatus::CANCELLED,
        ]);
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
