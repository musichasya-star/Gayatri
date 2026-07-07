<?php

namespace Tests\Feature;

use App\Jobs\ProcessAiAutomationRuleJob;
use App\Models\AiAutomationRule;
use App\Models\AiExtractedData;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Reminder;
use App\Models\Service;
use App\Models\WhatsAppSession;
use App\Support\BookingStatus;
use App\Support\CustomerStatus;
use App\Support\PaymentStatus;
use App\Support\ReminderStatus;
use App\Support\ReminderType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiAutomationReminderRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_reminder_rule_with_create_h1_reminder_creates_h1_scheduled_from_booking_time(): void
    {
        [$customer, $conversation] = $this->seedConversation('628123451601', 'Bunda Reminder H1');
        $booking = $this->seedBooking($customer, $conversation);

        AiAutomationRule::create([
            'name' => 'AI Reminder H1',
            'trigger_event' => 'message_extracted',
            'target_entity' => 'reminder',
            'action' => 'create_h1_reminder',
            'mode' => 'auto_create',
            'confidence_threshold' => 0.85,
            'required_fields' => [],
            'forbidden_intents' => [],
            'conditions' => null,
            'is_active' => true,
        ]);

        $message = $this->incomingMessage($conversation, $customer, 'Pengingat H1 dari AI.');
        $extracted = AiExtractedData::create([
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'customer_id' => $customer->id,
            'intent' => 'general_inquiry',
            'confidence_score' => 0.95,
            'extracted_customer_data' => ['name' => $customer->name],
            'extracted_booking_data' => ['booking_id' => $booking->id],
            'extracted_followup_data' => [
                'reason' => 'Reminder H-1 untuk persiapan treatment.',
            ],
            'missing_fields' => [],
            'raw_ai_response' => [
                'followup' => [
                    'reason' => 'Reminder H-1 untuk persiapan treatment.',
                ],
                'booking' => [
                    'booking_id' => $booking->id,
                ],
            ],
            'status' => 'extracted',
        ]);

        ProcessAiAutomationRuleJob::dispatch($extracted->id);

        $reminder = Reminder::query()
            ->where('customer_id', $customer->id)
            ->where('type', ReminderType::H1)
            ->firstOrFail();

        $this->assertSame(ReminderType::H1, $reminder->type);
        $this->assertSame(ReminderStatus::SCHEDULED, $reminder->status);
        $this->assertSame($booking->id, $reminder->booking_id);
        $this->assertSame('Reminder H-1 untuk persiapan treatment. Untuk Bunda Bunda Reminder H1.', $reminder->message);

        $expectedScheduledAt = Carbon::parse($booking->booking_date)->setTime(10, 0)->subDay();
        $this->assertSame($expectedScheduledAt->toDateTimeString(), $reminder->scheduled_at->toDateTimeString());
    }

    public function test_reminder_rule_with_create_reminder_uses_custom_type_and_custom_schedule(): void
    {
        [$customer, $conversation] = $this->seedConversation('628123451602', 'Bunda Reminder Generic');
        $scheduledAt = now()->addHours(3);

        AiAutomationRule::create([
            'name' => 'AI Reminder Generic',
            'trigger_event' => 'message_extracted',
            'target_entity' => 'reminder',
            'action' => 'create_reminder',
            'mode' => 'auto_create',
            'confidence_threshold' => 0.85,
            'required_fields' => [],
            'forbidden_intents' => [],
            'conditions' => null,
            'is_active' => true,
        ]);

        $message = $this->incomingMessage($conversation, $customer, 'Pengingat payment dari AI.');
        $extracted = AiExtractedData::create([
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'customer_id' => $customer->id,
            'intent' => 'general_inquiry',
            'confidence_score' => 0.95,
            'extracted_customer_data' => ['name' => $customer->name],
            'extracted_booking_data' => [],
            'extracted_followup_data' => [
                'reason' => 'Reminder pembayaran DP hari ini.',
                'reminder_type' => ReminderType::PAYMENT,
                'scheduled_at' => $scheduledAt->toDateTimeString(),
            ],
            'missing_fields' => [],
            'raw_ai_response' => [
                'followup' => [
                    'reason' => 'Reminder pembayaran DP hari ini.',
                    'reminder_type' => ReminderType::PAYMENT,
                ],
            ],
            'status' => 'extracted',
        ]);

        ProcessAiAutomationRuleJob::dispatch($extracted->id);

        $reminder = Reminder::query()
            ->where('customer_id', $customer->id)
            ->where('type', ReminderType::PAYMENT)
            ->firstOrFail();

        $this->assertSame(ReminderType::PAYMENT, $reminder->type);
        $this->assertSame(ReminderStatus::SCHEDULED, $reminder->status);
        $this->assertSame($scheduledAt->toDateTimeString(), $reminder->scheduled_at->toDateTimeString());
        $this->assertStringContainsString('Reminder pembayaran DP hari ini.', $reminder->message);
    }

    public function test_reminder_rule_with_create_h0_reminder_schedules_for_booking_time_and_includes_payload_metadata(): void
    {
        [$customer, $conversation] = $this->seedConversation('628123451603', 'Bunda Reminder H0');
        $booking = $this->seedBooking($customer, $conversation);

        $rule = AiAutomationRule::create([
            'name' => 'AI Reminder H0',
            'trigger_event' => 'message_extracted',
            'target_entity' => 'reminder',
            'action' => 'create_h0_reminder',
            'mode' => 'auto_create',
            'confidence_threshold' => 0.85,
            'required_fields' => [],
            'forbidden_intents' => [],
            'conditions' => null,
            'is_active' => true,
        ]);

        $message = $this->incomingMessage($conversation, $customer, 'Pengingat H0 dari AI.');
        $extracted = AiExtractedData::create([
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'customer_id' => $customer->id,
            'intent' => 'general_inquiry',
            'confidence_score' => 0.95,
            'extracted_customer_data' => ['name' => $customer->name],
            'extracted_booking_data' => ['booking_id' => $booking->id],
            'extracted_followup_data' => [
                'reason' => 'Reminder H0 agar tetap konfirmasi hari ini.',
            ],
            'missing_fields' => [],
            'raw_ai_response' => [
                'followup' => [
                    'reason' => 'Reminder H0 agar tetap konfirmasi hari ini.',
                ],
                'booking' => [
                    'booking_id' => $booking->id,
                ],
            ],
            'status' => 'extracted',
        ]);

        ProcessAiAutomationRuleJob::dispatch($extracted->id);

        $reminder = Reminder::query()
            ->where('customer_id', $customer->id)
            ->where('type', ReminderType::H0)
            ->firstOrFail();

        $this->assertSame(ReminderType::H0, $reminder->type);
        $this->assertSame(ReminderStatus::SCHEDULED, $reminder->status);
        $this->assertSame($booking->id, $reminder->booking_id);

        $expectedScheduledAt = Carbon::parse($booking->booking_date)
            ->setTimeFromTimeString(substr((string) $booking->start_time, 0, 8));

        $this->assertSame($expectedScheduledAt->toDateTimeString(), $reminder->scheduled_at->toDateTimeString());
        $this->assertSame('Reminder H0 agar tetap konfirmasi hari ini. Untuk Bunda Bunda Reminder H0.', $reminder->message);

        $this->assertSame([
            'source' => 'ai_automation',
            'rule_id' => $rule->id,
            'extracted_data_id' => $extracted->id,
        ], $reminder->payload);
    }

    public function test_reminder_rule_with_ambiguous_scheduled_at_string_falls_back_to_now(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-30 10:00:00'));

        try {
            [$customer, $conversation] = $this->seedConversation('628123451604', 'Bunda Reminder Ambiguous');

            AiAutomationRule::create([
                'name' => 'AI Reminder Ambiguous',
                'trigger_event' => 'message_extracted',
                'target_entity' => 'reminder',
                'action' => 'create_reminder',
                'mode' => 'auto_create',
                'confidence_threshold' => 0.85,
                'required_fields' => [],
                'forbidden_intents' => [],
                'conditions' => null,
                'is_active' => true,
            ]);

            $message = $this->incomingMessage($conversation, $customer, 'Pengingat dengan format waktu ambigu.');
            $extracted = AiExtractedData::create([
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'customer_id' => $customer->id,
                'intent' => 'general_inquiry',
                'confidence_score' => 0.95,
                'extracted_customer_data' => ['name' => $customer->name],
                'extracted_booking_data' => [],
                'extracted_followup_data' => [
                    'reason' => 'Reminder dengan format timezone ambigu.',
                    'scheduled_at' => '2026-07-02 00:00:00 10:00:00',
                ],
                'missing_fields' => [],
                'raw_ai_response' => [
                    'followup' => [
                        'reason' => 'Reminder dengan format timezone ambigu.',
                        'scheduled_at' => '2026-07-02 00:00:00 10:00:00',
                    ],
                ],
                'status' => 'extracted',
            ]);

            ProcessAiAutomationRuleJob::dispatch($extracted->id);

            $reminder = Reminder::query()
                ->where('customer_id', $customer->id)
                ->where('type', ReminderType::FOLLOWUP)
                ->firstOrFail();

            $this->assertSame(ReminderStatus::SCHEDULED, $reminder->status);
            $this->assertSame(Carbon::now()->toDateTimeString(), $reminder->scheduled_at->toDateTimeString());
            $this->assertStringContainsString('Reminder dengan format timezone ambigu.', $reminder->message);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_reminder_rule_with_unknown_timezone_token_falls_back_to_now(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-30 11:00:00'));

        try {
            [$customer, $conversation] = $this->seedConversation('628123451605', 'Bunda Reminder Timezone');

            AiAutomationRule::create([
                'name' => 'AI Reminder Unknown TZ',
                'trigger_event' => 'message_extracted',
                'target_entity' => 'reminder',
                'action' => 'create_reminder',
                'mode' => 'auto_create',
                'confidence_threshold' => 0.85,
                'required_fields' => [],
                'forbidden_intents' => [],
                'conditions' => null,
                'is_active' => true,
            ]);

            $message = $this->incomingMessage($conversation, $customer, 'Reminder timezone tidak dikenal.');
            $extracted = AiExtractedData::create([
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'customer_id' => $customer->id,
                'intent' => 'general_inquiry',
                'confidence_score' => 0.95,
                'extracted_customer_data' => ['name' => $customer->name],
                'extracted_booking_data' => [],
                'extracted_followup_data' => [
                    'reason' => 'Reminder dengan timezone tidak dikenal.',
                    'scheduled_at' => '2026-07-02 10:00:00 Asia/JakartaX',
                ],
                'missing_fields' => [],
                'raw_ai_response' => [
                    'followup' => [
                        'reason' => 'Reminder dengan timezone tidak dikenal.',
                        'scheduled_at' => '2026-07-02 10:00:00 Asia/JakartaX',
                    ],
                ],
                'status' => 'extracted',
            ]);

            ProcessAiAutomationRuleJob::dispatch($extracted->id);

            $reminder = Reminder::query()
                ->where('customer_id', $customer->id)
                ->where('type', ReminderType::FOLLOWUP)
                ->firstOrFail();

            $this->assertSame(ReminderStatus::SCHEDULED, $reminder->status);
            $this->assertSame(Carbon::now()->toDateTimeString(), $reminder->scheduled_at->toDateTimeString());
            $this->assertStringContainsString('Reminder dengan timezone tidak dikenal.', $reminder->message);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_reminder_type_from_raw_payload_uses_raw_timezone_malformed_and_falls_back_to_now(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-30 12:00:00'));

        try {
            [$customer, $conversation] = $this->seedConversation('628123451606', 'Bunda Reminder Raw');

            AiAutomationRule::create([
                'name' => 'AI Reminder Raw Payment',
                'trigger_event' => 'message_extracted',
                'target_entity' => 'reminder',
                'action' => 'create_reminder',
                'mode' => 'auto_create',
                'confidence_threshold' => 0.85,
                'required_fields' => [],
                'forbidden_intents' => [],
                'conditions' => null,
                'is_active' => true,
            ]);

            $message = $this->incomingMessage($conversation, $customer, 'Reminder raw payload payment.');
            $extracted = AiExtractedData::create([
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'customer_id' => $customer->id,
                'intent' => 'general_inquiry',
                'confidence_score' => 0.95,
                'extracted_customer_data' => ['name' => $customer->name],
                'extracted_booking_data' => [],
                'extracted_followup_data' => [
                    'reason' => 'Reminder dari payload raw.',
                ],
                'missing_fields' => [],
                'raw_ai_response' => [
                    'followup' => [
                        'reason' => 'Reminder dari payload raw.',
                        'scheduled_at' => '2026-07-02 10:00:00 Asia/JakartaX',
                    ],
                    'reminder' => [
                        'type' => ReminderType::PAYMENT,
                    ],
                ],
                'status' => 'extracted',
            ]);

            ProcessAiAutomationRuleJob::dispatch($extracted->id);

            $reminder = Reminder::query()
                ->where('customer_id', $customer->id)
                ->where('type', ReminderType::PAYMENT)
                ->firstOrFail();

            $this->assertSame(ReminderType::PAYMENT, $reminder->type);
            $this->assertSame(ReminderStatus::SCHEDULED, $reminder->status);
            $this->assertSame(Carbon::now()->toDateTimeString(), $reminder->scheduled_at->toDateTimeString());
            $this->assertSame('Reminder dari payload raw. Untuk Bunda Bunda Reminder Raw.', $reminder->message);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_reminder_uses_raw_ai_response_followup_scheduled_at_when_extracted_followup_data_missing(): void
    {
        $expectedScheduledAt = Carbon::parse('2026-07-02 10:00:00');

        [$customer, $conversation] = $this->seedConversation('628123451607', 'Bunda Reminder Raw Schedule');

        AiAutomationRule::create([
            'name' => 'AI Reminder Raw Schedule',
            'trigger_event' => 'message_extracted',
            'target_entity' => 'reminder',
            'action' => 'create_reminder',
            'mode' => 'auto_create',
            'confidence_threshold' => 0.85,
            'required_fields' => [],
            'forbidden_intents' => [],
            'conditions' => null,
            'is_active' => true,
        ]);

        $message = $this->incomingMessage($conversation, $customer, 'Reminder dari raw payload followup.');
        $extracted = AiExtractedData::create([
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'customer_id' => $customer->id,
            'intent' => 'general_inquiry',
            'confidence_score' => 0.95,
            'extracted_customer_data' => ['name' => $customer->name],
            'extracted_booking_data' => [],
            'extracted_followup_data' => [],
            'missing_fields' => [],
            'raw_ai_response' => [
                'followup' => [
                    'reason' => 'Reminder dari followup raw payload.',
                    'scheduled_at' => $expectedScheduledAt->toDateTimeString(),
                ],
            ],
            'status' => 'extracted',
        ]);

        ProcessAiAutomationRuleJob::dispatch($extracted->id);

        $reminder = Reminder::query()
            ->where('customer_id', $customer->id)
            ->where('type', ReminderType::FOLLOWUP)
            ->firstOrFail();

        $this->assertSame(ReminderType::FOLLOWUP, $reminder->type);
        $this->assertSame(ReminderStatus::SCHEDULED, $reminder->status);
        $this->assertSame($expectedScheduledAt->toDateTimeString(), $reminder->scheduled_at->toDateTimeString());
        $this->assertStringContainsString('Reminder dari followup raw payload.', $reminder->message);
    }

    public function test_reminder_uses_raw_ai_response_reminder_scheduled_at_when_followup_is_empty(): void
    {
        $expectedScheduledAt = Carbon::parse('2026-07-02 16:30:00');

        [$customer, $conversation] = $this->seedConversation('628123451608', 'Bunda Reminder ReminderNode');

        AiAutomationRule::create([
            'name' => 'AI Reminder Node',
            'trigger_event' => 'message_extracted',
            'target_entity' => 'reminder',
            'action' => 'create_reminder',
            'mode' => 'auto_create',
            'confidence_threshold' => 0.85,
            'required_fields' => [],
            'forbidden_intents' => [],
            'conditions' => null,
            'is_active' => true,
        ]);

        $message = $this->incomingMessage($conversation, $customer, 'Reminder payment dari reminder payload.');
        $extracted = AiExtractedData::create([
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'customer_id' => $customer->id,
            'intent' => 'general_inquiry',
            'confidence_score' => 0.95,
            'extracted_customer_data' => ['name' => $customer->name],
            'extracted_booking_data' => [],
            'extracted_followup_data' => [],
            'missing_fields' => [],
            'raw_ai_response' => [
                'followup' => [
                    'reason' => 'Reminder dari payload reminder block.',
                ],
                'reminder' => [
                    'type' => ReminderType::PAYMENT,
                    'scheduled_at' => $expectedScheduledAt->toDateTimeString(),
                ],
            ],
            'status' => 'extracted',
        ]);

        ProcessAiAutomationRuleJob::dispatch($extracted->id);

        $reminder = Reminder::query()
            ->where('customer_id', $customer->id)
            ->where('type', ReminderType::PAYMENT)
            ->firstOrFail();

        $this->assertSame(ReminderType::PAYMENT, $reminder->type);
        $this->assertSame(ReminderStatus::SCHEDULED, $reminder->status);
        $this->assertSame($expectedScheduledAt->toDateTimeString(), $reminder->scheduled_at->toDateTimeString());
        $this->assertStringContainsString('Reminder dari payload reminder block.', $reminder->message);
    }

    private function seedConversation(string $phone, string $name): array
    {
        $customer = Customer::create([
            'name' => $name,
            'phone' => $phone,
            'whatsapp_number' => $phone,
            'status' => CustomerStatus::LEAD,
        ]);

        $session = WhatsAppSession::create([
            'session_name' => 'default',
            'status' => 'working',
        ]);

        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_session_id' => $session->id,
            'wa_chat_id' => $phone.'@c.us',
            'channel' => 'whatsapp',
            'status' => 'open',
            'ai_enabled' => true,
        ]);

        return [$customer, $conversation];
    }

    private function seedBooking(Customer $customer, Conversation $conversation): Booking
    {
        $branch = Branch::create([
            'name' => 'Reminder Branch',
            'code' => 'RM'.uniqid(),
            'status' => 'active',
        ]);

        $service = Service::create([
            'name' => 'Baby Spa Reminder',
            'branch_id' => $branch->id,
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);

        return Booking::create([
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'booking_code' => 'BK-'.uniqid(),
            'booking_date' => now()->addDays(2)->toDateString(),
            'start_time' => '10:00:00',
            'status' => BookingStatus::CONFIRMED,
            'payment_status' => PaymentStatus::UNPAID,
            'source' => 'manual',
        ]);
    }

    private function incomingMessage(Conversation $conversation, Customer $customer, string $content): Message
    {
        return Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'wa_message_id' => 'wamid-reminder-test-'.sha1($content.$conversation->id),
            'direction' => 'incoming',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'content' => $content,
            'sent_at' => now(),
        ]);
    }
}
