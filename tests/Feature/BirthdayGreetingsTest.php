<?php

namespace Tests\Feature;

use App\Jobs\SendBirthdayGreetingJob;
use App\Models\BirthdayGreeting;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\User;
use App\Services\AppSettingService;
use App\Services\CRM\BirthdayGreetingService;
use App\Services\WhatsApp\WhatsAppGateway;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BirthdayGreetingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-26 09:30:00', 'Asia/Jakarta'));
        config(['whatsapp.provider' => 'waha', 'waha.base_url' => 'http://waha.test', 'waha.default_session' => 'default']);
        Http::preventStrayRequests();
    }

    public function test_admin_can_open_and_save_schedule_templates_and_audit(): void
    {
        $admin = $this->admin();
        $this->customer();
        $this->actingAs($admin)->get(route('admin.birthdays.index'))->assertOk()->assertSee('Ucapan Ulang Tahun')->assertSee('Jam Kirim (WIB)')->assertSee('Alya');
        $data = $this->defaults(['enabled' => true, 'include_baby' => true, 'send_time' => '10:00']);
        $this->post(route('admin.birthdays.update'), $data)->assertRedirect(route('admin.birthdays.index'))->assertSessionHasNoErrors();
        $this->assertTrue($this->service()->settings()['enabled']);
        $this->assertSame('10:00', $this->service()->settings()['send_time']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'birthday.settings.update']);
        Http::assertNothingSent();
    }

    public function test_sales_and_therapist_cannot_change_or_process_birthdays(): void
    {
        foreach (['sales', 'therapist'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'active']))->get(route('admin.birthdays.index'))->assertForbidden();
            $this->post(route('admin.birthdays.update'), $this->defaults())->assertForbidden();
            $this->post(route('admin.birthdays.process'))->assertForbidden();
            $this->post(route('admin.birthdays.preview'), $this->defaults())->assertForbidden();
        }
        $this->assertDatabaseCount('birthday_greetings', 0);
    }

    public function test_preview_uses_unsaved_template_without_delivery_or_settings_changes(): void
    {
        $customer = $this->customer(['baby_name' => 'Ari', 'baby_birth_date' => '2020-10-26']);
        $this->actingAs($this->admin())->post(route('admin.birthdays.preview'), $this->defaults([
            'baby_template' => 'Selamat ulang tahun ke-{usia}, {nama_bayi}! Dari {nama_bisnis}.',
        ]) + ['preview_customer_id' => $customer->id, 'preview_type' => 'baby'])->assertOk()->assertSee('Selamat ulang tahun ke-6, Ari! Dari Gayatri.');
        $this->assertDatabaseCount('app_settings', 0);
        $this->assertDatabaseCount('birthday_greetings', 0);
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    public function test_disabled_feature_and_time_before_schedule_never_queue_messages(): void
    {
        Queue::fake();
        $this->customer();
        $this->assertSame(0, $this->service()->dispatchDue());
        $this->configure(['enabled' => true, 'send_time' => '10:00']);
        $this->assertSame(0, $this->service()->dispatchDue());
        $this->assertDatabaseCount('birthday_greetings', 0);
        Queue::assertNothingPushed();
    }

    public function test_scheduler_catches_up_today_and_queues_only_once_per_customer_type_year(): void
    {
        Queue::fake();
        $customer = $this->customer();
        $this->configure(['enabled' => true]);
        $this->assertSame(1, $this->service()->dispatchDue());
        $this->assertSame(0, $this->service()->dispatchDue());
        Queue::assertPushed(SendBirthdayGreetingJob::class, 1);
        $this->assertDatabaseHas('birthday_greetings', ['customer_id' => $customer->id, 'birthday_type' => 'customer', 'year' => 2026, 'status' => 'queued']);
        $this->assertDatabaseCount('birthday_greetings', 1);
    }

    public function test_customer_and_baby_birthdays_get_separate_greetings_and_toggles(): void
    {
        Queue::fake();
        $this->customer(['baby_name' => 'Ari', 'baby_birth_date' => '2020-10-26']);
        $this->configure(['enabled' => true, 'include_baby' => true]);
        $this->assertSame(2, $this->service()->dispatchDue());
        $this->assertDatabaseHas('birthday_greetings', ['birthday_type' => 'baby', 'message' => 'Selamat ulang tahun ke-6 untuk Ari! 🎂 Semoga tumbuh sehat, ceria, dan penuh kebahagiaan. Salam hangat untuk keluarga Alya dari Gayatri.']);
        Queue::assertPushed(SendBirthdayGreetingJob::class, 2);
    }

    public function test_inactive_blacklisted_invalid_future_and_newborn_customers_are_skipped(): void
    {
        Queue::fake();
        $this->customer(['status' => 'blacklisted']);
        $this->customer(['status' => 'inactive', 'whatsapp_number' => '62822222222']);
        $this->customer(['status' => 'blocked', 'whatsapp_number' => '62833333333']);
        $this->customer(['whatsapp_number' => null]);
        $this->customer(['whatsapp_number' => 'invalid-number']);
        $this->customer(['whatsapp_number' => '62844444444', 'birth_date' => '2027-10-26']);
        $this->customer(['whatsapp_number' => '62855555555', 'birth_date' => '2026-10-26']);
        $this->configure(['enabled' => true]);
        $this->assertSame(0, $this->service()->dispatchDue());
        $this->assertDatabaseCount('birthday_greetings', 0);
        Queue::assertNothingPushed();
    }

    public function test_successful_job_records_message_and_cannot_send_twice(): void
    {
        config(['crm.default_ai_mode' => 'manual']);
        Http::fake(['http://waha.test/api/sendText' => Http::response(['id' => 'birthday-sent-1'], 200)]);
        $customer = $this->customer();
        $this->configure(['enabled' => true]);
        $greeting = $this->greeting($customer);
        $this->runJob($greeting);
        $this->runJob($greeting);
        Http::assertSentCount(1);
        $this->assertDatabaseHas('birthday_greetings', ['id' => $greeting->id, 'status' => 'sent', 'wa_message_id' => 'birthday-sent-1', 'attempts' => 1]);
        $this->assertDatabaseHas('messages', ['customer_id' => $customer->id, 'sender_type' => 'system', 'wa_message_id' => 'birthday-sent-1']);
        $this->assertNotNull($greeting->fresh()->message_id);
    }

    public function test_existing_lid_conversation_is_used_and_human_takeover_is_preserved(): void
    {
        Http::fake(['http://waha.test/api/sendText' => Http::response(['id' => 'birthday-sent-1'], 200)]);
        $customer = $this->customer(['whatsapp_number' => '12345678901234']);
        $conversation = Conversation::create(['customer_id' => $customer->id, 'wa_chat_id' => '12345678901234@lid', 'status' => 'human_handled', 'channel' => 'whatsapp', 'ai_enabled' => false]);
        $this->configure(['enabled' => true]);
        $this->runJob($this->greeting($customer));
        Http::assertSent(fn ($request) => $request['chatId'] === '12345678901234@lid');
        $this->assertSame('human_handled', $conversation->fresh()->status);
        $this->assertFalse($conversation->fresh()->ai_enabled);
        $this->assertDatabaseCount('conversations', 1);
    }

    public function test_disabling_feature_after_queueing_pauses_unsent_jobs(): void
    {
        $customer = $this->customer();
        $this->configure(['enabled' => false]);
        $greeting = $this->greeting($customer);
        $this->runJob($greeting);
        $this->assertSame('queued', $greeting->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_jobs_never_send_on_the_day_after_the_birthday_or_after_customer_edits(): void
    {
        $this->configure(['enabled' => true]);
        $customer = $this->customer();
        $old = $this->greeting($customer, ['birthday_on' => '2026-10-25']);
        $this->runJob($old);
        $this->assertSame('skipped', $old->fresh()->status);
        $other = $this->customer(['whatsapp_number' => '62822222222']);
        $edited = $this->greeting($other);
        $other->update(['birth_date' => '1992-10-27']);
        $this->runJob($edited);
        $this->assertSame('skipped', $edited->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_disconnected_gateway_failure_can_be_manually_retried(): void
    {
        $this->configure(['enabled' => true]);
        $greeting = $this->greeting($this->customer());
        Http::fake(['http://waha.test/api/sendText' => Http::response(['message' => 'WhatsApp belum tersambung'], 409)]);
        $this->runJob($greeting);
        $this->assertSame('failed', $greeting->fresh()->status);
        Queue::fake();
        $this->actingAs($this->admin())->post(route('admin.birthdays.retry', $greeting))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('queued', $greeting->fresh()->status);
        Queue::assertPushed(SendBirthdayGreetingJob::class, 1);
    }

    public function test_timeout_is_not_automatically_retried_and_requires_explicit_verification(): void
    {
        $this->configure(['enabled' => true]);
        $greeting = $this->greeting($this->customer());
        Http::fake(fn () => throw new ConnectionException('Request timeout'));
        $this->runJob($greeting);
        $this->assertSame('unknown', $greeting->fresh()->status);
        $this->runJob($greeting);
        Http::assertSentCount(0); // connection failed before fake recorded a successful response
        Queue::fake();
        $this->actingAs($this->admin())->post(route('admin.birthdays.retry', $greeting))->assertSessionHasErrors('confirmed_not_sent');
        $this->assertSame('unknown', $greeting->fresh()->status);
        $this->post(route('admin.birthdays.retry', $greeting), ['confirmed_not_sent' => 1])->assertSessionHasNoErrors();
        $this->assertSame('queued', $greeting->fresh()->status);
        Queue::assertPushed(SendBirthdayGreetingJob::class, 1);
    }

    public function test_missing_message_id_is_uncertain_and_sent_greetings_cannot_be_retried(): void
    {
        $this->configure(['enabled' => true]);
        $greeting = $this->greeting($this->customer());
        Http::fake(['http://waha.test/api/sendText' => Http::response(['status' => 'sent'], 200)]);
        $this->runJob($greeting);
        $this->assertSame('unknown', $greeting->fresh()->status);
        $greeting->update(['status' => 'sent', 'wa_message_id' => 'confirmed-id']);
        $this->actingAs($this->admin())->post(route('admin.birthdays.retry', $greeting))->assertSessionHasErrors('birthday');
        Http::assertSentCount(1);
    }

    public function test_rate_limit_spaces_multiple_sends_without_marking_waiting_messages_as_sent(): void
    {
        $this->configure(['enabled' => true]);
        $first = $this->greeting($this->customer());
        $second = $this->greeting($this->customer(['whatsapp_number' => '62822222222']));
        Http::fake(['http://waha.test/api/sendText' => Http::sequence()->push(['id' => 'birthday-1'])->push(['id' => 'birthday-2'])]);
        $this->runJob($first);
        $this->runJob($second);
        $this->assertSame('queued', $second->fresh()->status);
        Http::assertSentCount(1);
        $this->travel(11)->seconds();
        $this->runJob($second);
        $this->assertSame('sent', $second->fresh()->status);
        Http::assertSentCount(2);
    }

    public function test_leap_day_policy_and_real_leap_year_are_supported(): void
    {
        $this->customer(['birth_date' => '1992-02-29']);
        $settings = $this->defaults();
        $this->assertCount(1, $this->service()->occurrences(Carbon::parse('2027-02-28'), $settings));
        $this->assertCount(0, $this->service()->occurrences(Carbon::parse('2027-03-01'), $settings));
        $settings['leap_day'] = 'mar1';
        $this->assertCount(1, $this->service()->occurrences(Carbon::parse('2027-03-01'), $settings));
        $this->assertCount(0, $this->service()->occurrences(Carbon::parse('2028-02-28'), $settings));
        $this->assertCount(1, $this->service()->occurrences(Carbon::parse('2028-02-29'), $settings));
    }

    public function test_schedule_uses_wib_even_when_the_application_is_configured_in_utc(): void
    {
        Queue::fake();
        $this->customer();
        config(['app.timezone' => 'UTC']);
        $this->configure(['enabled' => true]);
        $this->travelTo(Carbon::parse('2026-10-26 01:59:00', 'UTC'));
        $this->assertSame(0, $this->service()->dispatchDue());
        $this->travelTo(Carbon::parse('2026-10-26 02:00:00', 'UTC'));
        $this->assertSame(1, $this->service()->dispatchDue());
        $this->assertSame('2026-10-26', BirthdayGreeting::first()->birthday_on->toDateString());
    }

    public function test_cli_and_laravel_schedule_are_registered_and_invalid_settings_are_rejected(): void
    {
        $this->artisan('crm:work-birthdays', ['--once' => true])->expectsOutputToContain('0 ucapan ulang tahun')->assertSuccessful();
        $events = collect(app(Schedule::class)->events());
        $event = $events->first(fn ($event) => str_contains((string) $event->command, 'crm:send-birthday-greetings'));
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertSame('Asia/Jakarta', $event->timezone);
        $this->actingAs($this->admin())->post(route('admin.birthdays.update'), $this->defaults(['include_customer' => false, 'include_baby' => false]))->assertSessionHasErrors('include_customer');
        $this->post(route('admin.birthdays.update'), $this->defaults(['send_time' => '25:99']))->assertSessionHasErrors('send_time');
    }

    public function test_worker_failure_marks_an_in_flight_delivery_as_uncertain(): void
    {
        $greeting = $this->greeting($this->customer(), ['status' => 'sending', 'started_at' => now()]);
        (new SendBirthdayGreetingJob($greeting->id))->failed(new \RuntimeException('Worker stopped'));
        $this->assertSame('unknown', $greeting->fresh()->status);
        Http::assertNothingSent();
    }

    private function defaults(array $overrides = []): array
    {
        return array_replace($this->service()->defaults(), $overrides);
    }

    private function configure(array $overrides): void
    {
        app(AppSettingService::class)->set(BirthdayGreetingService::SETTING_KEY, json_encode($this->defaults($overrides), JSON_UNESCAPED_UNICODE));
    }

    private function service(): BirthdayGreetingService
    {
        return app(BirthdayGreetingService::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    private function customer(array $attributes = []): Customer
    {
        return Customer::create(array_replace(['name' => 'Alya', 'whatsapp_number' => '62811111111', 'birth_date' => '1992-10-26', 'status' => 'active'], $attributes));
    }

    private function greeting(Customer $customer, array $attributes = []): BirthdayGreeting
    {
        return BirthdayGreeting::create(array_replace([
            'customer_id' => $customer->id, 'birthday_type' => 'customer', 'year' => 2026,
            'birthday_on' => '2026-10-26', 'scheduled_at' => '2026-10-26 09:00:00',
            'status' => 'queued', 'message' => 'Contoh ucapan',
        ], $attributes));
    }

    private function runJob(BirthdayGreeting $greeting): void
    {
        (new SendBirthdayGreetingJob($greeting->id))->handle(app(WhatsAppGateway::class), $this->service());
    }
}
