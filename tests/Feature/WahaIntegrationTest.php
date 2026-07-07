<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Services\WhatsApp\WahaService;
use App\Support\CustomerStatus;
use App\Support\UserRole;
use App\Support\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WahaIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('waha.base_url', 'http://waha.test');
        config()->set('waha.default_session', 'default');
        config()->set('waha.webhook_secret', 'secret-123');
        config()->set('waha.api_key', 'api-key-123');
        config()->set('waha.webhook_base_url', null);
        config()->set('waha.typing_delay_ms', 0);
    }

    public function test_admin_can_open_gateway_page_and_start_session(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
            'status' => UserStatus::ACTIVE,
        ]);

        Http::fake([
            'http://waha.test/api/sessions/default' => Http::response([], 404),
            'http://waha.test/api/sessions' => Http::response([
                'name' => 'default',
                'status' => 'SCAN_QR_CODE',
                'me' => null,
            ], 200),
            'http://waha.test/api/sessions/default' => Http::response([
                'name' => 'default',
                'status' => 'SCAN_QR_CODE',
                'me' => null,
            ], 200),
            'http://waha.test/api/sessions/default' => Http::response([
                'name' => 'default',
                'status' => 'SCAN_QR_CODE',
                'me' => null,
            ], 200),
            'http://waha.test/api/default/auth/qr*' => Http::response([
                'mimetype' => 'image/png',
                'data' => 'ZmFrZS1xci1kYXRh',
            ], 200),
            'http://waha.test/api/sessions/default/start' => Http::response([
                'name' => 'default',
                'status' => 'STARTING',
            ], 200),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.whatsapp.session'))
            ->assertOk()
            ->assertSee('Session WAHA')
            ->assertSee('default');

        $this->actingAs($admin)
            ->post(route('admin.whatsapp.session.start'), [
                'session_name' => 'default',
            ])
            ->assertRedirect(route('admin.whatsapp.session'));

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && $request->url() === 'http://waha.test/api/sessions/default'
            && data_get($request->data(), 'config.webhooks.0.url') === route('webhooks.waha.messages'));
    }

    public function test_gateway_page_uses_configured_webhook_base_url_if_set(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
            'status' => UserStatus::ACTIVE,
        ]);

        config()->set('waha.webhook_base_url', 'https://7digital-solution.web.id');

        Http::fake([
            'http://waha.test/api/sessions' => Http::response([
                [
                    'name' => 'default',
                    'status' => 'SCAN_QR_CODE',
                    'me' => null,
                ],
            ], 200),
            'http://waha.test/api/sessions/default' => Http::response([
                'name' => 'default',
                'status' => 'SCAN_QR_CODE',
                'me' => null,
            ], 200),
            'http://waha.test/api/default/auth/qr*' => Http::response('fake-png-binary', 200, ['Content-Type' => 'image/png']),
            'http://waha.test/api/sessions/default/start' => Http::response([
                'name' => 'default',
                'status' => 'STARTING',
            ], 200),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.whatsapp.session.start'), [
                'session_name' => 'default',
            ])
            ->assertRedirect(route('admin.whatsapp.session'));

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && $request->url() === 'http://waha.test/api/sessions/default'
            && data_get($request->data(), 'config.webhooks.0.url') === 'https://7digital-solution.web.id/webhooks/waha/messages');
    }

    public function test_gateway_page_renders_binary_qr_from_latest_waha(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
            'status' => UserStatus::ACTIVE,
        ]);

        Http::fake([
            'http://waha.test/api/sessions' => Http::response([
                [
                    'name' => 'default',
                    'status' => 'SCAN_QR_CODE',
                    'me' => null,
                ],
            ], 200),
            'http://waha.test/api/sessions/default' => Http::response([
                'name' => 'default',
                'status' => 'SCAN_QR_CODE',
                'me' => null,
            ], 200),
            'http://waha.test/api/default/auth/qr*' => Http::response('fake-png-binary', 200, ['Content-Type' => 'image/png']),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.whatsapp.session'))
            ->assertOk()
            ->assertSee('data:image/png;base64,'.base64_encode('fake-png-binary'), false);
    }

    public function test_gateway_page_shows_error_instead_of_crashing_when_waha_times_out(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
            'status' => UserStatus::ACTIVE,
        ]);

        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        $this->actingAs($admin)
            ->get(route('admin.whatsapp.session'))
            ->assertOk()
            ->assertSee('Session WAHA')
            ->assertSee('Connection timed out');
    }

    public function test_message_webhook_creates_customer_conversation_and_message(): void
    {
        $response = $this->withHeaders([
            'X-Webhook-Secret' => 'secret-123',
        ])->postJson(route('webhooks.waha.messages'), [
            'event' => 'message',
            'session' => 'default',
            'payload' => [
                'id' => 'wamid-001',
                'timestamp' => 1710000000,
                'from' => '628123450000@c.us',
                'fromMe' => false,
                'body' => 'Halo, saya mau tanya jadwal.',
                'hasMedia' => false,
            ],
        ]);

        $response->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('customers', [
            'whatsapp_number' => '628123450000',
            'status' => CustomerStatus::LEAD,
        ]);

        $customer = Customer::where('whatsapp_number', '628123450000')->firstOrFail();

        $this->assertDatabaseHas('conversations', [
            'customer_id' => $customer->id,
            'wa_chat_id' => '628123450000@c.us',
        ]);

        $conversation = Conversation::where('wa_chat_id', '628123450000@c.us')->firstOrFail();

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'wa_message_id' => 'wamid-001',
            'direction' => 'incoming',
            'content' => 'Halo, saya mau tanya jadwal.',
        ]);
    }

    public function test_existing_lid_conversation_keeps_linked_manual_whatsapp_customer(): void
    {
        $manualCustomer = Customer::create([
            'name' => 'Budiman',
            'phone' => '629984970786',
            'whatsapp_number' => '629984970786',
            'status' => CustomerStatus::LEAD,
        ]);
        $session = WhatsAppSession::create(['session_name' => 'default', 'status' => 'working']);
        $conversation = Conversation::create([
            'customer_id' => $manualCustomer->id,
            'whatsapp_session_id' => $session->id,
            'wa_chat_id' => '151152817635490@lid',
            'channel' => 'whatsapp',
            'status' => 'open',
            'ai_enabled' => true,
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'secret-123'])
            ->postJson(route('webhooks.waha.messages'), [
                'event' => 'message',
                'session' => 'default',
                'payload' => [
                    'id' => 'wamid-lid-existing-001',
                    'timestamp' => 1710000000,
                    'from' => '151152817635490@lid',
                    'fromMe' => false,
                    'body' => 'cek data reservasi saya',
                    'hasMedia' => false,
                ],
            ])
            ->assertOk();

        $this->assertSame($manualCustomer->id, $conversation->fresh()->customer_id);
        $this->assertDatabaseMissing('customers', ['whatsapp_number' => '151152817635490']);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'customer_id' => $manualCustomer->id,
            'wa_message_id' => 'wamid-lid-existing-001',
            'content' => 'cek data reservasi saya',
        ]);
    }

    public function test_message_webhook_ignores_status_broadcast_and_newsletter(): void
    {
        $this->withHeaders(['X-Webhook-Secret' => 'secret-123'])
            ->postJson(route('webhooks.waha.messages'), [
                'event' => 'message.any',
                'session' => 'default',
                'payload' => [
                    'id' => 'false_status@broadcast_abc',
                    'timestamp' => 1710000000,
                    'from' => 'status@broadcast',
                    'fromMe' => false,
                    'body' => 'Status text',
                    'hasMedia' => false,
                ],
            ])
            ->assertOk();

        $this->withHeaders(['X-Webhook-Secret' => 'secret-123'])
            ->postJson(route('webhooks.waha.messages'), [
                'event' => 'message.any',
                'session' => 'default',
                'payload' => [
                    'id' => 'false_newsletter_abc',
                    'timestamp' => 1710000000,
                    'from' => '120363425185468215@newsletter',
                    'fromMe' => false,
                    'body' => 'Newsletter text',
                    'hasMedia' => false,
                ],
            ])
            ->assertOk();

        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_button_webhook_is_saved_as_confirmation_text(): void
    {
        $this->withHeaders(['X-Webhook-Secret' => 'secret-123'])
            ->postJson(route('webhooks.waha.messages'), [
                'event' => 'message',
                'session' => 'default',
                'payload' => [
                    'id' => 'wamid-button-001',
                    'timestamp' => 1710000000,
                    'from' => '628123450099@c.us',
                    'fromMe' => false,
                    'body' => '',
                    'selectedButtonId' => 'booking_confirm_yes',
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('messages', [
            'wa_message_id' => 'wamid-button-001',
            'direction' => 'incoming',
            'content' => 'Iya lanjutkan',
        ]);
    }

    public function test_status_webhook_updates_whatsapp_session(): void
    {
        $this->withHeaders([
            'X-Api-Key' => 'api-key-123',
        ])->postJson(route('webhooks.waha.status'), [
            'event' => 'session.status',
            'session' => 'default',
            'me' => [
                'id' => '628111111111@c.us',
            ],
            'payload' => [
                'status' => 'WORKING',
            ],
        ])->assertOk();

        $this->assertDatabaseHas('whatsapp_sessions', [
            'session_name' => 'default',
            'status' => 'working',
            'phone_number' => '628111111111',
        ]);
    }

    public function test_send_whatsapp_message_job_saves_outgoing_message(): void
    {
        Http::fake([
            'http://waha.test/api/startTyping' => Http::response([], 200),
            'http://waha.test/api/sendText' => Http::response([
                'id' => 'wamid-outgoing-001',
            ], 200),
            'http://waha.test/api/stopTyping' => Http::response([], 200),
        ]);

        $customer = Customer::create([
            'name' => 'Bunda Mia',
            'whatsapp_number' => '6281234567000',
            'status' => CustomerStatus::ACTIVE,
        ]);

        $session = WhatsAppSession::create([
            'session_name' => 'default',
            'status' => 'working',
        ]);

        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_session_id' => $session->id,
            'wa_chat_id' => '6281234567000@c.us',
            'status' => 'open',
            'channel' => 'whatsapp',
            'ai_enabled' => true,
        ]);

        $user = User::factory()->create([
            'role' => UserRole::ADMIN,
            'status' => UserStatus::ACTIVE,
        ]);

        $job = new SendWhatsAppMessageJob($conversation->id, $user->id, 'admin', 'Halo Bunda, jadwal tersedia.');
        $job->handle(app(WahaService::class));

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'wa_message_id' => 'wamid-outgoing-001',
            'direction' => 'outgoing',
            'sender_type' => 'admin',
            'content' => 'Halo Bunda, jadwal tersedia.',
        ]);
        Http::assertSentInOrder([
            fn ($request) => $request->url() === 'http://waha.test/api/startTyping'
                && data_get($request->data(), 'chatId') === '6281234567000@c.us',
            fn ($request) => $request->url() === 'http://waha.test/api/sendText'
                && data_get($request->data(), 'text') === 'Halo Bunda, jadwal tersedia.',
            fn ($request) => $request->url() === 'http://waha.test/api/stopTyping'
                && data_get($request->data(), 'chatId') === '6281234567000@c.us',
        ]);
    }

    public function test_send_whatsapp_message_job_still_sends_when_typing_endpoint_fails(): void
    {
        Http::fake([
            'http://waha.test/api/startTyping' => Http::response(['message' => 'not supported'], 404),
            'http://waha.test/api/sendText' => Http::response(['id' => 'wamid-typing-fallback'], 200),
            'http://waha.test/api/stopTyping' => Http::response([], 200),
        ]);

        $customer = Customer::create([
            'name' => 'Bunda Typing',
            'whatsapp_number' => '6281234567001',
            'status' => CustomerStatus::ACTIVE,
        ]);
        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'wa_chat_id' => '6281234567001@c.us',
            'status' => 'open',
            'channel' => 'whatsapp',
            'ai_enabled' => true,
        ]);

        (new SendWhatsAppMessageJob($conversation->id, null, 'ai', 'Typing gagal tetap kirim'))->handle(app(WahaService::class));

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'wa_message_id' => 'wamid-typing-fallback',
            'direction' => 'outgoing',
            'sender_type' => 'ai',
            'content' => 'Typing gagal tetap kirim',
            'failed_reason' => null,
        ]);
    }

    public function test_send_whatsapp_message_job_accepts_empty_success_response(): void
    {
        Http::fake([
            'http://waha.test/api/sendText' => Http::response('', 201),
        ]);

        $customer = Customer::create([
            'name' => 'Bunda Empty',
            'whatsapp_number' => '6281234567111',
            'status' => CustomerStatus::ACTIVE,
        ]);
        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'wa_chat_id' => '6281234567111@c.us',
            'status' => 'open',
            'channel' => 'whatsapp',
            'ai_enabled' => true,
        ]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN, 'status' => UserStatus::ACTIVE]);

        (new SendWhatsAppMessageJob($conversation->id, $admin->id, 'admin', 'Test response kosong'))->handle(app(WahaService::class));

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'outgoing',
            'sender_type' => 'admin',
            'content' => 'Test response kosong',
            'failed_reason' => null,
        ]);
    }

    public function test_send_whatsapp_message_job_extracts_serialized_id_from_waha_object_response(): void
    {
        Http::fake([
            'http://waha.test/api/sendText' => Http::response([
                'id' => [
                    'fromMe' => true,
                    'remote' => '151152817635490@lid',
                    'id' => '3EB0ABC',
                    '_serialized' => 'true_151152817635490@lid_3EB0ABC',
                ],
                'body' => 'Halo Bunda',
            ], 201),
        ]);

        $customer = Customer::create([
            'name' => 'Bunda Lid',
            'whatsapp_number' => '151152817635490',
            'status' => CustomerStatus::ACTIVE,
        ]);
        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'wa_chat_id' => '151152817635490@lid',
            'status' => 'open',
            'channel' => 'whatsapp',
            'ai_enabled' => true,
        ]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN, 'status' => UserStatus::ACTIVE]);

        (new SendWhatsAppMessageJob($conversation->id, $admin->id, 'admin', 'Halo Bunda'))->handle(app(WahaService::class));

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'wa_message_id' => 'true_151152817635490@lid_3EB0ABC',
            'direction' => 'outgoing',
            'sender_type' => 'admin',
            'content' => 'Halo Bunda',
            'failed_reason' => null,
        ]);
    }

    public function test_send_whatsapp_message_job_marks_failed_when_waha_down_and_can_retry(): void
    {
        Queue::fake();
        Http::fake([
            'http://waha.test/api/sendText' => Http::response(['message' => 'WAHA unavailable'], 500),
        ]);

        $customer = Customer::create([
            'name' => 'Bunda Fail',
            'whatsapp_number' => '6281234567999',
            'status' => CustomerStatus::ACTIVE,
        ]);
        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'wa_chat_id' => '6281234567999@c.us',
            'status' => 'open',
            'channel' => 'whatsapp',
            'ai_enabled' => true,
        ]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN, 'status' => UserStatus::ACTIVE]);

        (new SendWhatsAppMessageJob($conversation->id, $admin->id, 'admin', 'Test gagal'))->handle(app(WahaService::class));

        $message = Message::where('content', 'Test gagal')->firstOrFail();
        $this->assertNotNull($message->failed_at);
        $this->assertNotNull($message->failed_reason);

        $this->actingAs($admin)
            ->post(route('admin.inbox.messages.retry', $message))
            ->assertRedirect(route('admin.inbox', ['conversation' => $conversation->id]));

        Queue::assertPushed(SendWhatsAppMessageJob::class);
    }
}
