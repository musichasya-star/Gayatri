<?php

namespace Tests\Feature;

use App\Models\AiLog;
use App\Models\AiPersona;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\KnowledgeBase;
use App\Models\Message;
use App\Models\User;
use App\Services\AI\AiChatCustomization;
use App\Services\AI\AiService;
use App\Services\AppSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ExercisesLegacyAi;
use Tests\TestCase;

#[Group('legacy-ai')]
class AiAutoReplySettingsTest extends TestCase
{
    use ExercisesLegacyAi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'whatsapp.provider' => 'waha', 'waha.base_url' => 'http://waha.test',
            'waha.default_session' => 'default', 'waha.webhook_secret' => 'chat-secret',
            'waha.typing_delay_ms' => 0, 'ai.provider' => 'local', 'ai.model' => 'local-deterministic',
        ]);
        Http::preventStrayRequests();
        Http::fake(['http://waha.test/api/*' => Http::response(['id' => 'custom-outgoing-1'], 200)]);
    }

    public function test_admin_can_open_and_save_templates_rules_and_audit_record(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)->get(route('admin.ai.auto-replies.index'))->assertOk()
            ->assertSee('Balasan Otomatis')->assertSee('Coba Balasan Sebelum Disimpan');
        $data = $this->data(['greeting' => 'Halo {sapaan}, saya {nama_asisten}.', 'rules' => [$this->rule()]]);
        $this->actingAs($admin)->post(route('admin.ai.auto-replies.update'), $data)->assertRedirect(route('admin.ai.auto-replies.index'))->assertSessionHasNoErrors();
        $stored = app(AiChatCustomization::class)->settings();
        $this->assertSame($data['greeting'], $stored['greeting']);
        $this->assertSame(['cara bayar', 'metode pembayaran'], $stored['rules'][0]['keywords']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ai.auto_reply.update', 'user_id' => $admin->id]);
        $this->actingAs($admin)->get(route('admin.ai.auto-replies.index'))->assertOk()->assertSee('Metode pembayaran');
    }

    public function test_sales_and_therapist_cannot_read_change_or_preview_settings(): void
    {
        foreach (['sales', 'therapist'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active']);
            $this->actingAs($user)->get(route('admin.ai.auto-replies.index'))->assertForbidden();
            $this->post(route('admin.ai.auto-replies.update'), $this->data())->assertForbidden();
            $this->post(route('admin.ai.auto-replies.preview'), $this->data())->assertForbidden();
        }
        $this->assertDatabaseCount('app_settings', 0);
    }

    public function test_preview_uses_unsaved_values_without_log_queue_or_provider_requests(): void
    {
        $this->persona();
        config(['ai.provider' => 'openrouter', 'ai.api_key' => 'fake-test-key', 'ai.model' => 'test-model']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)->post(route('admin.ai.auto-replies.preview'), $this->data([
            'greeting' => 'Hai {sapaan} {nama_customer}. Saya {nama_asisten}.',
            'salutation' => 'Kak', 'assistant_name' => 'Nara', 'preview_message' => 'Halo', 'preview_customer' => 'Alya',
        ]))->assertOk()->assertSee('Hai Kak Alya. Saya Nara.')->assertSee('Belum disimpan atau dikirim');
        $this->assertDatabaseCount('app_settings', 0);
        $this->assertDatabaseCount('ai_logs', 0);
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    public function test_custom_greeting_is_actually_sent_with_customer_name_and_one_signature(): void
    {
        $this->persona();
        Customer::create(['name' => 'Alya', 'whatsapp_number' => '62811111111', 'status' => 'active']);
        $this->store(['greeting' => 'Halo {sapaan} {nama_customer}, saya {nama_asisten}! 😊',
            'assistant_name' => 'Nara', 'salutation' => 'Kak', 'use_emoji' => false, 'signature' => 'Salam, {nama_bisnis}']);
        $this->incoming('Halo');
        $reply = Message::where('direction', 'outgoing')->firstOrFail();
        $this->assertSame("Halo Kak Alya, saya Nara!\n\nSalam, Gayatri", $reply->content);
        $this->assertSame($reply->content, AiLog::latest('id')->firstOrFail()->response);
        Http::assertSent(fn ($request) => $request->url() === 'http://waha.test/api/sendText' && $request['text'] === $reply->content);
    }

    public function test_global_disable_retains_inbox_message_without_auto_reply(): void
    {
        $this->persona();
        $this->store(['enabled' => false]);
        $this->incoming('Halo');
        $this->assertDatabaseCount('messages', 1);
        $this->assertSame(0, Message::where('direction', 'outgoing')->count());
        $this->assertDatabaseCount('ai_logs', 0);
        Http::assertNothingSent();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)->get(route('admin.ai.auto-replies.index'))->assertOk()->assertSee('AI Nonaktif');
    }

    public function test_explicit_enable_can_override_the_old_manual_environment_mode(): void
    {
        $this->persona();
        config(['crm.default_ai_mode' => 'manual']);
        $this->store(['enabled' => true, 'greeting' => 'Halo {sapaan}, siap membantu.']);
        $this->incoming('Halo');
        $this->assertDatabaseHas('messages', ['sender_type' => 'ai', 'content' => 'Halo Bunda, siap membantu.']);
    }

    public function test_keyword_reply_has_priority_and_is_used_for_questions_without_knowledge(): void
    {
        $this->persona();
        $this->store(['rules' => [
            ['name' => 'Umum', 'keywords' => ['bayar'], 'match_type' => 'contains', 'priority' => 20, 'is_active' => true, 'reply' => 'Balasan umum'],
            ['name' => 'Pembayaran', 'keywords' => ['cara bayar'], 'match_type' => 'exact', 'priority' => 1, 'is_active' => true, 'reply' => '{sapaan}, admin akan menjelaskan metode pembayaran.'],
        ]]);
        $this->incoming('CARA BAYAR');
        $this->assertDatabaseHas('messages', ['sender_type' => 'ai', 'content' => 'Bunda, admin akan menjelaskan metode pembayaran.']);
        $log = AiLog::latest('id')->firstOrFail();
        $this->assertSame('custom_rule', $log->meta['reply_source']);
        $this->assertSame('Pembayaran', $log->meta['reply_rule']);
    }

    public function test_keyword_rule_cannot_override_medical_or_complaint_handling(): void
    {
        $this->persona();
        $this->store(['rules' => [
            ['name' => 'Tidak boleh mengganti medis', 'keywords' => ['demam', 'komplain'], 'match_type' => 'contains', 'priority' => 0, 'is_active' => true, 'reply' => 'Balasan khusus yang tidak boleh dipakai'],
        ]]);
        $service = app(AiService::class);
        $medical = $service->simulate('Bayi demam');
        $this->assertSame('medical', $medical['fallback_reason']);
        $this->assertStringContainsString('dokter', $medical['reply']);
        $this->assertStringNotContainsString('Balasan khusus', $medical['reply']);
        $complaint = $service->simulate('Saya komplain');
        $this->assertSame('sensitive', $complaint['fallback_reason']);
    }

    public function test_templates_for_thanks_and_missing_information_are_respected(): void
    {
        $this->persona();
        $this->store(['thanks' => 'Terima kasih kembali {sapaan}.', 'fallback' => 'Informasi belum tersedia, {sapaan}. Silakan hubungi admin.']);
        $service = app(AiService::class);
        $this->assertSame('Terima kasih kembali Bunda.', $service->simulate('Terima kasih')['reply']);
        $result = $service->simulate('Apakah planet saturnus memiliki cincin?');
        $this->assertSame('no_knowledge', $result['fallback_reason']);
        $this->assertSame('Informasi belum tersedia, Bunda. Silakan hubungi admin.', $result['reply']);
    }

    public function test_guided_booking_flow_uses_salutation_and_signature_without_duplicate_footer(): void
    {
        $this->persona();
        $this->store(['salutation' => 'Kak', 'signature' => 'Salam dari {nama_asisten}']);
        $this->incoming('Mau booking');
        $reply = Message::where('direction', 'outgoing')->firstOrFail()->content;
        $this->assertStringContainsString('Kak', $reply);
        $this->assertStringNotContainsString('Bunda', $reply);
        $this->assertSame(1, substr_count($reply, 'Salam dari Admin Gayatri'));
    }

    public function test_human_takeover_is_not_reenabled_by_custom_replies(): void
    {
        $this->persona();
        $customer = Customer::create(['name' => 'Alya', 'whatsapp_number' => '62811111111', 'status' => 'active']);
        Conversation::create(['customer_id' => $customer->id, 'wa_chat_id' => '62811111111@c.us', 'status' => 'human_handled', 'channel' => 'whatsapp', 'ai_enabled' => true]);
        $this->store(['greeting' => 'Balasan otomatis']);
        $this->incoming('Halo');
        $this->assertSame(0, Message::where('direction', 'outgoing')->count());
        $this->assertDatabaseHas('conversations', ['status' => 'human_handled']);
        Http::assertNothingSent();
    }

    public function test_settings_refresh_on_the_same_service_instance_and_inactive_rules_are_skipped(): void
    {
        $this->persona();
        $this->store(['greeting' => 'Sapaan pertama']);
        $service = app(AiService::class);
        $this->assertSame('Sapaan pertama', $service->simulate('Halo')['reply']);
        $this->store(['greeting' => 'Sapaan kedua', 'rules' => [
            ['name' => 'Nonaktif', 'keywords' => ['halo'], 'match_type' => 'exact', 'priority' => 0, 'is_active' => false, 'reply' => 'Jangan pakai ini'],
        ]]);
        $this->assertSame('Sapaan kedua', $service->simulate('Halo')['reply']);
    }

    public function test_style_instructions_are_added_to_provider_prompt_and_output_is_formatted(): void
    {
        $this->persona();
        KnowledgeBase::create(['title' => 'Info layanan', 'slug' => 'info-layanan', 'type' => 'faq', 'content' => 'Baby spa adalah layanan relaksasi dan perawatan.', 'status' => 'active']);
        config(['ai.provider' => 'openrouter', 'ai.api_key' => 'fake-test-key', 'ai.model' => 'test-model']);
        Http::fake(['https://openrouter.ai/api/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'Bunda, berikut penjelasan singkat. 😊']]]], 200)]);
        $this->store(['assistant_name' => 'Nara', 'salutation' => 'Kak', 'instructions' => 'Gunakan bahasa sopan.', 'use_emoji' => false]);
        $result = app(AiService::class)->simulate('Jelaskan apa itu baby spa');
        $this->assertSame('Kak, berikut penjelasan singkat.', $result['reply']);
        Http::assertSent(fn ($request) => str_contains($request['messages'][0]['content'], 'Nama asisten: Nara')
            && str_contains($request['messages'][0]['content'], 'Gunakan bahasa sopan.')
            && str_contains($request['messages'][0]['content'], 'Jangan gunakan emoji.'));
    }

    public function test_form_rejects_invalid_rules_and_can_remove_all_saved_rules(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $rule = $this->rule();
        $rule['keywords'] = ', ,';
        $this->actingAs($admin)->post(route('admin.ai.auto-replies.update'), $this->data(['rules' => [$rule]]))->assertSessionHasErrors('rules');
        $this->post(route('admin.ai.auto-replies.update'), $this->data(['rules' => array_fill(0, 21, $this->rule())]))->assertSessionHasErrors('rules');
        $this->post(route('admin.ai.auto-replies.update'), $this->data(['rules' => [$this->rule()]]))->assertSessionHasNoErrors();
        $this->post(route('admin.ai.auto-replies.update'), $this->data())->assertSessionHasNoErrors();
        $this->assertSame([], app(AiChatCustomization::class)->settings()['rules']);
    }

    private function data(array $overrides = []): array
    {
        return array_replace(app(AiChatCustomization::class)->defaults(), $overrides);
    }

    private function store(array $overrides): void
    {
        app(AppSettingService::class)->set(AiChatCustomization::SETTING_KEY, json_encode($this->data($overrides), JSON_UNESCAPED_UNICODE));
    }

    private function persona(): void
    {
        AiPersona::create(['name' => 'Admin Gayatri', 'slug' => 'admin-gayatri', 'prompt' => 'Jawab pertanyaan layanan Gayatri dengan ramah.', 'tone' => 'warm', 'is_active' => true]);
    }

    private function rule(): array
    {
        return ['name' => 'Metode pembayaran', 'keywords' => 'Cara Bayar, metode pembayaran', 'reply' => '{sapaan}, silakan hubungi admin untuk pilihan pembayaran.', 'match_type' => 'exact', 'priority' => 10, 'is_active' => true];
    }

    private function incoming(string $message): void
    {
        $this->withHeaders(['X-Webhook-Secret' => 'chat-secret'])->postJson(route('webhooks.waha.messages'), [
            'event' => 'message', 'session' => 'default', 'payload' => ['id' => 'custom-incoming-1', 'from' => '62811111111@c.us', 'fromMe' => false, 'body' => $message, 'hasMedia' => false, 'timestamp' => time()],
        ])->assertOk();
    }
}
