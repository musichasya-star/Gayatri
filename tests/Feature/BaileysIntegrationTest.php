<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiReplyJob;
use App\Jobs\ProcessIncomingWhatsAppMessageJob;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\AiPersona;
use App\Models\AppSetting;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Services\AppSettingService;
use App\Services\WhatsApp\BaileysService;
use App\Services\WhatsApp\WhatsAppGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BaileysIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'whatsapp.provider' => 'baileys',
            'baileys.base_url' => 'http://baileys.test',
            'baileys.api_key' => 'baileys-api-test',
            'baileys.webhook_secret' => 'baileys-webhook-test',
            'baileys.default_session' => 'gayatri-main',
            'baileys.webhook_base_url' => 'http://127.0.0.1:8000',
            'baileys.typing_delay_ms' => 0,
            'ai.provider' => 'local',
            'ai.model' => 'local-deterministic',
        ]);
        Http::preventStrayRequests();
    }

    public function test_gateway_binding_and_send_job_use_baileys_with_legacy_chat_id(): void
    {
        $this->assertInstanceOf(BaileysService::class, app(WhatsAppGateway::class));
        Http::fake(['http://baileys.test/api/*' => Http::response(['id' => 'baileys-sent-1'], 200)]);
        $customer = Customer::create(['name' => 'Bunda Local', 'whatsapp_number' => '628123450001', 'status' => 'active']);
        $conversation = Conversation::create(['customer_id' => $customer->id, 'wa_chat_id' => '628123450001@c.us', 'status' => 'open', 'channel' => 'whatsapp', 'ai_enabled' => true]);

        app()->call([new SendWhatsAppMessageJob($conversation->id, null, 'admin', 'Halo dari Baileys'), 'handle']);

        Http::assertSent(fn ($request) => $request->url() === 'http://baileys.test/api/sendText'
            && $request->hasHeader('X-Api-Key', 'baileys-api-test') && $request['session'] === 'gayatri-main'
            && $request['chatId'] === '628123450001@c.us');
        $this->assertDatabaseHas('messages', ['wa_message_id' => 'baileys-sent-1', 'direction' => 'outgoing', 'failed_reason' => null]);
    }

    public function test_dashboard_renders_baileys_qr_and_local_callback_urls(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Http::fake([
            'http://baileys.test/api/sessions' => Http::response([['name' => 'gayatri-main', 'status' => 'SCAN_QR_CODE']], 200),
            'http://baileys.test/api/gayatri-main/auth/qr*' => Http::response('qr-binary', 200, ['Content-Type' => 'image/png']),
            'http://baileys.test/api/sessions/gayatri-main*' => Http::response(['name' => 'gayatri-main', 'status' => 'SCAN_QR_CODE'], 200),
        ]);
        $this->actingAs($admin)->get(route('admin.whatsapp.session'))->assertOk()->assertSee('Session Baileys')
            ->assertSee('data:image/png;base64,'.base64_encode('qr-binary'), false);
        $this->actingAs($admin)->post(route('admin.whatsapp.session.start'))->assertRedirect();
        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && data_get($request->data(), 'config.webhooks.0.url') === 'http://127.0.0.1:8000/webhooks/whatsapp/messages'
            && data_get($request->data(), 'config.webhooks.1.url') === 'http://127.0.0.1:8000/webhooks/whatsapp/status');
    }

    public function test_subdirectory_webhook_url_is_preserved_without_docker_hostname(): void
    {
        config(['baileys.webhook_base_url' => 'http://localhost/Gayatri/public']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Http::fake(['http://baileys.test/api/*' => Http::response([['name' => 'gayatri-main']], 200)]);
        $this->actingAs($admin)->post(route('admin.whatsapp.session.start'))->assertRedirect();
        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && data_get($request->data(), 'config.webhooks.0.url') === 'http://localhost/Gayatri/public/webhooks/whatsapp/messages');
    }

    public function test_gateway_binding_refreshes_saved_settings_in_a_long_running_worker(): void
    {
        $settings = app(AppSettingService::class);
        config(['whatsapp.provider' => 'waha']);
        $settings->setMany(['whatsapp.provider' => 'baileys', 'baileys.base_url' => 'http://baileys.test', 'baileys.api_key' => 'updated-worker-key']);
        Http::fake(['http://baileys.test/api/sessions' => Http::response([], 200)]);
        $gateway = app(WhatsAppGateway::class);
        $this->assertInstanceOf(BaileysService::class, $gateway);
        $gateway->listSessions();
        Http::assertSent(fn ($request) => $request->hasHeader('X-Api-Key', 'updated-worker-key'));
    }

    public function test_gateway_page_waits_for_first_qr_without_displaying_an_http_error(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Http::fake([
            'http://baileys.test/api/sessions' => Http::response([['name' => 'gayatri-main', 'status' => 'STARTING']], 200),
            'http://baileys.test/api/gayatri-main/auth/qr*' => Http::response(['message' => 'QR belum tersedia'], 409),
        ]);
        $this->actingAs($admin)->get(route('admin.whatsapp.session'))->assertOk()
            ->assertSee('QR Belum Tersedia')->assertDontSee('HTTP request returned status code');
    }

    public function test_baileys_settings_require_both_credentials_before_activation(): void
    {
        config(['baileys.api_key' => null, 'baileys.webhook_secret' => null]);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)->post(route('admin.settings.whatsapp.update'), [
            'gateway_provider' => 'baileys', 'base_url' => 'http://baileys.test', 'default_session' => 'main', 'timeout' => 15,
        ])->assertRedirect()->assertSessionHasErrors('whatsapp');
        $this->assertDatabaseCount('app_settings', 0);
    }

    public function test_settings_store_baileys_secrets_encrypted_and_preserve_waha(): void
    {
        $settings = app(AppSettingService::class);
        $settings->set('waha.api_key', 'keep-old-key');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)->post(route('admin.settings.whatsapp.update'), [
            'gateway_provider' => 'baileys', 'base_url' => 'http://baileys.test', 'api_key' => 'new-baileys-key',
            'default_session' => 'gayatri-main', 'webhook_secret' => 'new-webhook-key',
            'webhook_base_url' => 'http://127.0.0.1:8000', 'timeout' => 15,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('baileys', $settings->get('whatsapp.provider'));
        $this->assertSame('keep-old-key', $settings->get('waha.api_key'));
        $this->assertNotSame('new-baileys-key', AppSetting::where('key', 'baileys.api_key')->value('value'));
        $this->assertSame('new-baileys-key', $settings->get('baileys.api_key'));
        $this->actingAs($admin)->get(route('admin.settings.index'))->assertOk()->assertDontSee('new-baileys-key')->assertDontSee('new-webhook-key');
        Http::fake(['http://baileys.test/api/sessions' => Http::response([], 200)]);
        $this->actingAs($admin)->post(route('admin.settings.whatsapp.test'))->assertRedirect()->assertSessionHas('status');
        Http::assertSent(fn ($request) => $request->hasHeader('X-Api-Key', 'new-baileys-key'));
    }

    public function test_baileys_requires_webhook_secret_and_rejects_inactive_waha_callbacks(): void
    {
        $event = $this->incoming('secure-1');
        $this->postJson(route('webhooks.whatsapp.messages'), $event)->assertUnauthorized();
        $this->withHeaders(['X-Api-Key' => 'baileys-api-test'])->postJson(route('webhooks.whatsapp.messages'), $event)->assertUnauthorized();
        $this->withHeaders(['X-Webhook-Secret' => 'baileys-webhook-test'])->postJson(route('webhooks.waha.messages'), $event)->assertUnauthorized();
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_incoming_message_runs_local_ai_and_sends_one_reply_despite_duplicate_delivery(): void
    {
        AiPersona::create(['name' => 'Gayatri', 'slug' => 'local-gayatri', 'prompt' => 'Jawab pertanyaan layanan Gayatri dengan ramah.', 'tone' => 'friendly', 'is_active' => true]);
        Http::fake(['http://baileys.test/api/*' => Http::response(['id' => 'baileys-ai-reply'], 200)]);
        $event = $this->incoming('incoming-dedup-1');
        for ($i = 0; $i < 2; $i++) {
            $this->withHeaders(['X-Webhook-Secret' => 'baileys-webhook-test'])->postJson(route('webhooks.whatsapp.messages'), $event)->assertOk();
        }
        $this->assertDatabaseHas('messages', ['wa_message_id' => 'incoming-dedup-1', 'direction' => 'incoming']);
        $this->assertDatabaseHas('messages', ['wa_message_id' => 'baileys-ai-reply', 'sender_type' => 'ai', 'direction' => 'outgoing', 'failed_reason' => null]);
        $this->assertSame(1, Message::where('direction', 'incoming')->count());
        $this->assertSame(1, Message::where('sender_type', 'ai')->count());
        Http::assertSentCount(3); // composing, sendText, paused; duplicate must not send again.
    }

    public function test_lid_message_is_saved_and_own_or_group_messages_never_trigger_ai(): void
    {
        Queue::fake();
        $event = $this->incoming('lid-1');
        $event['payload']['from'] = '123456789@lid';
        $this->withHeaders(['X-Webhook-Secret' => 'baileys-webhook-test'])->postJson(route('webhooks.whatsapp.messages'), $event)->assertOk();
        Queue::assertPushed(ProcessIncomingWhatsAppMessageJob::class, 1);
        Queue::fake();
        $event['payload']['fromMe'] = true;
        $this->postJson(route('webhooks.whatsapp.messages'), $event)->assertOk();
        $event['payload']['fromMe'] = false;
        $event['payload']['from'] = '123456@g.us';
        $this->postJson(route('webhooks.whatsapp.messages'), $event)->assertOk();
        Queue::assertNotPushed(GenerateAiReplyJob::class);
        Queue::assertNotPushed(ProcessIncomingWhatsAppMessageJob::class);
    }

    public function test_delivery_receipts_never_mark_server_ack_as_delivered_or_clear_read_at(): void
    {
        $customer = Customer::create(['name' => 'Bunda Ack', 'whatsapp_number' => '628123450099', 'status' => 'active']);
        $conversation = Conversation::create(['customer_id' => $customer->id, 'wa_chat_id' => '628123450099@c.us', 'status' => 'open', 'channel' => 'whatsapp']);
        $message = Message::create(['customer_id' => $customer->id, 'conversation_id' => $conversation->id,
            'wa_message_id' => 'ack-1', 'direction' => 'outgoing', 'sender_type' => 'admin', 'message_type' => 'text', 'content' => 'Test']);
        foreach ([1, 3, 2] as $ack) {
            $this->withHeaders(['X-Webhook-Secret' => 'baileys-webhook-test'])->postJson(route('webhooks.whatsapp.messages'), [
                'event' => 'message.ack', 'session' => 'gayatri-main', 'payload' => ['id' => 'ack-1', 'ack' => $ack],
            ])->assertOk();
            if ($ack === 1) {
                $this->assertNull($message->fresh()->delivered_at);
            }
        }
        $this->assertNotNull($message->fresh()->read_at);
        $this->assertNotNull($message->fresh()->delivered_at);
    }

    public function test_session_status_updates_the_local_tracking(): void
    {
        $this->withHeaders(['X-Webhook-Secret' => 'baileys-webhook-test'])->postJson(route('webhooks.whatsapp.status'), [
            'event' => 'session.status', 'session' => 'gayatri-main', 'me' => ['id' => '62888888888@s.whatsapp.net'], 'payload' => ['status' => 'WORKING'],
        ])->assertOk();
        $this->assertDatabaseHas('whatsapp_sessions', ['session_name' => 'gayatri-main', 'status' => 'working', 'phone_number' => '62888888888']);
        $this->assertNotNull(WhatsAppSession::first()->connected_at);
    }

    private function incoming(string $id): array
    {
        return ['event' => 'message', 'session' => 'gayatri-main', 'engine' => 'BAILEYS', 'payload' => [
            'id' => $id, 'from' => '628123450001@c.us', 'fromMe' => false, 'body' => 'Halo', 'hasMedia' => false, 'timestamp' => time(),
        ]];
    }
}
