<?php

namespace Tests\Feature;

use App\Jobs\SendBirthdayGreetingJob;
use App\Jobs\SendBookingReminderJob;
use App\Jobs\SendLaundryNotificationJob;
use App\Jobs\SendRentalNotificationJob;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\BirthdayGreeting;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\LaundryNotification;
use App\Models\LaundryOrder;
use App\Models\Message;
use App\Models\Reminder;
use App\Models\RentalNotification;
use App\Models\RentalOrder;
use App\Models\User;
use App\Services\AppSettingService;
use App\Services\CRM\BirthdayGreetingService;
use App\Services\CRM\LaundryNotificationService;
use App\Services\CRM\ReminderService;
use App\Services\CRM\RentalNotificationService;
use App\Services\WhatsApp\GatewayHealth;
use App\Services\WhatsApp\OutboundMessages;
use App\Services\WhatsApp\QueueMonitor;
use App\Services\WhatsApp\WhatsAppGateway;
use App\Services\WhatsApp\WhatsAppMonitor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WhatsAppMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private Conversation $conversation;

    private string $healthState = 'WORKING';

    private int $healthStatus = 200;

    private bool $healthStubbed = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-01 10:00:00', 'Asia/Jakarta'));
        Http::preventStrayRequests();
        Queue::fake();
        config(['whatsapp.provider' => 'baileys', 'baileys.base_url' => 'http://baileys.test', 'baileys.api_key' => 'secret-monitor-test-DO-NOT-RENDER', 'baileys.default_session' => 'gayatri-main', 'baileys.typing_enabled' => false, 'queue.default' => 'database']);
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->customer = Customer::create(['name' => 'Pelanggan Monitoring', 'whatsapp_number' => '6280000094101', 'status' => 'active', 'birth_date' => '1990-11-01']);
        $this->conversation = Conversation::create(['customer_id' => $this->customer->id, 'wa_chat_id' => '6280000094101@c.us', 'status' => 'open', 'ai_enabled' => true]);
        $this->actingAs($this->admin);
        $this->health();
    }

    private function health(string $state = 'WORKING', int $status = 200): void
    {
        $this->healthState = $state;
        $this->healthStatus = $status;
        if (! $this->healthStubbed) {
            Http::fake(['http://baileys.test/health' => fn () => Http::response(['ok' => true, 'sessions' => [['name' => 'gayatri-main', 'status' => $this->healthState, 'config' => ['api_key' => 'secret-monitor-test-DO-NOT-RENDER']]], 'webhooks' => ['pending' => 3, 'max_attempts' => 2]], $this->healthStatus)]);
            $this->healthStubbed = true;
        }
    }

    private function message(array $changes = []): Message
    {
        $message = Message::create(array_replace(['conversation_id' => $this->conversation->id, 'customer_id' => $this->customer->id, 'user_id' => $this->admin->id, 'direction' => 'outgoing', 'sender_type' => 'admin', 'message_type' => 'text', 'content' => 'Pesan uji monitoring.', 'delivery_status' => 'failed', 'failed_at' => now(), 'failed_reason' => 'Gateway menolak permintaan.', 'send_provider' => 'baileys', 'send_session' => 'gayatri-main', 'send_chat_id' => '6280000094101@c.us'], $changes));
        if (isset($changes['created_at'])) {
            $message->forceFill(['created_at' => $changes['created_at']])->save();
        }

        return $message->fresh();
    }

    private function retryData(string $source, $row, array $changes = []): array
    {
        return array_replace(['fingerprint' => WhatsAppMonitor::fingerprint($source, $row->fresh()), 'reason' => 'Chat, tujuan, dan isi sudah diperiksa; pesan belum diterima.', 'confirmed_not_sent' => 1], $changes);
    }

    private function retryUrl(string $source, $row): string
    {
        return route('admin.whatsapp.monitor.retry', [$source, $row->id]);
    }

    private function rentalOrder(): RentalOrder
    {
        return RentalOrder::create(['code' => 'RENT-MON-'.Str::random(8), 'quote_id' => (string) Str::uuid(), 'request_session_hash' => str_repeat('a', 64), 'customer_id' => $this->customer->id, 'status' => 'reserved', 'start_date' => today(), 'end_date' => today()->addDays(7), 'period' => 'weekly', 'period_count' => 1, 'rental_subtotal' => 100000, 'deposit_total' => 0, 'pricing_snapshot' => []]);
    }

    private function laundryOrder(): LaundryOrder
    {
        return LaundryOrder::create(['code' => 'LND-MON-'.Str::random(8), 'entry_key' => (string) Str::uuid(), 'input_hash' => str_repeat('a', 64), 'customer_id' => $this->customer->id, 'customer_snapshot' => ['name' => $this->customer->name, 'whatsapp_number' => $this->customer->whatsapp_number, 'phone' => null], 'outlet_snapshot' => ['outlet_name' => 'Outlet Uji'], 'received_at' => now(), 'estimated_ready_at' => now()->addDay(), 'intake_mode' => 'walkin', 'status' => 'received']);
    }

    public function test_health_is_read_only_sanitized_cached_and_monitor_is_private(): void
    {
        $this->message();
        foreach (range(1, 2) as $_) {
            $this->get(route('admin.whatsapp.monitor'))->assertOk()->assertSee('Terhubung')->assertSee('Callback Baileys tertunda')->assertDontSee('secret-monitor-test-DO-NOT-RENDER')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
        }
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === 'http://baileys.test/health');
        Queue::assertNothingPushed();
    }

    public function test_gateway_failure_degrades_without_restarting_or_creating_a_session(): void
    {
        $this->health('FAILED', 503);
        $this->get(route('admin.whatsapp.monitor'))->assertOk()->assertSee('Gateway belum dapat dihubungi')->assertDontSee('secret-monitor-test-DO-NOT-RENDER');
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_roles_cannot_bypass_monitor_or_existing_inbox_retry(): void
    {
        $message = $this->message();
        foreach (['sales', 'therapist'] as $role) {
            $actor = User::factory()->create(['role' => $role, 'status' => 'active']);
            $this->actingAs($actor)->get(route('admin.whatsapp.monitor'))->assertForbidden();
            $this->post($this->retryUrl('message', $message), $this->retryData('message', $message))->assertForbidden();
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_database_queue_reports_only_whatsapp_tasks_without_exposing_payloads(): void
    {
        foreach ([[SendWhatsAppMessageJob::class, null, now()->timestamp], [SendBirthdayGreetingJob::class, null, now()->addHour()->timestamp], [SendWhatsAppMessageJob::class, now()->timestamp, now()->timestamp], ['App\\Jobs\\UnrelatedTask', null, now()->timestamp]] as [$class,$reserved,$available]) {
            DB::table('jobs')->insert(['queue' => 'default', 'payload' => json_encode(['displayName' => $class, 'data' => ['command' => 'PRIVATE-PAYLOAD-DO-NOT-RENDER']]), 'attempts' => 0, 'reserved_at' => $reserved, 'available_at' => $available, 'created_at' => now()->timestamp]);
        }
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => json_encode(['displayName' => SendWhatsAppMessageJob::class, 'data' => ['command' => 'PRIVATE-PAYLOAD-DO-NOT-RENDER']]), 'exception' => 'PRIVATE-EXCEPTION-DO-NOT-RENDER', 'failed_at' => now()]);
        $response = $this->get(route('admin.whatsapp.monitor'))->assertOk()->assertSee('Menunggu jadwal')->assertDontSee('PRIVATE-PAYLOAD-DO-NOT-RENDER')->assertDontSee('PRIVATE-EXCEPTION-DO-NOT-RENDER');
        $this->assertSame(2, $response->viewData('queue')['pending']);
        $this->assertSame(1, $response->viewData('queue')['processing']);
        $this->assertSame(1, $response->viewData('queue')['failed']);
    }

    public function test_worker_heartbeat_tracks_each_queue_and_expires(): void
    {
        event(new Looping('database', 'default,laundry'));
        $workers = collect(app(QueueMonitor::class)->inspect()['workers'])->keyBy('queue');
        $this->assertNotNull($workers['default']['seen_at']);
        $this->assertNotNull($workers['laundry']['seen_at']);
        $this->travel(181)->seconds();
        $this->assertNull(collect(app(QueueMonitor::class)->inspect()['workers'])->keyBy('queue')['laundry']['seen_at']);
    }

    public function test_monitor_cache_failure_does_not_interrupt_workers_or_hide_gateway_status(): void
    {
        foreach (['add', 'get', 'remember', 'forget'] as $method) {
            Cache::shouldReceive($method)->andThrow(new \RuntimeException('Cache unavailable'));
        }
        QueueMonitor::heartbeat('database', 'default,laundry');
        $this->assertTrue(app(QueueMonitor::class)->inspect()['available']);
        $this->assertTrue(app(GatewayHealth::class)->inspect()['reachable']);
        $this->assertTrue(app(GatewayHealth::class)->inspect(true)['reachable']);
        Http::assertSentCount(2);
        Queue::assertNothingPushed();
    }

    public function test_retries_require_confirmation_reason_fresh_state_and_connected_gateway(): void
    {
        $message = $this->message();
        $this->post($this->retryUrl('message', $message), $this->retryData('message', $message, ['confirmed_not_sent' => 0]))->assertSessionHasErrors('confirmed_not_sent');
        $this->post($this->retryUrl('message', $message), $this->retryData('message', $message, ['reason' => '']))->assertSessionHasErrors('reason');
        $this->post($this->retryUrl('message', $message), $this->retryData('message', $message, ['fingerprint' => str_repeat('a', 64)]))->assertSessionHasErrors('retry');
        Http::assertNothingSent();
        $this->health('SCAN_QR_CODE');
        $this->post($this->retryUrl('message', $message), $this->retryData('message', $message))->assertSessionHasErrors('retry');
        $this->assertSame('failed', $message->fresh()->delivery_status);
        Queue::assertNothingPushed();
    }

    public function test_duplicate_retry_clicks_queue_one_job_and_record_one_audit(): void
    {
        $message = $this->message();
        $data = $this->retryData('message', $message);
        $this->post($this->retryUrl('message', $message), $data)->assertSessionHasNoErrors();
        $this->post($this->retryUrl('message', $message), $data)->assertSessionHasErrors('retry');
        Queue::assertPushed(SendWhatsAppMessageJob::class, 1);
        $this->assertSame('queued', $message->fresh()->delivery_status);
        $this->assertSame(1, $message->fresh()->retry_count);
        $this->assertDatabaseCount('whatsapp_retry_requests', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'whatsapp.message.retry', 'auditable_id' => $message->id]);
    }

    public function test_replayed_send_job_sends_once_and_retains_original_message(): void
    {
        Http::fake(['http://baileys.test/api/sendText' => Http::response(['id' => 'MON-SENT-1'], 200)]);
        $job = new SendWhatsAppMessageJob($this->conversation->id, $this->admin->id, 'admin', 'Pesan uji');
        $job->handle(app(WhatsAppGateway::class));
        unserialize(serialize($job))->handle(app(WhatsAppGateway::class));
        Http::assertSentCount(1);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('messages', ['wa_message_id' => 'MON-SENT-1', 'delivery_status' => 'sent', 'send_attempts' => 1]);
    }

    public function test_timeout_or_missing_message_id_is_uncertain_and_is_not_automatically_replayed(): void
    {
        Http::fake(['http://baileys.test/api/sendText' => Http::sequence()->push(['message' => 'secret-monitor-test-DO-NOT-RENDER'], 500)->push([], 200)]);
        foreach (range(1, 2) as $_) {
            $job = new SendWhatsAppMessageJob($this->conversation->id, $this->admin->id, 'admin', 'Pesan uji hasil belum pasti');
            $job->handle(app(WhatsAppGateway::class));
            $job->handle(app(WhatsAppGateway::class));
            $message = Message::latest('id')->firstOrFail();
            $this->assertSame('unknown', $message->delivery_status);
            $this->assertStringNotContainsString('secret-monitor-test-DO-NOT-RENDER', $message->failed_reason);
        }
        Http::assertSentCount(2);
    }

    public function test_explicit_retry_resends_same_record_once_and_success_locks_further_retry(): void
    {
        $message = $this->message(['delivery_status' => 'unknown']);
        $this->post($this->retryUrl('message', $message), $this->retryData('message', $message))->assertSessionHasNoErrors();
        $job = Queue::pushed(SendWhatsAppMessageJob::class)->first();
        Http::fake(['http://baileys.test/api/sendText' => Http::response(['id' => 'MON-RETRY-1'], 200)]);
        $job->handle(app(WhatsAppGateway::class));
        $job->handle(app(WhatsAppGateway::class));
        $this->assertDatabaseCount('messages', 1);
        $this->assertSame('sent', $message->fresh()->delivery_status);
        $this->post($this->retryUrl('message', $message), $this->retryData('message', $message))->assertSessionHasErrors('retry');
        Http::assertSent(fn ($request) => $request->url() === 'http://baileys.test/api/sendText');
    }

    public function test_delivered_archived_changed_recipient_old_ai_and_system_messages_are_locked(): void
    {
        $service = app(OutboundMessages::class);
        foreach ([['wa_message_id' => 'EXISTS'], ['sent_at' => now()], ['delivered_at' => now()], ['read_at' => now()], ['send_chat_id' => '6280000099999@c.us'], ['send_provider' => 'waha'], ['sender_type' => 'system'], ['created_at' => now()->subDays(2)], ['retry_count' => 5]] as $changes) {
            $message = $this->message($changes);
            $this->assertNotNull($service->retryProblem($message));
        }
        $message = $this->message(['sender_type' => 'ai', 'created_at' => now()->subMinute()]);
        $this->message(['direction' => 'incoming', 'sender_type' => 'customer', 'created_at' => now()]);
        $this->assertNotNull($service->retryProblem($message));
        $this->customer->update(['status' => 'inactive']);
        $this->assertNotNull($service->retryProblem($this->message()));
        Http::assertNothingSent();
    }

    public function test_worker_rechecks_recipient_after_a_retry_is_queued(): void
    {
        $message = $this->message();
        $this->post($this->retryUrl('message', $message), $this->retryData('message', $message))->assertSessionHasNoErrors();
        $job = Queue::pushed(SendWhatsAppMessageJob::class)->first();
        $this->conversation->update(['wa_chat_id' => '6280000099999@c.us']);
        $job->handle(app(WhatsAppGateway::class));
        $this->assertSame('skipped', $message->fresh()->delivery_status);
        Http::assertSentCount(1); // Only the read-only health check.
    }

    public function test_stalled_send_is_reported_as_uncertain_without_resetting_or_replaying_it(): void
    {
        $message = $this->message(['delivery_status' => 'sending', 'sending_started_at' => now()->subMinutes(11), 'failed_at' => null, 'failed_reason' => null]);
        $response = $this->get(route('admin.whatsapp.monitor', ['status' => 'unknown']))->assertOk()->assertSee('lebih dari 10 menit');
        $this->assertSame(1, (int) $response->viewData('counts')['unknown']);
        $this->assertSame('sending', $message->fresh()->delivery_status);
        $this->post($this->retryUrl('message', $message), $this->retryData('message', $message))->assertSessionHasErrors('retry');
        Queue::assertNothingPushed();
        Http::assertSentCount(1);
    }

    public function test_interrupted_worker_does_not_replay_and_legacy_failures_are_uncertain(): void
    {
        $message = $this->message(['delivery_status' => null, 'failed_reason' => 'Timeout https://host.test/?api_key=secret-monitor-test-DO-NOT-RENDER']);
        $response = $this->get(route('admin.whatsapp.monitor'))->assertOk()->assertDontSee('secret-monitor-test-DO-NOT-RENDER')->assertSee('Perlu diperiksa');
        $this->assertSame(1, (int) $response->viewData('counts')['unknown']);
        $this->assertSame($message->id, $response->viewData('rows')->first()->record_id);
        $token = (string) Str::uuid();
        $message->update(['delivery_status' => 'sending', 'dispatch_token' => $token]);
        $job = new SendWhatsAppMessageJob($this->conversation->id, null, 'admin', 'Uji', null, $message->id, $token);
        $job->failed(new \RuntimeException('Private exception'));
        $job->handle(app(WhatsAppGateway::class));
        $this->assertSame('unknown', $message->fresh()->delivery_status);
        Http::assertSentCount(1);
    }

    public function test_reminder_sends_once_and_monitor_does_not_double_count_its_inbox_record(): void
    {
        $reminder = Reminder::create(['customer_id' => $this->customer->id, 'conversation_id' => $this->conversation->id, 'type' => 'manual', 'channel' => 'whatsapp', 'status' => 'scheduled', 'scheduled_at' => now(), 'message' => 'Pengingat uji.']);
        Http::fake(['http://baileys.test/api/sendText' => Http::response(['id' => 'MON-REMINDER-1'], 200)]);
        foreach (range(1, 2) as $_) {
            (new SendBookingReminderJob($reminder->id))->handle(app(WhatsAppGateway::class));
        }
        $this->assertSame('sent', $reminder->fresh()->status);
        $response = $this->get(route('admin.whatsapp.monitor'))->assertOk();
        $this->assertSame(1, (int) $response->viewData('counts')['sent']);
        $this->assertSame('reminder', $response->viewData('rows')->first()->source);
        Message::whereKey($reminder->fresh()->message_id)->delete();
        $response = $this->get(route('admin.whatsapp.monitor'))->assertOk();
        $this->assertSame(1, (int) $response->viewData('counts')['sent']);
        $this->assertSame('reminder', $response->viewData('rows')->first()->source);
        $this->expectException(ValidationException::class);
        app(ReminderService::class)->retry($reminder->fresh());
    }

    public function test_birthday_retry_requires_current_context_and_sent_evidence_is_locked(): void
    {
        $service = app(BirthdayGreetingService::class);
        $settings = array_replace($service->settings(), ['enabled' => true, 'include_customer' => true, 'send_time' => '09:00']);
        app(AppSettingService::class)->set(BirthdayGreetingService::SETTING_KEY, json_encode($settings));
        $row = BirthdayGreeting::create(['customer_id' => $this->customer->id, 'birthday_type' => 'customer', 'birthday_on' => '2026-11-01', 'year' => 2026, 'scheduled_at' => now(), 'status' => 'failed', 'attempts' => 1, 'chat_id' => '6280000094101@c.us', 'session_name' => 'gayatri-main', 'message' => 'Ucapan uji.']);
        $this->post($this->retryUrl('birthday', $row), $this->retryData('birthday', $row))->assertSessionHasNoErrors();
        Queue::assertPushed(SendBirthdayGreetingJob::class, 1);
        $this->post($this->retryUrl('birthday', $row), $this->retryData('birthday', $row))->assertSessionHasErrors('retry');
        $row->update(['status' => 'failed', 'sent_at' => now(), 'wa_message_id' => 'ALREADY-SENT']);
        $this->post($this->retryUrl('birthday', $row), $this->retryData('birthday', $row))->assertSessionHasErrors('retry');
    }

    public function test_source_filters_dates_demo_exclusion_and_end_of_day_are_consistent(): void
    {
        $this->message(['delivery_status' => 'sent', 'failed_at' => null, 'sent_at' => '2026-11-01 23:59:59']);
        $this->message(['delivery_status' => 'queued']);
        $demo = Customer::create(['name' => '[DEMO] Monitoring', 'whatsapp_number' => '6280000094102', 'status' => 'active']);
        $this->message(['customer_id' => $demo->id, 'delivery_status' => 'unknown']);
        $params = ['start_date' => '2026-11-01', 'end_date' => '2026-11-01', 'source' => 'message'];
        $response = $this->get(route('admin.whatsapp.monitor', $params))->assertOk();
        $this->assertSame(2, $response->viewData('rows')->total());
        $this->assertSame(1, (int) $response->viewData('counts')['sent']);
        $this->assertSame(3, $this->get(route('admin.whatsapp.monitor', $params + ['include_demo' => 1]))->viewData('rows')->total());
        $this->get(route('admin.whatsapp.monitor', ['source' => 'invalid']))->assertSessionHasErrors('source');
        $this->get(route('admin.whatsapp.monitor', ['start_date' => '2026-11-02', 'end_date' => '2026-11-01']))->assertSessionHasErrors('start_date');
    }

    public function test_all_module_successes_are_combined_without_double_counting_their_inbox_messages(): void
    {
        $this->message(['delivery_status' => 'sent', 'wa_message_id' => 'GENERAL-1', 'sent_at' => now()]);
        $birthdayMessage = $this->message(['delivery_status' => 'sent', 'wa_message_id' => 'BIRTHDAY-1', 'sent_at' => now()]);
        BirthdayGreeting::create(['customer_id' => $this->customer->id, 'birthday_type' => 'customer', 'birthday_on' => today(), 'year' => 2026, 'scheduled_at' => now(), 'status' => 'sent', 'message' => 'Ucapan uji.', 'sent_at' => now(), 'wa_message_id' => 'BIRTHDAY-1', 'message_id' => $birthdayMessage->id]);
        $this->message(['delivery_status' => 'sent', 'wa_message_id' => 'RENTAL-1', 'sent_at' => now()]);
        RentalNotification::create(['rental_order_id' => $this->rentalOrder()->id, 'kind' => 'confirmed', 'event_key' => 'MON-RENTAL', 'status' => 'sent', 'sent_at' => now(), 'wa_message_id' => 'RENTAL-1', 'expires_at' => now()->addDay()]);
        $this->message(['delivery_status' => 'sent', 'wa_message_id' => 'LAUNDRY-1', 'sent_at' => now()]);
        LaundryNotification::create(['laundry_order_id' => $this->laundryOrder()->id, 'kind' => 'received', 'event_key' => hash('sha256', 'MON-LAUNDRY'), 'status' => 'sent', 'sent_at' => now(), 'wa_message_id' => 'LAUNDRY-1', 'context' => [], 'fingerprint' => str_repeat('a', 64), 'expires_at' => now()->addDay()]);
        $this->message(['delivery_status' => 'sent', 'wa_message_id' => 'CAMPAIGN-1', 'sent_at' => now()]);
        $campaign = Campaign::create(['name' => 'Campaign Uji', 'message_template' => 'Uji', 'status' => 'completed']);
        CampaignRecipient::create(['campaign_id' => $campaign->id, 'customer_id' => $this->customer->id, 'status' => 'sent', 'sent_at' => now(), 'wa_message_id' => 'CAMPAIGN-1']);
        $response = $this->get(route('admin.whatsapp.monitor'))->assertOk();
        $this->assertSame(5, $response->viewData('rows')->total());
        $this->assertSame(5, (int) $response->viewData('counts')['sent']);
        foreach (['message', 'birthday', 'rental', 'laundry', 'campaign'] as $source) {
            $this->assertSame(1, $this->get(route('admin.whatsapp.monitor', ['source' => $source]))->assertOk()->viewData('rows')->total());
        }
    }

    public function test_rental_retry_preserves_context_and_rechecks_order_before_queuing(): void
    {
        $service = app(RentalNotificationService::class);
        app(AppSettingService::class)->set('rental.notifications', json_encode(array_replace($service->settings(), ['enabled' => true])));
        $order = $this->rentalOrder();
        $row = $service->enqueue($order, 'confirmed', 'MON');
        $row->update(['status' => 'failed']);
        $this->post($this->retryUrl('rental', $row), $this->retryData('rental', $row))->assertSessionHasNoErrors();
        Queue::assertPushed(SendRentalNotificationJob::class, 2);
        $row->update(['status' => 'unknown']);
        $order->update(['status' => 'cancelled']);
        $this->post($this->retryUrl('rental', $row), $this->retryData('rental', $row))->assertSessionHasErrors('retry');
        Queue::assertPushed(SendRentalNotificationJob::class, 2);
    }

    public function test_laundry_retry_uses_current_fingerprint_and_stops_when_returned_to_simulation(): void
    {
        $service = app(LaundryNotificationService::class);
        $settings = array_replace($service->settings(), ['enabled' => true, 'dry_run' => false]);
        app(AppSettingService::class)->set('laundry.notifications', json_encode($settings));
        $row = $service->enqueue($this->laundryOrder(), null, 'received', 'MON');
        $row->update(['status' => 'failed']);
        $this->post($this->retryUrl('laundry', $row), $this->retryData('laundry', $row))->assertSessionHasNoErrors();
        Queue::assertPushed(SendLaundryNotificationJob::class, 2);
        $row->update(['status' => 'unknown']);
        app(AppSettingService::class)->set('laundry.notifications', json_encode(array_replace($settings, ['dry_run' => true])));
        $this->post($this->retryUrl('laundry', $row), $this->retryData('laundry', $row))->assertSessionHasErrors('retry');
        Queue::assertPushed(SendLaundryNotificationJob::class, 2);
    }
}
