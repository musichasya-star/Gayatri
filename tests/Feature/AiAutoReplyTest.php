<?php

namespace Tests\Feature;

use App\Models\AiPersona;
use App\Models\AiExtractedData;
use App\Models\AvailabilitySlot;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\KnowledgeBase;
use App\Models\Message;
use App\Models\Service;
use App\Models\WhatsAppSession;
use App\Services\AI\AiDataExtractionService;
use App\Services\AI\AiService;
use App\Services\AppSettingService;
use App\Support\AvailabilitySlotStatus;
use App\Support\BookingStatus;
use App\Support\ConversationStatus;
use App\Support\CustomerStatus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiAutoReplyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('waha.base_url', 'http://waha.test');
        config()->set('waha.default_session', 'default');
        config()->set('waha.webhook_secret', 'secret-123');
        config()->set('crm.default_ai_mode', 'auto_reply');
    }

    public function test_incoming_whatsapp_message_gets_ai_auto_reply_when_safe(): void
    {
        $this->seedAiSetup();

        Http::fake([
            'http://waha.test/api/sendText' => Http::response(['id' => 'wamid-ai-out-001'], 200),
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'secret-123'])
            ->postJson(route('webhooks.waha.messages'), [
                'event' => 'message',
                'session' => 'default',
                'payload' => [
                    'id' => 'wamid-ai-in-001',
                    'timestamp' => 1710000000,
                    'from' => '628123450001@c.us',
                    'fromMe' => false,
                    'body' => 'Halo, mau tanya jadwal baby spa',
                    'hasMedia' => false,
                ],
            ])
            ->assertOk();

        $conversation = Conversation::where('wa_chat_id', '628123450001@c.us')->firstOrFail();

        $this->assertSame(ConversationStatus::AI_HANDLED, $conversation->fresh()->status);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'wa_message_id' => 'wamid-ai-out-001',
            'direction' => 'outgoing',
            'sender_type' => 'ai',
        ]);
        $this->assertDatabaseHas('ai_logs', [
            'conversation_id' => $conversation->id,
            'status' => 'success',
            'fallback_reason' => null,
        ]);
    }

    public function test_medical_question_gets_fallback_and_escalates_to_admin(): void
    {
        $this->seedAiSetup();

        Http::fake([
            'http://waha.test/api/sendText' => Http::response(['id' => 'wamid-ai-medical-out-001'], 200),
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'secret-123'])
            ->postJson(route('webhooks.waha.messages'), [
                'event' => 'message',
                'session' => 'default',
                'payload' => [
                    'id' => 'wamid-ai-medical-in-001',
                    'timestamp' => 1710000000,
                    'from' => '628123450002@c.us',
                    'fromMe' => false,
                    'body' => 'Bayi saya demam, boleh baby spa?',
                    'hasMedia' => false,
                ],
            ])
            ->assertOk();

        $conversation = Conversation::where('wa_chat_id', '628123450002@c.us')->firstOrFail();

        $this->assertSame(ConversationStatus::ESCALATED, $conversation->fresh()->status);
        $this->assertFalse($conversation->fresh()->ai_enabled);
        $this->assertDatabaseHas('ai_logs', [
            'conversation_id' => $conversation->id,
            'status' => 'escalated',
            'fallback_reason' => 'medical',
        ]);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'sender_type' => 'ai',
            'wa_message_id' => 'wamid-ai-medical-out-001',
        ]);

        $reply = Message::where('wa_message_id', 'wamid-ai-medical-out-001')->value('content');
        $this->assertStringContainsString('dokter', $reply);
        $this->assertStringNotContainsString('admin manusia', $reply);
        $this->assertStringNotContainsString('forbidden', strtolower($reply));
        $this->assertStringNotContainsString('guardrail', strtolower($reply));
    }

    public function test_greeting_gets_conversational_reply_and_keeps_ai_enabled_for_next_message(): void
    {
        $this->seedAiSetup();

        Http::fake([
            'http://waha.test/api/sendText' => Http::sequence()
                ->push(['id' => 'wamid-ai-greeting-out-001'], 200)
                ->push(['id' => 'wamid-ai-greeting-out-002'], 200),
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'secret-123'])
            ->postJson(route('webhooks.waha.messages'), [
                'event' => 'message',
                'session' => 'default',
                'payload' => [
                    'id' => 'wamid-ai-greeting-in-001',
                    'timestamp' => 1710000000,
                    'from' => '628123450005@c.us',
                    'fromMe' => false,
                    'body' => 'Selamat sore',
                    'hasMedia' => false,
                ],
            ])
            ->assertOk();

        $conversation = Conversation::where('wa_chat_id', '628123450005@c.us')->firstOrFail();

        $this->assertTrue((bool) $conversation->fresh()->ai_enabled);
        $this->assertSame(ConversationStatus::AI_HANDLED, $conversation->fresh()->status);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'outgoing',
            'sender_type' => 'ai',
            'wa_message_id' => 'wamid-ai-greeting-out-001',
        ]);
        $this->assertStringContainsString('Selamat sore Bunda', Message::where('wa_message_id', 'wamid-ai-greeting-out-001')->value('content'));

        $this->withHeaders(['X-Webhook-Secret' => 'secret-123'])
            ->postJson(route('webhooks.waha.messages'), [
                'event' => 'message',
                'session' => 'default',
                'payload' => [
                    'id' => 'wamid-ai-greeting-in-002',
                    'timestamp' => 1710000001,
                    'from' => '628123450005@c.us',
                    'fromMe' => false,
                    'body' => 'Mau tanya jadwal baby spa',
                    'hasMedia' => false,
                ],
            ])
            ->assertOk();

        $this->assertTrue((bool) $conversation->fresh()->ai_enabled);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'outgoing',
            'sender_type' => 'ai',
            'wa_message_id' => 'wamid-ai-greeting-out-002',
        ]);
    }

    public function test_pijat_question_offers_available_services_instead_of_admin_fallback(): void
    {
        $this->seedAiSetup();
        Service::create(['name' => 'Baby Spa Premium', 'category' => 'baby-spa', 'duration_minutes' => 60, 'price' => 250000, 'is_active' => true]);
        Service::create(['name' => 'Mom Massage', 'category' => 'mom-massage', 'duration_minutes' => 60, 'price' => 300000, 'is_active' => true]);

        Http::fake(['http://waha.test/api/sendText' => Http::response(['id' => 'wamid-ai-pijat-out-001'], 200)]);

        $this->withHeaders(['X-Webhook-Secret' => 'secret-123'])
            ->postJson(route('webhooks.waha.messages'), [
                'event' => 'message',
                'session' => 'default',
                'payload' => [
                    'id' => 'wamid-ai-pijat-in-001',
                    'timestamp' => 1710000000,
                    'from' => '628123450033@c.us',
                    'fromMe' => false,
                    'body' => 'Saya ingin pijat',
                    'hasMedia' => false,
                ],
            ])
            ->assertOk();

        $conversation = Conversation::where('wa_chat_id', '628123450033@c.us')->firstOrFail();
        $reply = Message::where('conversation_id', $conversation->id)->where('direction', 'outgoing')->value('content');

        $this->assertSame(ConversationStatus::AI_HANDLED, $conversation->fresh()->status);
        $this->assertStringContainsString('layanan', strtolower($reply));
        $this->assertStringContainsString('Mom Massage', $reply);
        $this->assertStringNotContainsString('teruskan ke admin', strtolower($reply));
    }

    public function test_pijet_typo_offers_available_services_instead_of_admin_fallback(): void
    {
        $this->seedAiSetup();
        Service::create(['name' => 'Baby Spa Premium', 'category' => 'baby-spa', 'duration_minutes' => 60, 'price' => 250000, 'is_active' => true]);
        Service::create(['name' => 'Pijat Bayi Balita', 'category' => 'Pijat Bayi', 'duration_minutes' => 60, 'price' => 150000, 'is_active' => true]);

        Http::fake(['http://waha.test/api/sendText' => Http::response(['id' => 'wamid-ai-pijet-out-001'], 200)]);

        $this->withHeaders(['X-Webhook-Secret' => 'secret-123'])
            ->postJson(route('webhooks.waha.messages'), [
                'event' => 'message',
                'session' => 'default',
                'payload' => [
                    'id' => 'wamid-ai-pijet-in-001',
                    'timestamp' => 1710000000,
                    'from' => '628123450034@c.us',
                    'fromMe' => false,
                    'body' => 'saya mau pijet',
                    'hasMedia' => false,
                ],
            ])
            ->assertOk();

        $conversation = Conversation::where('wa_chat_id', '628123450034@c.us')->firstOrFail();
        $reply = Message::where('conversation_id', $conversation->id)->where('direction', 'outgoing')->value('content');

        $this->assertSame(ConversationStatus::AI_HANDLED, $conversation->fresh()->status);
        $this->assertStringContainsString('layanan', strtolower($reply));
        $this->assertStringContainsString('Pijat Bayi Balita', $reply);
        $this->assertStringNotContainsString('teruskan ke admin', strtolower($reply));
    }

    public function test_available_services_question_uses_numbered_list_without_schedule(): void
    {
        $this->seedAiSetup();
        Service::create(['name' => 'Baby Spa Premium', 'category' => 'baby-spa', 'duration_minutes' => 60, 'price' => 250000, 'is_active' => true]);
        Service::create(['name' => 'Mom Postnatal Massage', 'category' => 'mom-care', 'duration_minutes' => 60, 'price' => 300000, 'is_active' => true]);
        Service::create(['name' => 'Pijat Bayi Balita', 'category' => 'Pijat Bayi', 'duration_minutes' => 60, 'price' => 150000, 'is_active' => true]);

        Http::fake(['http://waha.test/api/sendText' => Http::response(['id' => 'wamid-ai-services-out-001'], 200)]);

        $this->withHeaders(['X-Webhook-Secret' => 'secret-123'])
            ->postJson(route('webhooks.waha.messages'), [
                'event' => 'message',
                'session' => 'default',
                'payload' => [
                    'id' => 'wamid-ai-services-in-001',
                    'timestamp' => 1710000000,
                    'from' => '628123450037@c.us',
                    'fromMe' => false,
                    'body' => 'layanan apa yang tersedia',
                    'hasMedia' => false,
                ],
            ])
            ->assertOk();

        $conversation = Conversation::where('wa_chat_id', '628123450037@c.us')->firstOrFail();
        $reply = Message::where('conversation_id', $conversation->id)->where('direction', 'outgoing')->value('content');

        $this->assertStringContainsString("1. Baby Spa Premium", $reply);
        $this->assertStringContainsString("2. Mom Postnatal Massage", $reply);
        $this->assertStringContainsString("3. Pijat Bayi Balita", $reply);
        $this->assertStringContainsString('reservasi', strtolower($reply));
        $this->assertStringNotContainsString('Slot yang tersedia', $reply);
        $this->assertStringNotContainsString('09:00', $reply);
    }

    public function test_service_price_question_uses_dashboard_service_prices(): void
    {
        $this->seedAiSetup();
        Service::create(['name' => 'Baby Spa Premium', 'category' => 'baby-spa', 'duration_minutes' => 60, 'price' => 250000, 'is_active' => true]);
        Service::create(['name' => 'Mom Postnatal Massage', 'category' => 'mom-care', 'duration_minutes' => 75, 'price' => 300000, 'is_active' => true]);
        Service::create(['name' => 'Pijat Bayi Balita', 'category' => 'Pijat Bayi', 'duration_minutes' => 45, 'price' => 150000, 'is_active' => true]);

        Http::fake(['http://waha.test/api/sendText' => Http::response(['id' => 'wamid-ai-prices-out-001'], 200)]);

        $this->withHeaders(['X-Webhook-Secret' => 'secret-123'])
            ->postJson(route('webhooks.waha.messages'), [
                'event' => 'message',
                'session' => 'default',
                'payload' => [
                    'id' => 'wamid-ai-prices-in-001',
                    'timestamp' => 1710000000,
                    'from' => '628123450042@c.us',
                    'fromMe' => false,
                    'body' => 'berapa harga layanan di gayatri?',
                    'hasMedia' => false,
                ],
            ])
            ->assertOk();

        $conversation = Conversation::where('wa_chat_id', '628123450042@c.us')->firstOrFail();
        $reply = Message::where('conversation_id', $conversation->id)->where('direction', 'outgoing')->value('content');

        $this->assertStringContainsString('1. Baby Spa Premium (60 menit) - Rp250.000', $reply);
        $this->assertStringContainsString('2. Mom Postnatal Massage (75 menit) - Rp300.000', $reply);
        $this->assertStringContainsString('3. Pijat Bayi Balita (45 menit) - Rp150.000', $reply);
        $this->assertStringContainsString('reservasi', strtolower($reply));
        $this->assertStringNotContainsString('teruskan ke admin', strtolower($reply));
    }

    public function test_specific_service_price_question_filters_matching_service(): void
    {
        $this->seedAiSetup();
        Service::create(['name' => 'Baby Spa Premium', 'category' => 'baby-spa', 'duration_minutes' => 60, 'price' => 250000, 'is_active' => true]);
        Service::create(['name' => 'Mom Postnatal Massage', 'category' => 'mom-care', 'duration_minutes' => 75, 'price' => 300000, 'is_active' => true]);

        Http::fake(['http://waha.test/api/sendText' => Http::response(['id' => 'wamid-ai-baby-price-out-001'], 200)]);

        $this->withHeaders(['X-Webhook-Secret' => 'secret-123'])
            ->postJson(route('webhooks.waha.messages'), [
                'event' => 'message',
                'session' => 'default',
                'payload' => [
                    'id' => 'wamid-ai-baby-price-in-001',
                    'timestamp' => 1710000000,
                    'from' => '628123450043@c.us',
                    'fromMe' => false,
                    'body' => 'harga baby spa premium berapa?',
                    'hasMedia' => false,
                ],
            ])
            ->assertOk();

        $conversation = Conversation::where('wa_chat_id', '628123450043@c.us')->firstOrFail();
        $reply = Message::where('conversation_id', $conversation->id)->where('direction', 'outgoing')->value('content');

        $this->assertStringContainsString('Baby Spa Premium (60 menit) - Rp250.000', $reply);
        $this->assertStringNotContainsString('Mom Postnatal Massage', $reply);
        $this->assertStringNotContainsString('Slot yang tersedia', $reply);
    }

    public function test_service_detail_question_does_not_reuse_previous_cancel_context(): void
    {
        $this->seedAiSetup();
        app(AppSettingService::class)->setMany([
            'ai.provider' => 'openrouter',
            'ai.model' => 'openai/gpt-4o-mini',
            'ai.temperature' => 0.3,
            'ai.max_tokens' => 800,
        ]);
        app(AppSettingService::class)->set('ai.api_key', 'sk-openrouter-test');

        $service = Service::create(['name' => 'Baby Spa Premium', 'category' => 'baby-spa', 'duration_minutes' => 60, 'price' => 250000, 'is_active' => true]);
        $customer = Customer::create(['name' => 'Bunda Cancel Context', 'whatsapp_number' => '628123450038', 'status' => CustomerStatus::LEAD]);
        $session = WhatsAppSession::create(['session_name' => 'default', 'status' => 'working']);
        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_session_id' => $session->id,
            'wa_chat_id' => '628123450038@c.us',
            'channel' => 'whatsapp',
            'status' => ConversationStatus::AI_HANDLED,
            'ai_enabled' => true,
        ]);
        $booking = Booking::create([
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'service_id' => $service->id,
            'booking_code' => 'BK-CANCEL-CONTEXT',
            'booking_date' => now()->addDay()->toDateString(),
            'start_time' => '15:00:00',
            'end_time' => '16:00:00',
            'status' => BookingStatus::CONFIRMED,
            'payment_status' => 'unpaid',
            'source' => 'manual',
        ]);
        AiExtractedData::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'intent' => 'booking_cancel_request',
            'confidence_score' => 0.95,
            'extracted_customer_data' => [],
            'extracted_booking_data' => [
                'action' => 'cancel_booking',
                'booking_id' => $booking->id,
                'booking_code' => $booking->booking_code,
                'booking_status' => BookingStatus::CONFIRMED,
                'service_id' => $service->id,
                'service_name' => $service->name,
                'booking_date' => $booking->booking_date?->toDateString(),
                'start_time' => $booking->start_time,
            ],
            'missing_fields' => [],
            'raw_ai_response' => [],
            'status' => 'awaiting_confirmation',
        ]);
        KnowledgeBase::create([
            'title' => 'Baby Spa Premium',
            'slug' => 'baby-spa-premium',
            'type' => 'text',
            'content' => 'Baby Spa Premium adalah layanan hydrotherapy dan pijat lembut bayi untuk membantu relaksasi dan kenyamanan si kecil.',
            'status' => 'active',
        ])->chunks()->create([
            'chunk_index' => 0,
            'content' => 'Baby Spa Premium adalah layanan hydrotherapy dan pijat lembut bayi.',
            'token_count' => 9,
        ]);

        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'Baby Spa Premium adalah layanan hydrotherapy dan pijat lembut bayi untuk membantu relaksasi si kecil, Bunda.'],
                ]],
            ], 200),
            'http://waha.test/api/sendText' => Http::response(['id' => 'wamid-ai-service-detail-out-001'], 200),
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'secret-123'])
            ->postJson(route('webhooks.waha.messages'), [
                'event' => 'message',
                'session' => 'default',
                'payload' => [
                    'id' => 'wamid-ai-service-detail-in-001',
                    'timestamp' => 1710000000,
                    'from' => '628123450038@c.us',
                    'fromMe' => false,
                    'body' => 'jelaskan tentang baby spa premium',
                    'hasMedia' => false,
                ],
            ])
            ->assertOk();

        $reply = Message::where('conversation_id', $conversation->id)->where('direction', 'outgoing')->latest('id')->value('content');

        $this->assertStringContainsString('Baby Spa Premium adalah layanan hydrotherapy', $reply);
        $this->assertStringNotContainsString('reservasi yang akan dibatalkan', strtolower($reply));
        $this->assertStringNotContainsString('Iya batalkan', $reply);
        $this->assertSame('provider', data_get($conversation->aiLogs()->latest('id')->first()->meta, 'reply_source'));
    }

    public function test_current_time_question_does_not_reuse_previous_cancel_context(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 21:19:00', config('app.timezone')));

        $this->seedAiSetup();
        $service = Service::create(['name' => 'Baby Spa Premium', 'category' => 'baby-spa', 'duration_minutes' => 60, 'price' => 250000, 'is_active' => true]);
        $customer = Customer::create(['name' => 'Bunda Time Context', 'whatsapp_number' => '628123450039', 'status' => CustomerStatus::LEAD]);
        $session = WhatsAppSession::create(['session_name' => 'default', 'status' => 'working']);
        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_session_id' => $session->id,
            'wa_chat_id' => '628123450039@c.us',
            'channel' => 'whatsapp',
            'status' => ConversationStatus::AI_HANDLED,
            'ai_enabled' => true,
        ]);
        $booking = Booking::create([
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'service_id' => $service->id,
            'booking_code' => 'BK-TIME-CONTEXT',
            'booking_date' => now()->addDay()->toDateString(),
            'start_time' => '15:00:00',
            'end_time' => '16:00:00',
            'status' => BookingStatus::CONFIRMED,
            'payment_status' => 'unpaid',
            'source' => 'manual',
        ]);
        AiExtractedData::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'intent' => 'booking_cancel_request',
            'confidence_score' => 0.95,
            'extracted_customer_data' => [],
            'extracted_booking_data' => [
                'action' => 'cancel_booking',
                'booking_id' => $booking->id,
                'booking_code' => $booking->booking_code,
                'booking_status' => BookingStatus::CONFIRMED,
                'service_id' => $service->id,
                'service_name' => $service->name,
                'booking_date' => $booking->booking_date?->toDateString(),
                'start_time' => $booking->start_time,
            ],
            'missing_fields' => [],
            'raw_ai_response' => [],
            'status' => 'awaiting_confirmation',
        ]);

        Http::fake(['http://waha.test/api/sendText' => Http::response(['id' => 'wamid-ai-time-out-001'], 200)]);

        $this->withHeaders(['X-Webhook-Secret' => 'secret-123'])
            ->postJson(route('webhooks.waha.messages'), [
                'event' => 'message',
                'session' => 'default',
                'payload' => [
                    'id' => 'wamid-ai-time-in-001',
                    'timestamp' => 1710000000,
                    'from' => '628123450039@c.us',
                    'fromMe' => false,
                    'body' => 'sekarang pagi atau malam ?',
                    'hasMedia' => false,
                ],
            ])
            ->assertOk();

        $reply = Message::where('conversation_id', $conversation->id)->where('direction', 'outgoing')->latest('id')->value('content');

        $this->assertStringContainsString('21:19', $reply);
        $this->assertStringContainsString('malam', strtolower($reply));
        $this->assertStringNotContainsString('reservasi yang akan dibatalkan', strtolower($reply));
        $this->assertStringNotContainsString('Iya batalkan', $reply);
        $this->assertSame('local', data_get($conversation->aiLogs()->latest('id')->first()->meta, 'reply_source'));

        Carbon::setTestNow();
    }

    public function test_availability_question_does_not_reuse_previous_cancel_context(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 21:32:00', config('app.timezone')));

        $this->seedAiSetup();
        $service = Service::create(['name' => 'Baby Spa Premium', 'category' => 'baby-spa', 'duration_minutes' => 60, 'price' => 250000, 'is_active' => true]);
        AvailabilitySlot::create([
            'service_id' => $service->id,
            'slot_date' => now()->addDay()->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'capacity' => 1,
            'booked_count' => 0,
            'status' => AvailabilitySlotStatus::AVAILABLE,
        ]);
        $customer = Customer::create(['name' => 'Bunda Schedule Context', 'whatsapp_number' => '628123450040', 'status' => CustomerStatus::LEAD]);
        $session = WhatsAppSession::create(['session_name' => 'default', 'status' => 'working']);
        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_session_id' => $session->id,
            'wa_chat_id' => '628123450040@c.us',
            'channel' => 'whatsapp',
            'status' => ConversationStatus::AI_HANDLED,
            'ai_enabled' => true,
        ]);
        $booking = Booking::create([
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'service_id' => $service->id,
            'booking_code' => 'BK-SCHEDULE-CONTEXT',
            'booking_date' => now()->toDateString(),
            'start_time' => '15:00:00',
            'end_time' => '16:00:00',
            'status' => BookingStatus::CONFIRMED,
            'payment_status' => 'unpaid',
            'source' => 'manual',
        ]);
        AiExtractedData::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'intent' => 'booking_cancel_request',
            'confidence_score' => 0.95,
            'extracted_customer_data' => [],
            'extracted_booking_data' => [
                'action' => 'cancel_booking',
                'booking_id' => $booking->id,
                'booking_code' => $booking->booking_code,
                'booking_status' => BookingStatus::CONFIRMED,
                'service_id' => $service->id,
                'service_name' => $service->name,
                'booking_date' => $booking->booking_date?->toDateString(),
                'start_time' => $booking->start_time,
            ],
            'missing_fields' => [],
            'raw_ai_response' => [],
            'status' => 'awaiting_confirmation',
        ]);

        Http::fake(['http://waha.test/api/sendText' => Http::response(['id' => 'wamid-ai-schedule-out-001'], 200)]);

        $this->withHeaders(['X-Webhook-Secret' => 'secret-123'])
            ->postJson(route('webhooks.waha.messages'), [
                'event' => 'message',
                'session' => 'default',
                'payload' => [
                    'id' => 'wamid-ai-schedule-in-001',
                    'timestamp' => 1710000000,
                    'from' => '628123450040@c.us',
                    'fromMe' => false,
                    'body' => 'jadwal yang ready untuk baby spa kapan ?',
                    'hasMedia' => false,
                ],
            ])
            ->assertOk();

        $reply = Message::where('conversation_id', $conversation->id)->where('direction', 'outgoing')->latest('id')->value('content');

        $this->assertStringContainsString('Slot yang tersedia terdekat', $reply);
        $this->assertStringContainsString('Baby Spa Premium', $reply);
        $this->assertStringContainsString('09:00', $reply);
        $this->assertStringNotContainsString('reservasi yang akan dibatalkan', strtolower($reply));
        $this->assertStringNotContainsString('Iya batalkan', $reply);
        $this->assertSame('local', data_get($conversation->aiLogs()->latest('id')->first()->meta, 'reply_source'));

        Carbon::setTestNow();
    }

    public function test_cancelled_booking_context_does_not_ask_for_cancel_confirmation_again(): void
    {
        $this->seedAiSetup();
        $service = Service::create(['name' => 'Baby Spa Premium', 'category' => 'baby-spa', 'duration_minutes' => 60, 'price' => 250000, 'is_active' => true]);
        $customer = Customer::create(['name' => 'Bunda Cancelled Booking', 'whatsapp_number' => '628123450041', 'status' => CustomerStatus::LEAD]);
        $session = WhatsAppSession::create(['session_name' => 'default', 'status' => 'working']);
        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_session_id' => $session->id,
            'wa_chat_id' => '628123450041@c.us',
            'channel' => 'whatsapp',
            'status' => ConversationStatus::AI_HANDLED,
            'ai_enabled' => true,
        ]);
        $booking = Booking::create([
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'service_id' => $service->id,
            'booking_code' => 'BK-GAY-260708-FKHJ',
            'booking_date' => now()->toDateString(),
            'start_time' => '15:00:00',
            'end_time' => '16:00:00',
            'status' => BookingStatus::CANCELLED,
            'payment_status' => 'unpaid',
            'source' => 'manual',
        ]);
        $oldMessage = Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'incoming',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'content' => 'batalkan booking saya',
            'sent_at' => now()->subMinute(),
        ]);
        AiExtractedData::create([
            'conversation_id' => $conversation->id,
            'message_id' => $oldMessage->id,
            'customer_id' => $customer->id,
            'intent' => 'booking_cancel_request',
            'confidence_score' => 0.95,
            'extracted_customer_data' => [],
            'extracted_booking_data' => [
                'action' => 'cancel_booking',
                'booking_id' => $booking->id,
                'booking_code' => $booking->booking_code,
                'booking_status' => BookingStatus::CONFIRMED,
                'service_id' => $service->id,
                'service_name' => $service->name,
                'booking_date' => $booking->booking_date?->toDateString(),
                'start_time' => $booking->start_time,
            ],
            'missing_fields' => [],
            'raw_ai_response' => [],
            'status' => 'awaiting_confirmation',
        ]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'incoming',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'content' => 'batalkan booking saya',
            'sent_at' => now(),
        ]);

        $result = app(AiService::class)->generateReply($conversation, $message);

        $this->assertStringContainsString('sudah berstatus cancelled', strtolower($result['reply']));
        $this->assertStringNotContainsString('reservasi yang akan dibatalkan', strtolower($result['reply']));
        $this->assertStringNotContainsString('Iya batalkan', $result['reply']);
    }

    public function test_service_choice_after_cancelled_context_starts_new_booking(): void
    {
        $this->seedAiSetup();
        $oldService = Service::create(['name' => 'Pijat Bayi Balita', 'category' => 'Pijat Bayi', 'duration_minutes' => 60, 'price' => 50000, 'is_active' => true]);
        Service::create(['name' => 'Girl Massage (13-20 Tahun) - 60Menit', 'category' => 'girl massage', 'duration_minutes' => 60, 'price' => 150000, 'is_active' => true]);
        $customer = Customer::create(['name' => 'Bunda Girl Massage', 'whatsapp_number' => '628123450044', 'status' => CustomerStatus::LEAD]);
        $session = WhatsAppSession::create(['session_name' => 'default', 'status' => 'working']);
        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_session_id' => $session->id,
            'wa_chat_id' => '628123450044@c.us',
            'channel' => 'whatsapp',
            'status' => ConversationStatus::AI_HANDLED,
            'ai_enabled' => true,
        ]);
        $booking = Booking::create([
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'service_id' => $oldService->id,
            'booking_code' => 'BK-GAY-260708-VDC5',
            'booking_date' => now()->toDateString(),
            'start_time' => '15:00:00',
            'end_time' => '16:00:00',
            'status' => BookingStatus::CANCELLED,
            'payment_status' => 'unpaid',
            'source' => 'manual',
        ]);
        AiExtractedData::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'intent' => 'booking_cancel_request',
            'confidence_score' => 0.95,
            'extracted_customer_data' => [],
            'extracted_booking_data' => [
                'action' => 'cancel_booking',
                'booking_id' => $booking->id,
                'booking_code' => $booking->booking_code,
                'booking_status' => BookingStatus::CONFIRMED,
                'service_id' => $oldService->id,
                'service_name' => $oldService->name,
                'booking_date' => $booking->booking_date?->toDateString(),
                'start_time' => $booking->start_time,
            ],
            'missing_fields' => [],
            'raw_ai_response' => [],
            'status' => 'awaiting_confirmation',
        ]);

        Http::fake(['http://waha.test/api/sendText' => Http::response(['id' => 'wamid-ai-girl-massage-out-001'], 200)]);

        $this->withHeaders(['X-Webhook-Secret' => 'secret-123'])
            ->postJson(route('webhooks.waha.messages'), [
                'event' => 'message',
                'session' => 'default',
                'payload' => [
                    'id' => 'wamid-ai-girl-massage-in-001',
                    'timestamp' => 1710000000,
                    'from' => '628123450044@c.us',
                    'fromMe' => false,
                    'body' => 'saya mau Girl Massage',
                    'hasMedia' => false,
                ],
            ])
            ->assertOk();

        $reply = Message::where('conversation_id', $conversation->id)->where('direction', 'outgoing')->latest('id')->value('content');

        $this->assertStringContainsString('Girl Massage (13-20 Tahun) - 60Menit sudah saya catat', $reply);
        $this->assertStringContainsString('hari atau tanggal kunjungan', $reply);
        $this->assertStringContainsString('perkiraan jam', $reply);
        $this->assertStringNotContainsString('sudah berstatus cancelled', strtolower($reply));
        $this->assertStringNotContainsString('Layanan yang tersedia saat ini', $reply);
    }

    public function test_member_card_question_uses_member_card_knowledge_first(): void
    {
        $this->seedAiSetup();
        KnowledgeBase::create([
            'title' => 'Benefit Member Card',
            'slug' => 'benefit-member-card',
            'type' => 'text',
            'content' => 'Kartu bisa ditukar dengan diskon di merchant Gayatri Mom & Baby SPA. Pemilik kartu berhak mendapatkan 1 poin dengan minimal transaksi Rp 40.000 berlaku kelipatan. Pemilik kartu berhak mendapatkan diskon 50% treatment dengan total 50 poin.',
            'status' => 'active',
        ])->chunks()->create([
            'chunk_index' => 0,
            'content' => 'Kartu bisa ditukar dengan diskon di merchant Gayatri Mom & Baby SPA. Pemilik kartu berhak mendapatkan 1 poin dengan minimal transaksi Rp 40.000 berlaku kelipatan.',
            'token_count' => 22,
        ]);
        KnowledgeBase::create([
            'title' => 'Tentang Gayatri',
            'slug' => 'tentang-gayatri',
            'type' => 'text',
            'content' => 'Gayatri Mom & Baby SPA adalah pertama dan satu-satunya Baby SPA di Kediri yang memberikan pelayanan aman.',
            'status' => 'active',
        ])->chunks()->create([
            'chunk_index' => 0,
            'content' => 'Gayatri Mom & Baby SPA adalah pertama dan satu-satunya Baby SPA di Kediri yang memberikan pelayanan aman.',
            'token_count' => 14,
        ]);

        Http::fake(['http://waha.test/api/sendText' => Http::response(['id' => 'wamid-ai-member-card-out-001'], 200)]);

        $this->withHeaders(['X-Webhook-Secret' => 'secret-123'])
            ->postJson(route('webhooks.waha.messages'), [
                'event' => 'message',
                'session' => 'default',
                'payload' => [
                    'id' => 'wamid-ai-member-card-in-001',
                    'timestamp' => 1710000000,
                    'from' => '628123450035@c.us',
                    'fromMe' => false,
                    'body' => 'jelaskan tentang member card',
                    'hasMedia' => false,
                ],
            ])
            ->assertOk();

        $conversation = Conversation::where('wa_chat_id', '628123450035@c.us')->firstOrFail();
        $reply = Message::where('conversation_id', $conversation->id)->where('direction', 'outgoing')->value('content');

        $this->assertStringContainsString('Member Card', $reply);
        $this->assertStringContainsString('diskon', strtolower($reply));
        $this->assertStringContainsString('1 poin', strtolower($reply));
        $this->assertStringContainsString('50%', $reply);
        $this->assertStringNotContainsString('pertama dan satu-satunya', strtolower($reply));
    }

    public function test_pesan_baby_spa_starts_step_booking_flow_without_old_template(): void
    {
        $this->seedAiSetup();
        Service::create(['name' => 'Baby Spa Premium', 'category' => 'baby-spa', 'duration_minutes' => 60, 'price' => 250000, 'is_active' => true]);

        Http::fake(['http://waha.test/api/sendText' => Http::response(['id' => 'wamid-ai-book-flow-out-001'], 200)]);

        $this->withHeaders(['X-Webhook-Secret' => 'secret-123'])
            ->postJson(route('webhooks.waha.messages'), [
                'event' => 'message',
                'session' => 'default',
                'payload' => [
                    'id' => 'wamid-ai-book-flow-in-001',
                    'timestamp' => 1710000000,
                    'from' => '628123450034@c.us',
                    'fromMe' => false,
                    'body' => 'Saya ingin pesan baby spa',
                    'hasMedia' => false,
                ],
            ])
            ->assertOk();

        $conversation = Conversation::where('wa_chat_id', '628123450034@c.us')->firstOrFail();
        $reply = Message::where('conversation_id', $conversation->id)->where('direction', 'outgoing')->value('content');

        $this->assertStringContainsString('atas nama siapa', $reply);
        $this->assertStringNotContainsString('template berikut', strtolower($reply));
        $this->assertStringNotContainsString('1. Nama', $reply);
    }

    public function test_unknown_question_does_not_disable_ai_for_next_message(): void
    {
        $this->seedAiSetup();

        Http::fake([
            'http://waha.test/api/sendText' => Http::response(['id' => 'wamid-ai-unknown-out-001'], 200),
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'secret-123'])
            ->postJson(route('webhooks.waha.messages'), [
                'event' => 'message',
                'session' => 'default',
                'payload' => [
                    'id' => 'wamid-ai-unknown-in-001',
                    'timestamp' => 1710000000,
                    'from' => '628123450006@c.us',
                    'fromMe' => false,
                    'body' => 'Apakah ada parkir valet?',
                    'hasMedia' => false,
                ],
            ])
            ->assertOk();

        $conversation = Conversation::where('wa_chat_id', '628123450006@c.us')->firstOrFail();

        $this->assertSame(ConversationStatus::NEED_FOLLOWUP, $conversation->fresh()->status);
        $this->assertTrue((bool) $conversation->fresh()->ai_enabled);
        $this->assertDatabaseHas('ai_logs', [
            'conversation_id' => $conversation->id,
            'fallback_reason' => 'no_knowledge',
        ]);
    }

    public function test_auto_reply_uses_configured_openrouter_provider(): void
    {
        $this->seedAiSetup();
        app(AppSettingService::class)->setMany([
            'ai.provider' => 'openrouter',
            'ai.model' => 'deepseek/deepseek-chat',
            'ai.temperature' => 0.4,
            'ai.max_tokens' => 300,
        ]);
        app(AppSettingService::class)->set('ai.api_key', 'sk-openrouter-test');

        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'Provider AI: Baby spa membantu relaksasi si kecil, Bunda.'],
                ]],
            ], 200),
            'http://waha.test/api/sendText' => Http::response(['id' => 'wamid-provider-out-001'], 200),
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'secret-123'])
            ->postJson(route('webhooks.waha.messages'), [
                'event' => 'message',
                'session' => 'default',
                'payload' => [
                    'id' => 'wamid-provider-in-001',
                    'timestamp' => 1710000000,
                    'from' => '628123450007@c.us',
                    'fromMe' => false,
                    'body' => 'Info baby spa untuk bayi?',
                    'hasMedia' => false,
                ],
            ])
            ->assertOk();

        Http::assertSent(fn ($request) => $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer sk-openrouter-test')
            && data_get($request->data(), 'model') === 'deepseek/deepseek-chat');

        $this->assertDatabaseHas('messages', [
            'wa_message_id' => 'wamid-provider-out-001',
            'sender_type' => 'ai',
            'content' => 'Provider AI: Baby spa membantu relaksasi si kecil, Bunda.',
        ]);
        $this->assertSame('provider', data_get(Conversation::where('wa_chat_id', '628123450007@c.us')->firstOrFail()->aiLogs()->latest('id')->first()->meta, 'reply_source'));
    }

    public function test_external_provider_receives_only_high_relevance_knowledge(): void
    {
        $this->seedAiSetup();
        app(AppSettingService::class)->setMany([
            'ai.provider' => 'openrouter',
            'ai.model' => 'openai/gpt-4o-mini',
            'ai.temperature' => 0.3,
            'ai.max_tokens' => 800,
        ]);
        app(AppSettingService::class)->set('ai.api_key', 'sk-openrouter-test');

        KnowledgeBase::create([
            'title' => 'pemilik Gayatri',
            'slug' => 'pemilik-gayatri',
            'type' => 'text',
            'content' => 'pemilik Gayatri Mom & baby Spa adalah dr. Amira Tauhida. dia adalah dokter umum dan konselor menyusui di Kota Kediri.',
            'status' => 'active',
        ])->chunks()->create([
            'chunk_index' => 0,
            'content' => 'pemilik Gayatri Mom & baby Spa adalah dr. Amira Tauhida.',
            'token_count' => 8,
        ]);
        KnowledgeBase::create([
            'title' => 'Benefit Member Card',
            'slug' => 'benefit-member-card',
            'type' => 'text',
            'content' => 'Kartu bisa ditukar dengan diskon di merchant Gayatri Mom & Baby SPA. Pemilik kartu berhak mendapatkan 1 poin.',
            'status' => 'active',
        ])->chunks()->create([
            'chunk_index' => 0,
            'content' => 'Pemilik kartu berhak mendapatkan 1 poin.',
            'token_count' => 6,
        ]);

        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'Pemilik Gayatri adalah dr. Amira Tauhida, Bunda.'],
                ]],
            ], 200),
            'http://waha.test/api/sendText' => Http::response(['id' => 'wamid-provider-owner-out-001'], 200),
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'secret-123'])
            ->postJson(route('webhooks.waha.messages'), [
                'event' => 'message',
                'session' => 'default',
                'payload' => [
                    'id' => 'wamid-provider-owner-in-001',
                    'timestamp' => 1710000000,
                    'from' => '628123450036@c.us',
                    'fromMe' => false,
                    'body' => 'siapa pemilik gayatri',
                    'hasMedia' => false,
                ],
            ])
            ->assertOk();

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://openrouter.ai/api/v1/chat/completions') {
                return false;
            }

            $systemPrompt = data_get($request->data(), 'messages.0.content', '');

            return str_contains($systemPrompt, 'pemilik Gayatri')
                && str_contains($systemPrompt, 'dr. Amira Tauhida')
                && ! str_contains($systemPrompt, 'Benefit Member Card');
        });
    }

    public function test_ai_does_not_reply_when_conversation_is_taken_over_by_human(): void
    {
        $this->seedAiSetup();

        Http::fake([
            'http://waha.test/api/sendText' => Http::response(['id' => 'should-not-send'], 200),
        ]);

        $customer = Customer::create([
            'name' => 'Bunda Takeover',
            'whatsapp_number' => '628123450003',
            'status' => CustomerStatus::LEAD,
        ]);
        $session = WhatsAppSession::create(['session_name' => 'default', 'status' => 'working']);
        Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_session_id' => $session->id,
            'wa_chat_id' => '628123450003@c.us',
            'channel' => 'whatsapp',
            'status' => ConversationStatus::HUMAN_HANDLED,
            'ai_enabled' => false,
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'secret-123'])
            ->postJson(route('webhooks.waha.messages'), [
                'event' => 'message',
                'session' => 'default',
                'payload' => [
                    'id' => 'wamid-ai-human-in-001',
                    'timestamp' => 1710000000,
                    'from' => '628123450003@c.us',
                    'fromMe' => false,
                    'body' => 'Masih ada jadwal?',
                    'hasMedia' => false,
                ],
            ])
            ->assertOk();

        $this->assertDatabaseMissing('messages', [
            'wa_message_id' => 'should-not-send',
            'sender_type' => 'ai',
        ]);
        $this->assertSame(0, Message::where('sender_type', 'ai')->count());
    }

    public function test_new_incoming_message_reopens_closed_conversation_but_does_not_reply_if_ai_disabled(): void
    {
        $this->seedAiSetup();

        Http::fake([
            'http://waha.test/api/sendText' => Http::response(['id' => 'should-not-send-closed'], 200),
        ]);

        $customer = Customer::create([
            'name' => 'Bunda Closed',
            'whatsapp_number' => '628123450004',
            'status' => CustomerStatus::LEAD,
        ]);
        $session = WhatsAppSession::create(['session_name' => 'default', 'status' => 'working']);
        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_session_id' => $session->id,
            'wa_chat_id' => '628123450004@c.us',
            'channel' => 'whatsapp',
            'status' => ConversationStatus::CLOSED,
            'ai_enabled' => false,
            'closed_at' => now()->subDay(),
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'secret-123'])
            ->postJson(route('webhooks.waha.messages'), [
                'event' => 'message',
                'session' => 'default',
                'payload' => [
                    'id' => 'wamid-ai-closed-in-001',
                    'timestamp' => 1710000000,
                    'from' => '628123450004@c.us',
                    'fromMe' => false,
                    'body' => 'Halo admin, mau tanya lagi.',
                    'hasMedia' => false,
                ],
            ])
            ->assertOk();

        $this->assertSame(ConversationStatus::OPEN, $conversation->fresh()->status);
        $this->assertFalse($conversation->fresh()->ai_enabled);
        $this->assertSame(0, Message::where('sender_type', 'ai')->count());
    }

    public function test_short_booking_confirmation_gets_booking_template_instead_of_no_knowledge_when_data_incomplete(): void
    {
        config()->set('ai.provider', 'local');
        $this->seedAiSetup();
        $customer = Customer::create([
            'name' => 'Bunda Konfirmasi',
            'phone' => '628123450008',
            'whatsapp_number' => '628123450008',
            'status' => CustomerStatus::LEAD,
        ]);
        $session = WhatsAppSession::create(['session_name' => 'default', 'status' => 'working']);
        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_session_id' => $session->id,
            'wa_chat_id' => '628123450008@c.us',
            'channel' => 'whatsapp',
            'status' => ConversationStatus::OPEN,
            'ai_enabled' => true,
        ]);
        $service = Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
        AvailabilitySlot::create([
            'service_id' => $service->id,
            'slot_date' => now()->addDay()->toDateString(),
            'start_time' => '16:00:00',
            'end_time' => '17:00:00',
            'capacity' => 1,
            'booked_count' => 0,
            'status' => AvailabilitySlotStatus::AVAILABLE,
        ]);
        app(AiDataExtractionService::class)->extractFromMessage(Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'incoming',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'content' => 'Saya ingin booking baby spa besok jam 4 sore',
            'sent_at' => now(),
        ]));
        Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'outgoing',
            'sender_type' => 'ai',
            'message_type' => 'text',
            'content' => 'Kami bisa bantu Baby Spa Premium besok pukul 16:00. Bunda mau pilih slot ini?',
            'sent_at' => now(),
        ]);
        $confirmation = Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'incoming',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'content' => 'Iya saya mau',
            'sent_at' => now(),
        ]);

        $result = app(AiService::class)->generateReply($conversation, $confirmation);

        $this->assertSame('success', $result['status']);
        $this->assertNull($result['fallback_reason']);
        $this->assertStringContainsString('Nama:', $result['reply']);
        $this->assertStringContainsString('Alamat:', $result['reply']);
        $this->assertStringContainsString('Jam reservasi:', $result['reply']);
        $this->assertStringNotContainsString('belum bisa memastikan', $result['reply']);
    }

    public function test_booking_rejection_reply_does_not_fall_back_to_no_knowledge(): void
    {
        config()->set('ai.provider', 'local');
        $this->seedAiSetup();
        $customer = Customer::create([
            'name' => 'Putri',
            'phone' => '628123450009',
            'whatsapp_number' => '628123450009',
            'address' => 'Kediri',
            'status' => CustomerStatus::LEAD,
        ]);
        $session = WhatsAppSession::create(['session_name' => 'default', 'status' => 'working']);
        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_session_id' => $session->id,
            'wa_chat_id' => '628123450009@c.us',
            'channel' => 'whatsapp',
            'status' => ConversationStatus::OPEN,
            'ai_enabled' => true,
        ]);
        $service = Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
        AvailabilitySlot::create([
            'service_id' => $service->id,
            'slot_date' => '2026-06-26',
            'start_time' => '15:00:00',
            'end_time' => '16:00:00',
            'capacity' => 1,
            'booked_count' => 0,
            'status' => AvailabilitySlotStatus::AVAILABLE,
        ]);
        app(AiDataExtractionService::class)->extractFromMessage(Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'incoming',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'content' => 'nama putri, alamat kediri, tanggal 26, jam 3 sore',
            'sent_at' => now(),
        ]));
        Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'outgoing',
            'sender_type' => 'ai',
            'message_type' => 'text',
            'content' => "Data reservasi sudah lengkap, Bunda. Mohon konfirmasi apakah reservasi ini akan dilanjutkan:\nLayanan: Baby Spa Premium\nTanggal: 2026-06-26\nJam: 15:00\n\nBalas dengan: Iya lanjutkan / Tidak.",
            'sent_at' => now(),
        ]);
        $rejection = Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'incoming',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'content' => 'tidak',
            'sent_at' => now(),
        ]);

        $extracted = app(AiDataExtractionService::class)->extractFromMessage($rejection);
        $result = app(AiService::class)->generateReply($conversation, $rejection);

        $this->assertSame('booking_request', $extracted->intent);
        $this->assertSame('rejected', data_get($extracted->raw_ai_response, 'booking_confirmation'));
        $this->assertSame('success', $result['status']);
        $this->assertStringContainsString('reservasi tidak kami lanjutkan', $result['reply']);
        $this->assertStringNotContainsString('belum bisa memastikan', $result['reply']);
    }

    public function test_partial_booking_data_is_merged_with_missing_service_reply_before_provider(): void
    {
        config()->set('ai.provider', 'openrouter');
        config()->set('ai.api_key', 'test-key');
        config()->set('ai.model', 'test-model');
        $this->seedAiSetup();
        $customer = Customer::create([
            'name' => 'Customer Partial Booking',
            'phone' => '628123450020',
            'whatsapp_number' => '628123450020',
            'status' => CustomerStatus::LEAD,
        ]);
        $session = WhatsAppSession::create(['session_name' => 'default', 'status' => 'working']);
        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_session_id' => $session->id,
            'wa_chat_id' => '628123450020@c.us',
            'channel' => 'whatsapp',
            'status' => ConversationStatus::OPEN,
            'ai_enabled' => true,
        ]);
        $service = Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
        AvailabilitySlot::create([
            'service_id' => $service->id,
            'slot_date' => now()->year.'-06-26',
            'start_time' => '17:00:00',
            'end_time' => '18:00:00',
            'capacity' => 1,
            'booked_count' => 0,
            'status' => AvailabilitySlotStatus::AVAILABLE,
        ]);
        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Mohon isi template dari awal.']]],
            ], 200),
        ]);

        app(AiDataExtractionService::class)->extractFromMessage(Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'incoming',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'content' => 'nama krisna, alamat kweden kediri, tanggal 26 juni, jam 5 sore',
            'sent_at' => now(),
        ]));
        $serviceReply = Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'incoming',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'content' => 'saya ingin baby spa',
            'sent_at' => now(),
        ]);

        $extracted = app(AiDataExtractionService::class)->extractFromMessage($serviceReply);
        $result = app(AiService::class)->generateReply($conversation, $serviceReply);

        $this->assertSame('booking_request', $extracted->intent);
        $this->assertSame([], $extracted->missing_fields);
        $this->assertStringContainsString('data reservasinya sudah lengkap', $result['reply']);
        $this->assertStringContainsString(now()->year.'-06-26', $result['reply']);
        $this->assertStringContainsString('17:00', $result['reply']);
        $this->assertStringNotContainsString('template dari awal', $result['reply']);
    }

    public function test_thanks_after_booking_confirmation_does_not_repeat_booking_details(): void
    {
        config()->set('ai.provider', 'local');
        $this->seedAiSetup();
        $customer = Customer::create([
            'name' => 'Bunda Thanks Booking',
            'phone' => '628123450021',
            'whatsapp_number' => '628123450021',
            'address' => 'Kediri',
            'status' => CustomerStatus::LEAD,
        ]);
        $session = WhatsAppSession::create(['session_name' => 'default', 'status' => 'working']);
        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_session_id' => $session->id,
            'wa_chat_id' => '628123450021@c.us',
            'channel' => 'whatsapp',
            'status' => ConversationStatus::OPEN,
            'ai_enabled' => true,
        ]);
        $service = Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
        AvailabilitySlot::create([
            'service_id' => $service->id,
            'slot_date' => '2026-06-26',
            'start_time' => '16:00:00',
            'end_time' => '17:00:00',
            'capacity' => 1,
            'booked_count' => 0,
            'status' => AvailabilitySlotStatus::AVAILABLE,
        ]);
        app(AiDataExtractionService::class)->extractFromMessage(Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'incoming',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'content' => 'booking nama putri, alamat kediri, tanggal 26 juni, jam 4 sore layanan baby spa',
            'sent_at' => now(),
        ]));
        Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'outgoing',
            'sender_type' => 'ai',
            'message_type' => 'text',
            'content' => "Siap Bunda, data reservasinya sudah lengkap. Mohon cek kembali detail berikut:\nLayanan: Baby Spa Premium\nTanggal: 2026-06-26\nJam: 16:00",
            'sent_at' => now(),
        ]);
        $thanks = Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'incoming',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'content' => 'baik terima kasih',
            'sent_at' => now(),
        ]);

        $extracted = app(AiDataExtractionService::class)->extractFromMessage($thanks);
        $result = app(AiService::class)->generateReply($conversation, $thanks);

        $this->assertSame('general_inquiry', $extracted->intent);
        $this->assertSame([], $extracted->extracted_booking_data);
        $this->assertStringContainsString('Sama-sama Bunda', $result['reply']);
        $this->assertStringNotContainsString('data reservasinya sudah lengkap', $result['reply']);
        $this->assertStringNotContainsString('Baby Spa Premium', $result['reply']);
        $this->assertStringNotContainsString('2026-06-26', $result['reply']);
    }

    public function test_general_question_after_booking_confirmation_does_not_repeat_booking_details(): void
    {
        config()->set('ai.provider', 'openrouter');
        config()->set('ai.api_key', 'test-key');
        config()->set('ai.model', 'test-model');
        $this->seedAiSetup();
        $customer = Customer::create([
            'name' => 'Bunda General After Booking',
            'phone' => '628123450022',
            'whatsapp_number' => '628123450022',
            'address' => 'Kediri',
            'status' => CustomerStatus::LEAD,
        ]);
        $session = WhatsAppSession::create(['session_name' => 'default', 'status' => 'working']);
        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_session_id' => $session->id,
            'wa_chat_id' => '628123450022@c.us',
            'channel' => 'whatsapp',
            'status' => ConversationStatus::OPEN,
            'ai_enabled' => true,
        ]);
        $service = Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
        AvailabilitySlot::create([
            'service_id' => $service->id,
            'slot_date' => '2026-06-26',
            'start_time' => '16:00:00',
            'end_time' => '17:00:00',
            'capacity' => 1,
            'booked_count' => 0,
            'status' => AvailabilitySlotStatus::AVAILABLE,
        ]);
        app(AiDataExtractionService::class)->extractFromMessage(Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'incoming',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'content' => 'booking nama putri, alamat kediri, tanggal 26 juni, jam 4 sore layanan baby spa',
            'sent_at' => now(),
        ]));
        Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'outgoing',
            'sender_type' => 'ai',
            'message_type' => 'text',
            'content' => "Siap Bunda, data reservasinya sudah lengkap. Mohon cek kembali detail berikut:\nLayanan: Baby Spa Premium\nTanggal: 2026-06-26\nJam: 16:00",
            'sent_at' => now(),
        ]);
        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Saya Gayatri Assistant, siap membantu Bunda.']]],
            ], 200),
        ]);
        $question = Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'incoming',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'content' => 'kamu namanya siapa?',
            'sent_at' => now(),
        ]);

        $extracted = app(AiDataExtractionService::class)->extractFromMessage($question);
        $result = app(AiService::class)->generateReply($conversation, $question);

        $this->assertSame('general_inquiry', $extracted->intent);
        $this->assertSame([], $extracted->extracted_booking_data);
        $this->assertStringContainsString('Gayatri Assistant', $result['reply']);
        $this->assertStringNotContainsString('data reservasinya sudah lengkap', $result['reply']);
        $this->assertStringNotContainsString('Baby Spa Premium', $result['reply']);
        $this->assertStringNotContainsString('2026-06-26', $result['reply']);
    }

    public function test_operational_hours_question_after_incomplete_booking_context_does_not_repeat_missing_fields(): void
    {
        $this->seedAiSetup();
        $customer = Customer::create([
            'name' => 'Bunda Operational Context',
            'phone' => '628123450023',
            'whatsapp_number' => '628123450023',
            'status' => CustomerStatus::LEAD,
        ]);
        $session = WhatsAppSession::create(['session_name' => 'default', 'status' => 'working']);
        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_session_id' => $session->id,
            'wa_chat_id' => '628123450023@c.us',
            'channel' => 'whatsapp',
            'status' => ConversationStatus::OPEN,
            'ai_enabled' => true,
        ]);
        Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
        app(AiDataExtractionService::class)->extractFromMessage(Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'incoming',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'content' => 'saya mau booking baby spa',
            'sent_at' => now(),
        ]));
        $question = Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'incoming',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'content' => 'jam operasional jam berapa ?',
            'sent_at' => now(),
        ]);

        $extracted = app(AiDataExtractionService::class)->extractFromMessage($question);
        $result = app(AiService::class)->generateReply($conversation, $question);

        $this->assertSame('general_inquiry', $extracted->intent);
        $this->assertSame([], $extracted->extracted_booking_data);
        $this->assertStringContainsString('09.00 sampai 18.00', $result['reply']);
        $this->assertStringNotContainsString('data booking sebelumnya', strtolower($result['reply']));
        $this->assertStringNotContainsString('Tinggal lengkapi', $result['reply']);
    }

    public function test_english_cancel_after_incomplete_booking_context_cancels_instead_of_fallback(): void
    {
        config()->set('ai.provider', 'local');
        $this->seedAiSetup();

        $customer = Customer::create([
            'name' => 'Bunda Cancel English',
            'phone' => '628123450024',
            'whatsapp_number' => '628123450024',
            'status' => CustomerStatus::LEAD,
        ]);
        $session = WhatsAppSession::create(['session_name' => 'default', 'status' => 'working']);
        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_session_id' => $session->id,
            'wa_chat_id' => '628123450024@c.us',
            'channel' => 'whatsapp',
            'status' => ConversationStatus::OPEN,
            'ai_enabled' => true,
        ]);
        Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);

        app(AiDataExtractionService::class)->extractFromMessage(Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'incoming',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'content' => 'saya mau booking baby spa',
            'sent_at' => now(),
        ]));
        Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'outgoing',
            'sender_type' => 'ai',
            'message_type' => 'text',
            'content' => 'Baik Bunda, data booking sebelumnya sudah saya catat. Tinggal lengkapi: nama Bunda, alamat lengkap, hari atau tanggal kunjungan, perkiraan jam.',
            'sent_at' => now(),
        ]);

        $cancel = Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'incoming',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'content' => 'cancel',
            'sent_at' => now(),
        ]);

        app(AiDataExtractionService::class)->extractFromMessage($cancel);
        $result = app(AiService::class)->generateReply($conversation, $cancel);

        $this->assertSame('success', $result['status']);
        $this->assertStringContainsString('proses reservasi tidak saya lanjutkan', strtolower($result['reply']));
        $this->assertStringNotContainsString('belum bisa memastikan', strtolower($result['reply']));
    }

    public function test_booking_lookup_includes_pending_confirmation_reservation(): void
    {
        config()->set('ai.provider', 'local');
        $this->seedAiSetup();

        $customer = Customer::create([
            'name' => 'Bunda Lookup',
            'phone' => '628123450030',
            'whatsapp_number' => '628123450030',
            'address' => 'Kediri',
            'status' => CustomerStatus::LEAD,
        ]);
        $session = WhatsAppSession::create(['session_name' => 'default', 'status' => 'working']);
        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_session_id' => $session->id,
            'wa_chat_id' => '628123450030@c.us',
            'channel' => 'whatsapp',
            'status' => ConversationStatus::OPEN,
            'ai_enabled' => true,
        ]);
        $service = Service::create([
            'name' => 'Baby Spa Premium',
            'category' => 'baby-spa',
            'duration_minutes' => 60,
            'price' => 250000,
            'is_active' => true,
        ]);
        $bookingCustomer = Customer::create([
            'name' => 'Budiman Manual',
            'phone' => '629984970786',
            'whatsapp_number' => '629984970786',
            'address' => 'Kediri',
            'status' => CustomerStatus::LEAD,
        ]);
        Booking::create([
            'customer_id' => $bookingCustomer->id,
            'conversation_id' => $conversation->id,
            'service_id' => $service->id,
            'booking_code' => 'BK-LOOKUP-PENDING',
            'booking_date' => now()->addDay()->toDateString(),
            'start_time' => '14:00:00',
            'end_time' => '15:00:00',
            'status' => BookingStatus::PENDING_CONFIRMATION,
            'payment_status' => 'unpaid',
            'source' => 'ai_approval',
        ]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'incoming',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'content' => 'cek data reservasi saya',
            'sent_at' => now(),
        ]);

        app(AiDataExtractionService::class)->extractFromMessage($message);
        $result = app(AiService::class)->generateReply($conversation, $message);

        $this->assertStringContainsString('Reservasi aktif yang tercatat', $result['reply']);
        $this->assertStringContainsString('BK-LOOKUP-PENDING', $result['reply']);
        $this->assertStringContainsString('pending_confirmation', $result['reply']);
        $this->assertStringNotContainsString('belum menemukan reservasi aktif', strtolower($result['reply']));
    }

    private function seedAiSetup(): void
    {
        AiPersona::create([
            'name' => 'Gayatri Assistant',
            'slug' => 'gayatri-assistant',
            'prompt' => 'Jawab hanya berdasarkan knowledge base Gayatri dan eskalasi pertanyaan medis.',
            'tone' => 'warm-professional',
            'is_active' => true,
        ]);

        $knowledge = KnowledgeBase::create([
            'title' => 'FAQ Baby Spa',
            'slug' => 'faq-baby-spa',
            'type' => 'faq',
            'content' => 'Baby spa tersedia pukul 09.00 sampai 18.00. Booking bisa dibantu admin Gayatri.',
            'status' => 'active',
        ]);

        $knowledge->chunks()->create([
            'chunk_index' => 0,
            'content' => 'Baby spa tersedia pukul 09.00 sampai 18.00.',
            'token_count' => 8,
        ]);
    }
}
