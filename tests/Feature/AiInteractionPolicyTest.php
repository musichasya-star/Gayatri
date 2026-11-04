<?php

namespace Tests\Feature;

use App\Jobs\CreateAiAutomationApprovalJob;
use App\Jobs\CreateBookingFromAiDataJob;
use App\Jobs\CreateCustomerFromAiDataJob;
use App\Jobs\CreateFollowupFromAiDataJob;
use App\Jobs\CreateReminderFromAiDataJob;
use App\Jobs\ExtractDataFromIncomingMessageJob;
use App\Jobs\GenerateAiReplyJob;
use App\Jobs\ProcessAiAutomationRuleJob;
use App\Jobs\SendBookingReminderJob;
use App\Jobs\SendWhatsAppMessageJob;
use App\Jobs\UpdateCustomerFromAiDataJob;
use App\Models\AiAutomationApproval;
use App\Models\AiAutomationRule;
use App\Models\AiExtractedData;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\ConversationFlow;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Reminder;
use App\Models\Service;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Services\AI\AiAutomationApprovalService;
use App\Services\AI\AiAutomationExecutorService;
use App\Services\AI\AiChatCustomization;
use App\Services\AI\AiConversationPreferences;
use App\Services\AI\AiInteractionPolicy;
use App\Services\AI\AiService;
use App\Services\AI\ConversationFlowService;
use App\Services\AppSettingService;
use App\Services\CRM\RentalSettings;
use App\Services\WhatsApp\OutboundMessages;
use App\Services\WhatsApp\WhatsAppGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AiInteractionPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['waha.base_url' => 'http://waha.test', 'waha.default_session' => 'default', 'waha.typing_enabled' => false]);
        Http::fake(['http://waha.test/*' => Http::response(['id' => 'test-out'], 200)]);
    }

    private function conversation(): array
    {
        $customer = Customer::create(['name' => 'DEMO Policy', 'whatsapp_number' => '6281234000701', 'status' => 'lead']);
        $session = WhatsAppSession::create(['session_name' => 'default', 'status' => 'WORKING']);
        $conversation = Conversation::create(['customer_id' => $customer->id, 'whatsapp_session_id' => $session->id,
            'wa_chat_id' => '6281234000701@c.us', 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'unread_count' => 1]);
        $message = Message::create(['conversation_id' => $conversation->id, 'customer_id' => $customer->id,
            'direction' => 'incoming', 'sender_type' => 'customer', 'message_type' => 'text', 'content' => 'Booking baby spa besok jam 10, ya']);

        return [$customer, $conversation, $message];
    }

    private function extracted(Customer $customer, Conversation $conversation, Message $message): AiExtractedData
    {
        return AiExtractedData::create(['customer_id' => $customer->id, 'conversation_id' => $conversation->id, 'message_id' => $message->id,
            'intent' => 'booking_request', 'confidence_score' => 1, 'status' => 'extracted',
            'extracted_customer_data' => ['name' => 'Changed', 'whatsapp_number' => '628888888888', 'address' => 'Changed'],
            'extracted_booking_data' => ['booking_date' => now()->addDay()->toDateString(), 'start_time' => '10:00:00'],
            'raw_ai_response' => ['booking_confirmation' => 'confirmed']]);
    }

    private function generate(Conversation $conversation, Message $message): void
    {
        (new GenerateAiReplyJob($conversation->id, $message->id))->handle(
            app(AiService::class), app(AppSettingService::class), app(ConversationFlowService::class)
        );
    }

    public function test_default_policy_has_no_legacy_configuration_escape(): void
    {
        $policy = app(AiInteractionPolicy::class);
        $this->assertSame('information_web', $policy->snapshot()['mode']);
        config(['crm.ai_data_automation.enabled' => true, 'crm.ai_data_automation.auto_create_booking' => true]);
        $this->assertFalse($policy->allowsLegacyAutomation());
        DB::table('ai_interaction_policies')->where('id', 1)->update(['mode' => 'legacy_transaction']);
        $this->assertFalse($policy->snapshot()['enabled']);
        $this->assertFalse($policy->snapshot()['available']);
        $this->assertFalse($policy->allowsLegacyAutomation());
    }

    public function test_unavailable_or_invalid_policy_and_customization_fail_closed(): void
    {
        DB::table('ai_interaction_policies')->delete();
        $this->assertFalse(app(AiInteractionPolicy::class)->snapshot()['enabled']);
        DB::table('ai_interaction_policies')->insert(['id' => 1, 'mode' => 'information_web', 'revision' => 1]);
        app(AppSettingService::class)->set('ai.chat.customization', '{invalid');
        $this->assertFalse(app(AiInteractionPolicy::class)->snapshot()['enabled']);
    }

    public function test_safe_reply_does_not_execute_extraction_rules_or_private_lookup(): void
    {
        Queue::fake();
        [$customer, $conversation, $message] = $this->conversation();
        app(AppSettingService::class)->set('ai.chat.customization', json_encode([
            'enabled' => true, 'signature' => 'Booking sudah confirmed dan lunas',
            'rules' => [['name' => 'unsafe', 'keywords' => ['booking'], 'reply' => 'Lunas', 'is_active' => true]],
        ]));
        $this->generate($conversation, $message);
        Queue::assertPushed(SendWhatsAppMessageJob::class, 1);
        Queue::assertNotPushed(ExtractDataFromIncomingMessageJob::class);
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('ai_extracted_data', 0);
        $log = DB::table('ai_logs')->first();
        $this->assertStringContainsString('/booking', $log->response);
        $this->assertStringNotContainsString('confirmed', $log->response);
        $this->assertStringNotContainsString('sudah confirmed', $log->response);
        $this->assertSame('DEMO Policy', $customer->fresh()->name);
        Http::assertNothingSent();
    }

    public function test_ai_off_and_blocked_customer_do_not_generate_or_send(): void
    {
        Queue::fake();
        [$customer, $conversation, $message] = $this->conversation();
        DB::table('ai_interaction_policies')->where('id', 1)->update(['mode' => 'off']);
        $this->generate($conversation, $message);
        DB::table('ai_interaction_policies')->where('id', 1)->update(['mode' => 'information_web']);
        $customer->update(['status' => 'inactive']);
        $this->generate($conversation->fresh(), $message);
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('ai_logs', 0);
    }

    public function test_replayed_generate_job_dispatches_only_once(): void
    {
        Queue::fake();
        [, $conversation, $message] = $this->conversation();
        $this->generate($conversation, $message);
        $this->generate($conversation->fresh(), $message->fresh());
        Queue::assertPushed(SendWhatsAppMessageJob::class, 1);
    }

    public function test_message_cannot_be_used_with_another_conversation(): void
    {
        Queue::fake();
        [$customer, $conversation, $message] = $this->conversation();
        $other = Conversation::create(['customer_id' => $customer->id, 'wa_chat_id' => '6281234000702@c.us', 'status' => 'open', 'ai_enabled' => true]);
        $this->generate($other, $message);
        Queue::assertNothingPushed();
    }

    public static function jobs(): array
    {
        return [
            [ProcessAiAutomationRuleJob::class, 'booking', 'create_booking_confirmed'],
            [CreateBookingFromAiDataJob::class, 'booking', 'create_booking_confirmed'],
            [CreateCustomerFromAiDataJob::class, 'customer', 'create_customer'],
            [UpdateCustomerFromAiDataJob::class, 'customer', 'update_customer'],
            [CreateFollowupFromAiDataJob::class, 'followup', 'create_followup'],
            [CreateReminderFromAiDataJob::class, 'reminder', 'create_reminder'],
            [CreateAiAutomationApprovalJob::class, 'booking', 'create_booking_draft'],
        ];
    }

    #[DataProvider('jobs')]
    public function test_legacy_serialized_jobs_cannot_mutate_even_with_active_rules(string $jobClass, string $target, string $action): void
    {
        Queue::fake();
        [$customer, $conversation, $message] = $this->conversation();
        $customer->update(['status' => 'inactive']);
        $data = $this->extracted($customer, $conversation, $message);
        $rule = AiAutomationRule::create(['name' => 'Legacy', 'trigger_event' => 'message_extracted', 'target_entity' => $target,
            'action' => $action, 'mode' => 'auto_create', 'confidence_threshold' => 0, 'is_active' => true]);
        $this->mock(AiAutomationExecutorService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('process');
            $mock->shouldNotReceive('processWithRule');
        });
        $job = unserialize(serialize(new $jobClass($data->id, $rule->id)));
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('followups', 0);
        $this->assertDatabaseCount('reminders', 0);
        $this->assertDatabaseCount('ai_automation_approvals', 0);
        $this->assertDatabaseCount('ai_automation_logs', 1);
        $this->assertSame('inactive', $customer->fresh()->status);
        $this->assertSame('DEMO Policy', $customer->fresh()->name);
        $this->assertSame('blocked', $data->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_direct_executor_and_approval_creation_are_blocked_before_profile_changes(): void
    {
        [$customer, $conversation, $message] = $this->conversation();
        $data = $this->extracted($customer, $conversation, $message);
        foreach (['create_booking_confirmed', 'create_booking_draft', 'cancel_booking', 'reschedule_booking', 'update_customer', 'create_followup'] as $action) {
            $rule = AiAutomationRule::create(['name' => $action, 'trigger_event' => 'message_extracted',
                'target_entity' => str_contains($action, 'customer') ? 'customer' : 'booking', 'action' => $action,
                'mode' => 'auto_create', 'confidence_threshold' => 0, 'is_active' => true]);
            $result = app(AiAutomationExecutorService::class)->processWithRule($data, $rule);
            $this->assertSame('blocked', $result->status);
        }
        $this->assertSame('blocked', app(AiAutomationExecutorService::class)->process($data)->status);
        $this->assertSame('DEMO Policy', $customer->fresh()->name);
        $this->assertDatabaseCount('bookings', 0);
        $this->expectException(ValidationException::class);
        app(AiAutomationApprovalService::class)->create($data, $rule, ['customer' => ['name' => 'Changed']]);
    }

    public function test_flow_and_extraction_entrypoints_do_not_start_transactions(): void
    {
        [$customer, $conversation, $message] = $this->conversation();
        $flow = ConversationFlow::create(['conversation_id' => $conversation->id, 'customer_id' => $customer->id, 'intent' => 'booking_new',
            'step' => 'confirmation', 'status' => 'active', 'payload' => ['name' => 'Original']]);
        $this->assertNull(app(ConversationFlowService::class)->handle($conversation, $message));
        $this->assertSame('held', $flow->fresh()->status);
        $this->assertSame('Original', $flow->fresh()->payload['name']);
        app()->call([new ExtractDataFromIncomingMessageJob($message->id), 'handle']);
        $this->assertDatabaseCount('ai_extracted_data', 0);
        $this->assertDatabaseCount('bookings', 0);
    }

    public static function approvalActions(): array
    {
        return [['approve'], ['edit-approve'], ['reject']];
    }

    #[DataProvider('approvalActions')]
    public function test_legacy_approval_http_actions_never_touch_booking_or_profile(string $action): void
    {
        [$customer, $conversation, $message] = $this->conversation();
        $data = $this->extracted($customer, $conversation, $message);
        $service = Service::create(['name' => 'DEMO Service', 'duration_minutes' => 60, 'price' => 100000, 'is_active' => true]);
        $booking = Booking::create(['booking_code' => 'BK-DEMO-POLICY', 'customer_id' => $customer->id, 'service_id' => $service->id,
            'booking_date' => now()->addDay()->toDateString(), 'start_time' => '10:00:00', 'end_time' => '11:00:00',
            'status' => 'pending', 'payment_status' => 'unpaid']);
        $approval = AiAutomationApproval::create(['ai_extracted_data_id' => $data->id, 'customer_id' => $customer->id, 'conversation_id' => $conversation->id,
            'target_entity' => 'booking', 'action' => 'create_booking_confirmed', 'mode' => 'need_confirmation', 'status' => 'pending',
            'proposed_data' => ['booking' => ['booking_id' => $booking->id], 'customer' => ['name' => 'Changed']]]);
        $before = $booking->fresh()->getRawOriginal();
        $owner = User::factory()->create(['role' => 'owner', 'status' => 'active']);
        $this->actingAs($owner)->get(route('admin.ai.data-automation.approvals.show', $approval))
            ->assertOk()->assertSee('Transaksi dan perubahan profil dari AI lama dikunci')
            ->assertDontSee('action="'.route('admin.ai.data-automation.approvals.approve', $approval).'"', false)
            ->assertDontSee('action="'.route('admin.ai.data-automation.approvals.reject', $approval).'"', false);
        $this->actingAs($owner)->postJson(route('admin.ai.data-automation.approvals.'.$action, $approval), [
            'rejection_reason' => 'Attempt old cancellation',
        ])->assertUnprocessable()->assertJsonValidationErrors('approval');
        $this->assertSame($before, $booking->fresh()->getRawOriginal());
        $this->assertSame('DEMO Policy', $customer->fresh()->name);
        $this->assertSame('held', $approval->fresh()->status);
        $this->assertDatabaseCount('booking_payments', 0);
    }

    public static function sendChanges(): array
    {
        return [['missing'], ['off'], ['takeover'], ['disabled'], ['expired'], ['new_incoming'], ['admin_reply'], ['revision'], ['recipient'], ['provider'], ['session'], ['assigned']];
    }

    #[DataProvider('sendChanges')]
    public function test_pending_ai_send_and_manual_retry_are_rechecked(string $change): void
    {
        [$customer, $conversation, $message] = $this->conversation();
        $policy = app(AiInteractionPolicy::class);
        $text = 'Pemesanan melalui web.';
        $context = $policy->replyContext($conversation, $message, $text, $policy->snapshot());
        if ($change === 'missing') {
            $context = null;
        }
        if ($change === 'off') {
            DB::table('ai_interaction_policies')->where('id', 1)->update(['mode' => 'off']);
        }
        if ($change === 'takeover') {
            $conversation->update(['status' => 'human_handled']);
        }
        if ($change === 'disabled') {
            $conversation->update(['ai_enabled' => false]);
        }
        if ($change === 'assigned') {
            $conversation->update(['assigned_user_id' => User::factory()->create(['role' => 'admin', 'status' => 'active'])->id]);
        }
        if ($change === 'expired') {
            $context['expires_at'] = now()->subSecond()->timestamp;
        }
        if ($change === 'revision') {
            DB::table('ai_interaction_policies')->where('id', 1)->increment('revision');
        }
        if ($change === 'recipient') {
            $conversation->update(['wa_chat_id' => '6281234000709@c.us']);
        }
        if ($change === 'provider') {
            app(AppSettingService::class)->set('whatsapp.provider', 'baileys');
        }
        if ($change === 'session') {
            $conversation->whatsappSession->update(['session_name' => 'changed-session']);
        }
        if (in_array($change, ['new_incoming', 'admin_reply'], true)) {
            Message::create(['conversation_id' => $conversation->id, 'customer_id' => $customer->id, 'message_type' => 'text',
                'direction' => $change === 'new_incoming' ? 'incoming' : 'outgoing',
                'sender_type' => $change === 'new_incoming' ? 'customer' : 'admin', 'content' => 'New context']);
        }
        $job = unserialize(serialize(new SendWhatsAppMessageJob($conversation->id, null, 'ai', $text, 'default', null, null, $context)));
        $job->handle(app(WhatsAppGateway::class));
        $outgoing = Message::where('sender_type', 'ai')->firstOrFail();
        $this->assertSame('skipped', $outgoing->delivery_status);
        $this->assertNotNull(app(OutboundMessages::class)->retryProblem($outgoing));
        Http::assertNothingSent();
    }

    public function test_valid_information_reply_is_sent_and_replay_is_idempotent(): void
    {
        [$customer, $conversation, $message] = $this->conversation();
        $policy = app(AiInteractionPolicy::class);
        $text = 'Gunakan formulir web untuk pemesanan.';
        $context = $policy->replyContext($conversation, $message, $text, $policy->snapshot());
        $job = unserialize(serialize(new SendWhatsAppMessageJob($conversation->id, null, 'ai', $text, 'default', null, null, $context)));
        $job->handle(app(WhatsAppGateway::class));
        $job->handle(app(WhatsAppGateway::class));
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseHas('messages', ['sender_type' => 'ai', 'delivery_status' => 'sent']);
        Http::assertSentCount(1);
    }

    public function test_takeover_during_typing_blocks_last_moment_send(): void
    {
        [$customer, $conversation, $message] = $this->conversation();
        config(['waha.typing_enabled' => true, 'waha.typing_delay_ms' => 0]);
        Http::fake(function ($request) use ($conversation) {
            if (str_contains($request->url(), 'startTyping')) {
                $conversation->update(['status' => 'human_handled']);
            }

            return Http::response(['id' => 'test'], 200);
        });
        $policy = app(AiInteractionPolicy::class);
        $context = $policy->replyContext($conversation, $message, 'Info', $policy->snapshot());
        (new SendWhatsAppMessageJob($conversation->id, null, 'ai', 'Info', 'default', null, null, $context))->handle(app(WhatsAppGateway::class));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'sendText'));
        $this->assertDatabaseHas('messages', ['sender_type' => 'ai', 'delivery_status' => 'skipped', 'failure_code' => 'human_takeover']);
    }

    public function test_mode_command_holds_legacy_records_without_modifying_orders(): void
    {
        $initialRevision = app(AiInteractionPolicy::class)->snapshot()['revision'];
        [$customer, $conversation, $message] = $this->conversation();
        ConversationFlow::create(['conversation_id' => $conversation->id, 'intent' => 'booking_new', 'step' => 'name', 'status' => 'active', 'payload' => ['keep' => true]]);
        $data = $this->extracted($customer, $conversation, $message);
        $approval = AiAutomationApproval::create(['ai_extracted_data_id' => $data->id, 'target_entity' => 'booking', 'action' => 'cancel_booking',
            'mode' => 'need_confirmation', 'proposed_data' => ['keep' => true], 'status' => 'pending']);
        $this->artisan('ai:interaction-mode information_web')->assertSuccessful();
        $this->artisan('ai:interaction-mode information_web')->assertSuccessful();
        $this->assertSame('held', $approval->fresh()->status);
        $this->assertTrue(ConversationFlow::first()->payload['keep']);
        $this->assertDatabaseCount('bookings', 0);
        $this->artisan('ai:interaction-mode off')->assertSuccessful();
        $this->assertSame($initialRevision + 1, app(AiInteractionPolicy::class)->snapshot()['revision']);
        $this->artisan('ai:interaction-mode information_web')->assertSuccessful();
        $this->assertSame($initialRevision + 2, app(AiInteractionPolicy::class)->snapshot()['revision']);
        $this->artisan('ai:interaction-mode legacy_transaction')->assertFailed();
    }

    public function test_ai_legacy_reminder_is_not_sent_but_off_does_not_block_admin_message(): void
    {
        [$customer, $conversation, $message] = $this->conversation();
        $reminder = Reminder::create(['customer_id' => $customer->id, 'conversation_id' => $conversation->id, 'type' => 'followup',
            'channel' => 'whatsapp', 'status' => 'scheduled', 'scheduled_at' => now(), 'payload' => ['source' => 'ai_automation']]);
        (new SendBookingReminderJob($reminder->id))->handle(app(WhatsAppGateway::class));
        $this->assertSame('cancelled', $reminder->fresh()->status);
        Http::assertNothingSent();
        DB::table('ai_interaction_policies')->where('id', 1)->update(['mode' => 'off']);
        (new SendWhatsAppMessageJob($conversation->id, null, 'admin', 'Admin manual', 'default'))->handle(app(WhatsAppGateway::class));
        $this->assertDatabaseHas('messages', ['sender_type' => 'admin', 'delivery_status' => 'sent']);
    }

    public function test_legacy_lookup_and_simulator_are_information_only_and_hide_private_details(): void
    {
        [$customer, $conversation, $message] = $this->conversation();
        $result = app(AiService::class)->generateReply($conversation, $message);
        $this->assertStringContainsString('admin', $result['reply']);
        $this->assertDatabaseCount('ai_extracted_data', 0);
        $this->assertDatabaseCount('bookings', 0);
        $preview = app(AiService::class)->preview('Cek kode orang lain', ['enabled' => true]);
        $this->assertStringNotContainsString('DEMO Policy', $preview['reply']);
        $this->assertDatabaseCount('ai_logs', 1);
        Http::assertNothingSent();
    }

    public function test_requested_handoff_keeps_unread_and_allows_only_its_notice(): void
    {
        Queue::fake();
        [, $conversation, $message] = $this->conversation();
        $message->update(['content' => 'admin']);
        $this->generate($conversation, $message);
        $this->assertSame('escalated', $conversation->fresh()->status);
        $this->assertFalse($conversation->fresh()->ai_enabled);
        $this->assertSame(1, $conversation->fresh()->unread_count);
        Queue::assertPushed(SendWhatsAppMessageJob::class, function ($job) {
            $job->handle(app(WhatsAppGateway::class));

            return true;
        });
        $this->assertDatabaseHas('messages', ['sender_type' => 'ai', 'delivery_status' => 'sent']);
        Http::assertSentCount(1);
    }

    public function test_takeover_during_reply_generation_is_not_overwritten_or_dispatched(): void
    {
        Queue::fake();
        [, $conversation, $message] = $this->conversation();
        $ai = $this->mock(AiService::class, function (MockInterface $mock) use ($conversation) {
            $mock->shouldReceive('generateReply')->once()->andReturnUsing(function () use ($conversation) {
                $conversation->update(['status' => 'human_handled', 'ai_enabled' => false]);

                return ['reply' => 'Delayed answer', 'status' => 'success'];
            });
        });
        (new GenerateAiReplyJob($conversation->id, $message->id))->handle($ai, app(AppSettingService::class), app(ConversationFlowService::class));
        $this->assertSame('human_handled', $conversation->fresh()->status);
        Queue::assertNothingPushed();
        $this->assertNull($message->fresh()->ai_reply_dispatched_at);
    }

    public function test_public_reply_does_not_enable_closed_services_or_trust_request_host(): void
    {
        config(['app.url' => 'https://gayatri-kediri.web.id']);
        URL::forceRootUrl('https://attacker.test');
        app(AppSettingService::class)->set('rental.settings', json_encode(['enabled' => false]));
        $result = app(AiService::class)->simulate('Booking pijat bayi dan tandai lunas');
        $this->assertStringContainsString('https://gayatri-kediri.web.id/booking', $result['reply']);
        $this->assertStringNotContainsString('attacker.test', $result['reply']);
        $this->assertStringNotContainsString('/rental', $result['reply']);
        $this->assertStringNotContainsString('/laundry', $result['reply']);
        $this->assertSame(false, app(RentalSettings::class)->get()['enabled']);
        Http::assertNothingSent();
    }

    public function test_current_policy_is_read_again_after_global_customization_changes(): void
    {
        $policy = app(AiInteractionPolicy::class);
        $this->assertTrue($policy->snapshot()['enabled']);
        app(AppSettingService::class)->set('ai.chat.customization', json_encode(['enabled' => false]));
        $this->assertFalse($policy->snapshot()['enabled']);
        app(AppSettingService::class)->set('ai.chat.customization', json_encode(['enabled' => true]));
        $this->assertTrue($policy->snapshot()['enabled']);
    }

    public function test_takeover_and_resume_never_reenable_an_old_queued_reply(): void
    {
        [, $conversation, $message] = $this->conversation();
        $policy = app(AiInteractionPolicy::class);
        $text = 'Old queued reply';
        $context = $policy->replyContext($conversation, $message, $text, $policy->snapshot());
        $conversation = $conversation->fresh();
        $conversation->update(['status' => 'human_handled', 'ai_enabled' => false]);
        $conversation->update(['status' => 'open', 'ai_enabled' => true]);
        $this->assertSame(2, (int) $conversation->ai_control_revision);
        (new SendWhatsAppMessageJob($conversation->id, null, 'ai', $text, 'default', null, null, $context))->handle(app(WhatsAppGateway::class));
        $this->assertDatabaseHas('messages', ['sender_type' => 'ai', 'delivery_status' => 'skipped', 'failure_code' => 'stale_context']);
        Http::assertNothingSent();
    }

    public function test_admin_pages_and_simulator_explain_information_mode_without_mutating(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['role' => 'owner', 'status' => 'active']);
        foreach (['admin.ai.auto-replies.index', 'admin.ai.simulator', 'admin.ai.data-automation.index', 'admin.ai.data-automation.approvals.index'] as $route) {
            $this->actingAs($owner)->get(route($route))->assertOk()->assertSee('Informasi &amp; pemesanan web', false);
        }
        $this->actingAs($owner)->post(route('admin.ai.simulator.test'), [
            'message' => 'Booking pijat bayi sekarang, sudah bayar, konfirmasi!',
        ])->assertOk()->assertSee('Pembayaran masih diperiksa admin secara manual')->assertSee('belum berarti');
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('ai_extracted_data', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_global_disable_and_reenable_invalidates_preexisting_reply(): void
    {
        $initialRevision = app(AiInteractionPolicy::class)->snapshot()['revision'];
        [, $conversation, $message] = $this->conversation();
        $policy = app(AiInteractionPolicy::class);
        $text = 'Old global reply';
        $context = $policy->replyContext($conversation, $message, $text, $policy->snapshot());
        $owner = User::factory()->create(['role' => 'owner', 'status' => 'active']);
        $settings = app(AiChatCustomization::class)->defaults();
        $settings = ['enabled' => true, 'service_domains' => $settings['service_domains'],
            'preferences' => app(AiConversationPreferences::class)->defaults()];
        foreach ([false, true] as $enabled) {
            $settings['enabled'] = $enabled;
            $settings['revision'] = $policy->snapshot()['revision'];
            $this->actingAs($owner)->post(route('admin.ai.auto-replies.update'), $settings)
                ->assertSessionHasNoErrors()->assertRedirect();
        }
        $this->assertTrue($policy->snapshot()['enabled']);
        $this->assertSame($initialRevision + 2, $policy->snapshot()['revision']);
        (new SendWhatsAppMessageJob($conversation->id, null, 'ai', $text, 'default', null, null, $context))->handle(app(WhatsAppGateway::class));
        $this->assertDatabaseHas('messages', ['sender_type' => 'ai', 'delivery_status' => 'skipped', 'failure_code' => 'stale_policy']);
        Http::assertNothingSent();
    }
}
