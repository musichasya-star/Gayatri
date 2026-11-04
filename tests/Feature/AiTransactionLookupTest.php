<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiReplyJob;
use App\Jobs\ProcessIncomingWhatsAppMessageJob;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\AiLog;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Feedback;
use App\Models\LaundryOrder;
use App\Models\LaundryPublicRequest;
use App\Models\Message;
use App\Models\RentalOrder;
use App\Models\Service;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Services\AI\AiConversationContext;
use App\Services\AI\AiInteractionPolicy;
use App\Services\AI\AiLookupLimiter;
use App\Services\AI\AiReplyEvidence;
use App\Services\AI\AiService;
use App\Services\AI\AiTransactionLookup;
use App\Services\AI\KnowledgeRetrievalService;
use App\Services\CRM\FeedbackService;
use App\Services\WhatsApp\OutboundMessages;
use App\Services\WhatsApp\WhatsAppSenderIdentity;
use App\Support\CustomerPhone;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AiTransactionLookupTest extends TestCase
{
    use RefreshDatabase;

    private Conversation $conversation;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-01 10:00:00', 'Asia/Jakarta'));
        Queue::fake();
        config(['whatsapp.provider' => 'baileys', 'baileys.webhook_secret' => 'lookup-test-secret', 'baileys.default_session' => 'main']);
        $customer = Customer::create(['name' => 'Pemilik', 'whatsapp_number' => '628123450001', 'status' => 'active']);
        $session = WhatsAppSession::create(['session_name' => 'main', 'status' => 'WORKING']);
        $this->conversation = Conversation::create(['customer_id' => $customer->id, 'whatsapp_session_id' => $session->id,
            'wa_chat_id' => '628123450001@c.us', 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true]);
        $service = Service::create(['name' => 'Pijat Bayi', 'service_group' => 'spa', 'price' => 100000, 'duration_minutes' => 60, 'is_active' => true]);
        $this->booking = Booking::create(['customer_id' => $customer->id, 'service_id' => $service->id, 'booking_code' => 'BK-C-1',
            'booking_date' => '2026-11-02', 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'confirmed', 'payment_status' => 'unpaid',
            'service_price_snapshot' => 100000, 'finance_managed' => true, 'finance_revision' => 1, 'service_mode' => 'home',
            'visit_address' => 'ALAMAT RAHASIA', 'notes' => 'CATATAN KESEHATAN RAHASIA']);
    }

    private function source(string $text, array $overrides = [], bool $authorized = true): Message
    {
        $event = array_replace_recursive(['session' => 'main', 'payload' => ['id' => (string) Str::uuid(), 'from' => '628123450001@c.us',
            'body' => $text, 'timestamp' => now()->timestamp]], $overrides);
        if ($authorized) {
            $event['_gayatri_gateway'] = ['provider' => 'baileys', 'session' => 'main', 'accepted_at' => now()->timestamp];
        }
        $source = Message::create(['conversation_id' => $this->conversation->id, 'customer_id' => $this->conversation->customer_id,
            'direction' => 'incoming', 'sender_type' => 'customer', 'message_type' => 'text', 'content' => $text,
            'wa_message_id' => $event['payload']['id'], 'sent_at' => Carbon::createFromTimestamp($event['payload']['timestamp'])->timezone(config('app.timezone')), 'payload' => $event['payload']]);
        app(WhatsAppSenderIdentity::class)->observe($event, $this->conversation->whatsappSession, $source);

        return $source->fresh();
    }

    private function answer(string $text, array $overrides = [], bool $authorized = true): array
    {
        return app(AiService::class)->generateReply($this->conversation->fresh(), $this->source($text, $overrides, $authorized));
    }

    private function identity(): array
    {
        return ['customer_id' => $this->conversation->customer_id, 'phone' => '628123450001', 'is_demo' => false];
    }

    private function rental(): RentalOrder
    {
        return RentalOrder::create(['code' => 'RENT-C-1', 'quote_id' => (string) Str::uuid(), 'request_session_hash' => hash('sha256', 'rental'),
            'customer_id' => $this->conversation->customer_id, 'customer_snapshot' => ['whatsapp_number' => '08123450001'], 'status' => 'rented',
            'start_date' => '2026-11-01', 'end_date' => '2026-11-07', 'period' => 'weekly', 'period_count' => 1,
            'fulfilment' => 'self', 'rental_subtotal' => 70000, 'deposit_total' => 10000, 'pricing_snapshot' => []]);
    }

    private function laundry(): LaundryOrder
    {
        return LaundryOrder::create(['code' => 'LND-C-1', 'entry_key' => (string) Str::uuid(), 'input_hash' => hash('sha256', 'laundry'),
            'customer_id' => $this->conversation->customer_id, 'customer_snapshot' => ['whatsapp_number' => '628123450001'], 'outlet_snapshot' => [],
            'received_at' => now(), 'estimated_ready_at' => now()->addDay(), 'intake_mode' => 'walkin', 'status' => 'approved',
            'workflow_status' => 'washing', 'estimated_total' => 20000, 'actual_total' => 24000]);
    }

    public function test_owner_can_read_booking_without_sensitive_fields_or_business_mutations(): void
    {
        $source = $this->source('cek status BK-C-1 08123450001');
        $this->assertNotEmpty(data_get($source->payload, '_gayatri_sender'), json_encode([$source->payload, DB::table('whatsapp_identities')->get()]));
        $this->assertNotNull(app(WhatsAppSenderIdentity::class)->resolve($this->conversation, $source), json_encode([$source->payload, DB::table('whatsapp_identities')->get(), $this->conversation->only('id', 'customer_id', 'whatsapp_session_id'), CustomerPhone::matching('628123450001')->get(['id', 'status'])]));
        $tables = ['customers', 'bookings', 'booking_payments', 'rental_orders', 'laundry_orders', 'reminders'];
        $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->toJson()])->all();
        $result = app(AiService::class)->generateReply($this->conversation, $source);
        $this->assertSame('lookup_verified', $result['routing']['reason_code']);
        foreach (['BK-C-1', 'Dikonfirmasi', 'Rp 100.000', 'Kunjungan rumah'] as $text) {
            $this->assertStringContainsString($text, $result['reply']);
        }
        foreach (['ALAMAT RAHASIA', 'KESEHATAN', '08123450001', '628123450001'] as $text) {
            $this->assertStringNotContainsString($text, $result['reply']);
        }
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->toJson());
        }
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertNull(app(AiReplyEvidence::class)->problem($result['evidence'], $this->conversation->id));
        $log = AiLog::latest('id')->first();
        $this->assertStringNotContainsString('08123450001', $log->toJson());
        $this->assertArrayNotHasKey('lookup', $log->meta['evidence']);
    }

    public function test_phone_typed_by_customer_is_never_authentication(): void
    {
        $result = $this->answer('cek status BK-C-1 08123450001', [], false);
        $this->assertSame('lookup_identity_unverified', $result['fallback_reason']);
        $this->assertStringNotContainsString('Dikonfirmasi', $result['reply']);
        $this->assertDatabaseCount('ai_lookup_attempts', 0);
    }

    public function test_wrong_phone_wrong_code_and_other_owners_code_have_identical_denial(): void
    {
        $wrongPhone = $this->answer('cek status BK-C-1 08123450999');
        $wrongCode = $this->answer('cek status BK-C-404 08123450001');
        $other = Customer::create(['name' => 'Lain', 'whatsapp_number' => '628123450099', 'status' => 'active']);
        $this->booking->update(['customer_id' => $other->id]);
        $cross = $this->answer('cek status BK-C-1 08123450001');
        foreach ([$wrongPhone, $wrongCode, $cross] as $r) {
            $this->assertSame(AiTransactionLookup::DENIED, $r['reply']);
        }
        $this->assertDatabaseCount('ai_lookup_attempts', 3);
    }

    public static function invalidOwners(): array
    {
        return [['duplicate'], ['inactive'], ['blacklisted'], ['changed_phone'], ['snapshot_mismatch'], ['old_manual']];
    }

    #[DataProvider('invalidOwners')]
    public function test_changed_ambiguous_blocked_or_unreviewed_contacts_fail_closed(string $case): void
    {
        if ($case === 'duplicate') {
            Customer::create(['name' => 'Duplikat', 'whatsapp_number' => '08123450001', 'status' => 'active']);
        }
        if (in_array($case, ['inactive', 'blacklisted'], true)) {
            $this->conversation->customer->update(['status' => $case]);
        }
        if ($case === 'changed_phone') {
            $this->conversation->customer->update(['whatsapp_number' => '628123450099']);
        }
        if ($case === 'snapshot_mismatch') {
            $this->booking->forceFill(['contact_snapshot' => ['customer_id' => $this->conversation->customer_id, 'whatsapp_number' => '628123450099']])->save();
        }
        if ($case === 'old_manual') {
            $this->booking->forceFill(['contact_snapshot' => null])->save();
        }
        $result = $this->answer('cek status BK-C-1 08123450001');
        $this->assertNotSame('lookup_verified', $result['routing']['reason_code']);
        $this->assertStringNotContainsString('Dikonfirmasi', $result['reply']);
    }

    public function test_existing_crm_08_format_matches_gateway_62_sender(): void
    {
        $this->conversation->customer->update(['whatsapp_number' => '08123450001']);
        $this->assertSame('lookup_verified', $this->answer('BK-C-1 +62 812 3450 001')['routing']['reason_code']);
    }

    public function test_lactation_booking_is_separate_and_historical_paid_is_not_new_cash(): void
    {
        $this->booking->service->update(['service_group' => 'lactation', 'name' => 'Konsultasi ASI']);
        $this->booking->update(['finance_managed' => false, 'payment_status' => 'paid', 'status' => 'completed']);
        $result = $this->answer('cek status BK-C-1 08123450001');
        $this->assertSame('lactation', $result['routing']['domain']);
        $this->assertStringContainsString('Lunas (catatan historis', $result['reply']);
        $this->assertDatabaseCount('booking_payments', 0);
    }

    public function test_multi_turn_state_is_encrypted_and_cancelled_or_expired_state_does_not_lookup(): void
    {
        $source = $this->source('cek status');
        $result = app(AiService::class)->generateReply($this->conversation, $source);
        $this->assertSame('lookup_need_domain', $result['routing']['reason_code']);
        app(AiConversationContext::class)->commit($this->conversation, $source, $result['next_context']);
        foreach (['1', 'BK-C-1'] as $message) {
            $source = $this->source($message);
            $result = app(AiService::class)->generateReply($this->conversation, $source);
            app(AiConversationContext::class)->commit($this->conversation, $source, $result['next_context']);
        }
        $row = DB::table('ai_conversation_contexts')->first();
        $this->assertStringNotContainsString('BK-C-1', $row->lookup_payload);
        $this->assertSame('BK-C-1', json_decode(Crypt::decryptString($row->lookup_payload), true)['code']);
        $this->assertSame('lookup_verified', $this->answer('08123450001')['routing']['reason_code']);
        $cancel = $this->answer('menu');
        $this->assertArrayNotHasKey('lookup', $cancel['next_context']);
        $this->travel(11)->minutes();
        $this->assertArrayNotHasKey('lookup', app(AiConversationContext::class)->read($this->conversation));
        $this->assertNotSame('lookup_verified', $this->answer('08123450001')['routing']['reason_code']);
    }

    public function test_verification_limit_is_persistent_and_expires_after_ten_minutes(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame('lookup_denied', $this->answer('BK-MISSING 08123450001')['routing']['reason_code']);
        }
        app()->forgetInstance(AiLookupLimiter::class);
        $this->assertSame('lookup_limited', $this->answer('BK-C-1 08123450001')['routing']['reason_code']);
        $this->travel(11)->minutes();
        $this->assertSame('lookup_verified', $this->answer('BK-C-1 08123450001')['routing']['reason_code']);
        $audit = DB::table('ai_lookup_attempts')->get()->toJson();
        $this->assertStringNotContainsString('BK-', $audit);
        $this->assertStringNotContainsString('628123450001', $audit);
    }

    public static function staleEvidence(): array
    {
        return [['status'], ['snapshot'], ['customer'], ['identity'], ['provider'], ['expiry']];
    }

    #[DataProvider('staleEvidence')]
    public function test_private_reply_evidence_is_rechecked_before_send_and_retry(string $change): void
    {
        $result = $this->answer('BK-C-1 08123450001');
        $this->assertSame('lookup_verified', $result['routing']['reason_code']);
        if ($change === 'status') {
            $this->booking->update(['status' => 'cancelled']);
        }
        if ($change === 'snapshot') {
            $this->booking->forceFill(['contact_snapshot' => null])->save();
        }
        if ($change === 'customer') {
            $this->conversation->customer->update(['status' => 'inactive']);
        }
        if ($change === 'identity') {
            DB::table('whatsapp_identities')->update(['revoked_at' => now(), 'revision' => 2]);
        }
        if ($change === 'provider') {
            config(['whatsapp.provider' => 'waha']);
        }
        if ($change === 'expiry') {
            $this->travel(6)->minutes();
        }
        $this->assertNotNull(app(AiReplyEvidence::class)->problem($result['evidence'], $this->conversation->id));
        Http::assertNothingSent();
    }

    public function test_unknown_lid_is_not_a_phone_and_verified_alias_conflicts_revoke_access(): void
    {
        $unknown = $this->answer('BK-C-1 08123450001', ['payload' => ['from' => '628123450001@lid']]);
        $this->assertSame('lookup_identity_unverified', $unknown['routing']['reason_code']);
        $meta = ['version' => 1, 'originalJid' => '1234567@lid', 'phoneJid' => '628123450001@s.whatsapp.net', 'lid' => '1234567@lid', 'source' => 'message_key_alt'];
        $source = $this->source('BK-C-1 08123450001', ['payload' => ['senderIdentity' => $meta]]);
        $this->assertNotNull(app(WhatsAppSenderIdentity::class)->resolve($this->conversation, $source));
        $this->assertDatabaseHas('whatsapp_identities', ['jid' => '1234567@lid', 'phone' => '628123450001', 'status' => 'verified']);
        $meta['phoneJid'] = '628123450099@s.whatsapp.net';
        $this->source('hello', ['payload' => ['from' => '628123450099@c.us', 'senderIdentity' => $meta]]);
        $this->assertDatabaseHas('whatsapp_identities', ['jid' => '1234567@lid', 'status' => 'conflict']);
        $this->assertNull(app(WhatsAppSenderIdentity::class)->resolve($this->conversation, $source));
    }

    public function test_cached_lid_mapping_can_identify_existing_correct_owner_only(): void
    {
        $meta = ['version' => 1, 'originalJid' => '77777@lid', 'phoneJid' => '628123450001@s.whatsapp.net', 'lid' => '77777@lid', 'source' => 'baileys_cache'];
        $this->source('hello', ['payload' => ['senderIdentity' => $meta]]);
        $result = $this->answer('BK-C-1 08123450001', ['payload' => ['from' => '77777@lid', 'senderIdentity' => ['version' => 1, 'source' => 'unmapped_lid']]]);
        $this->assertSame('lookup_verified', $result['routing']['reason_code']);
        DB::table('whatsapp_identities')->where('jid', '77777@lid')->update(['verified_at' => now()->subDays(31)]);
        $this->assertSame('lookup_identity_unverified', $this->answer('BK-C-1 08123450001', ['payload' => ['from' => '77777@lid']])['routing']['reason_code']);
    }

    public function test_source_signature_cannot_be_copied_and_stale_or_cross_session_source_is_denied(): void
    {
        $source = $this->source('BK-C-1 08123450001');
        $copied = $this->source('BK-C-1 08123450001', [], false);
        $copied->update(['payload' => $source->payload]);
        $this->assertNull(app(WhatsAppSenderIdentity::class)->resolve($this->conversation, $copied));
        $source->update(['sent_at' => now()->subMinutes(16)]);
        $this->assertNull(app(WhatsAppSenderIdentity::class)->resolve($this->conversation, $source->fresh()));
        $source = $this->source('BK-C-1 08123450001');
        $other = WhatsAppSession::create(['session_name' => 'other']);
        $this->conversation->update(['whatsapp_session_id' => $other->id]);
        $this->assertNull(app(WhatsAppSenderIdentity::class)->resolve($this->conversation, $source));
    }

    public function test_authenticated_webhook_stamps_identity_and_rejects_forged_or_group_events(): void
    {
        $event = ['event' => 'message', 'session' => 'main', 'payload' => ['id' => 'hook-c', 'from' => '628123450001@c.us', 'body' => 'BK-C-1 08123450001', 'timestamp' => now()->timestamp]];
        $this->postJson('/webhooks/whatsapp/messages', $event)->assertUnauthorized();
        $this->withHeader('X-Webhook-Secret', 'lookup-test-secret')->postJson('/webhooks/whatsapp/messages', $event)->assertSuccessful();
        Queue::assertPushed(ProcessIncomingWhatsAppMessageJob::class);
        $job = Queue::pushed(ProcessIncomingWhatsAppMessageJob::class)->first();
        app()->call([$job, 'handle']);
        $source = Message::where('wa_message_id', 'hook-c')->firstOrFail();
        $this->assertNotNull(app(WhatsAppSenderIdentity::class)->resolve($this->conversation, $source));
        $this->assertSame('lookup_verified', app(AiService::class)->generateReply($this->conversation, $source)['routing']['reason_code']);
        Queue::fake();
        foreach (['123@g.us', 'status@broadcast'] as $from) {
            $event['payload']['from'] = $from;
            $this->postJson('/webhooks/whatsapp/messages', $event)->assertSuccessful();
        }
        Queue::assertNotPushed(ProcessIncomingWhatsAppMessageJob::class);
        Http::assertNothingSent();
    }

    public function test_rental_reads_payment_period_and_rejects_unverified_notification_contact(): void
    {
        $order = $this->rental();
        $order->payments()->create(['kind' => 'payment', 'amount' => 30000, 'method' => 'cash']);
        $result = $this->answer('RENT-C-1 08123450001');
        $this->assertSame('lookup_verified', $result['routing']['reason_code']);
        foreach (['01-11-2026', '07-11-2026', 'Rp 50.000', 'Sebagian / DP'] as $text) {
            $this->assertStringContainsString($text, $result['reply']);
        }
        $order->update(['notification_phone' => '628123450001', 'notification_verified_at' => null]);
        $this->assertSame(AiTransactionLookup::DENIED, $this->answer('RENT-C-1 08123450001')['reply']);
    }

    public function test_laundry_reads_workflow_and_voided_payments_and_pending_weighing(): void
    {
        $order = $this->laundry();
        $order->payments()->create(['entry_key' => (string) Str::uuid(), 'input_hash' => str_repeat('a', 64), 'amount' => 10000, 'method' => 'cash', 'paid_at' => now(), 'payer_name' => 'Uji', 'receipt_number' => 'LC-1']);
        $payment = $order->payments()->create(['entry_key' => (string) Str::uuid(), 'input_hash' => str_repeat('b', 64), 'amount' => 14000, 'method' => 'cash', 'paid_at' => now(), 'payer_name' => 'Uji', 'receipt_number' => 'LC-2', 'voided_at' => now()]);
        $result = $this->answer('LND-C-1 08123450001');
        $this->assertSame('lookup_verified', $result['routing']['reason_code']);
        $this->assertStringContainsString('Dicuci', $result['reply']);
        $this->assertStringContainsString('Rp 14.000', $result['reply']);
        $order->update(['status' => 'awaiting_approval']);
        $result = $this->answer('LND-C-1 08123450001');
        $this->assertStringContainsString('menunggu hasil timbang', $result['reply']);
        $this->assertStringNotContainsString('Rp 14.000', $result['reply']);
    }

    public function test_laundry_public_uuid_is_scoped_to_phone_and_customer_and_follows_linked_order(): void
    {
        $uuid = 'c0000001-0000-4000-8000-000000000001';
        $request = LaundryPublicRequest::create(['public_id' => $uuid, 'entry_key' => (string) Str::uuid(), 'input_hash' => str_repeat('c', 64),
            'name' => 'Pemesan', 'whatsapp_number' => '628123450001', 'fulfilment' => 'dropoff', 'items' => [], 'estimate' => [],
            'outlet_snapshot' => [], 'status' => 'pending', 'portal_expires_at' => now()->addDays(7)]);
        $result = $this->answer($uuid.' 08123450001');
        $this->assertSame('lookup_verified', $result['routing']['reason_code']);
        $this->assertStringContainsString('Belum menjadi order', $result['reply']);
        $order = $this->laundry();
        $request->update(['laundry_order_id' => $order->id, 'customer_id' => $this->conversation->customer_id]);
        $this->assertStringContainsString('Dicuci', $this->answer($uuid.' 08123450001')['reply']);
        $request->update(['whatsapp_number' => '628123450099']);
        $this->assertSame(AiTransactionLookup::DENIED, $this->answer($uuid.' 08123450001')['reply']);
    }

    public function test_demo_knowledge_is_idempotent_and_excluded_from_live_retrieval(): void
    {
        $this->artisan('ai:seed-knowledge-demo')->assertSuccessful();
        $this->artisan('ai:seed-knowledge-demo')->assertSuccessful();
        $this->assertDatabaseCount('knowledge_bases', 30);
        $this->assertCount(0, app(KnowledgeRetrievalService::class)->retrieve('persiapan pijat', 5, 'spa'));
        $this->assertNotEmpty(app(KnowledgeRetrievalService::class)->retrieve('persiapan pijat', 5, 'spa', true));
        foreach (['spa' => 'persiapan pijat', 'lactation' => 'persiapan laktasi', 'rental' => 'kelengkapan rental', 'laundry' => 'pewangi laundry', 'general' => 'privasi'] as $domain => $question) {
            $records = app(KnowledgeRetrievalService::class)->retrieve($question, 2, $domain, true);
            $this->assertNotEmpty($records);
            foreach ($records as $record) {
                $this->assertTrue($record->domains->pluck('domain')->intersect([$domain, 'general'])->isNotEmpty());
            }
        }
    }

    public function test_simulator_demo_allowlist_isolated_from_live_customers_and_cross_owner_data(): void
    {
        $this->artisan('ai:seed-lookup-demo')->assertSuccessful();
        $this->artisan('ai:seed-lookup-demo')->assertSuccessful();
        $this->assertDatabaseCount('ai_demo_profiles', 2);
        $profile = DB::table('ai_demo_profiles')->first();
        $input = ['include_demo' => true, 'demo_profile_id' => $profile->id];
        $result = app(AiService::class)->simulate('BK-DEMO-C-SPA-A 080000000901', null, $input);
        $this->assertSame('lookup_verified', $result['routing']['reason_code']);
        $this->assertStringContainsString('[DEMO', $result['reply']);
        $this->assertNotNull(app(AiReplyEvidence::class)->problem($result['evidence'], $this->conversation->id));
        $this->assertSame(AiTransactionLookup::DENIED, app(AiService::class)->simulate('BK-DEMO-C-SPA-B 080000000901', null, $input)['reply']);
        $this->assertSame(AiTransactionLookup::DENIED, app(AiService::class)->simulate('BK-C-1 080000000901', null, $input)['reply']);
        $this->assertSame('lookup_identity_unverified', app(AiService::class)->simulate('BK-C-1 08123450001', null, ['demo_profile_id' => 999999, 'include_demo' => true])['routing']['reason_code']);
        $this->assertSame('lookup_identity_unverified', app(AiService::class)->simulate('BK-C-1 08123450001', null, ['demo_profile_id' => $profile->id])['routing']['reason_code']);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_demo_seed_and_simulation_cannot_be_enabled_in_production(): void
    {
        $this->app->instance('env', 'production');
        $this->artisan('ai:seed-knowledge-demo')->assertFailed();
        $this->artisan('ai:seed-lookup-demo')->assertFailed();
        $this->assertNull(app(AiTransactionLookup::class)->identity(['mode' => 'simulator', 'include_demo' => true, 'demo_profile_id' => 1]));
    }

    public function test_retention_removes_expired_state_not_business_data(): void
    {
        $source = $this->source('BK-C-1');
        $result = app(AiService::class)->generateReply($this->conversation, $source);
        app(AiConversationContext::class)->commit($this->conversation, $source, $result['next_context']);
        DB::table('ai_lookup_attempts')->insert(['scope_hash' => str_repeat('a', 64), 'result' => 'denied', 'created_at' => now()->subDays(31)]);
        $this->travel(11)->minutes();
        $this->artisan('ai:prune-lookups')->assertSuccessful();
        $this->assertNull(DB::table('ai_conversation_contexts')->value('lookup_payload'));
        $this->assertDatabaseCount('ai_lookup_attempts', 0);
        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_booking_journal_payment_refund_and_void_are_read_without_mutation(): void
    {
        foreach ([['payment', 6000000, false], ['refund', 1000000, false], ['payment', 5000000, true]] as [$kind, $amount, $voided]) {
            $this->booking->payments()->create(['entry_key' => (string) Str::uuid(), 'input_hash' => str_repeat('e', 64),
                'kind' => $kind, 'amount_cents' => $amount, 'method' => 'cash', 'paid_at' => now(), 'payer_name' => 'Uji',
                'receipt_number' => (string) Str::uuid(), 'snapshot' => [], 'voided_at' => $voided ? now() : null]);
        }
        $this->booking->update(['legacy_credit_cents' => 1000000]);
        $result = $this->answer('BK-C-1 08123450001');
        $this->assertStringContainsString('Rp 40.000', $result['reply']);
        $this->assertStringContainsString('Sebagian / DP', $result['reply']);
        $this->booking->update(['status' => 'cancelled']);
        $result = $this->answer('BK-C-1 08123450001');
        $this->assertStringContainsString('Dibatalkan', $result['reply']);
        $this->assertStringContainsString('refund tercatat Rp 10.000', $result['reply']);
        $this->assertStringNotContainsString('Sisa tagihan', $result['reply']);
        $this->assertDatabaseCount('booking_payments', 3);
    }

    public function test_manual_old_booking_requires_explicit_authorized_admin_contact_review(): void
    {
        $this->booking->forceFill(['contact_snapshot' => null])->save();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)->get(route('admin.bookings.finance', $this->booking))->assertOk()->assertSee('Verifikasi kontak untuk cek status WhatsApp');
        $this->post(route('admin.bookings.verify-contact', $this->booking), ['reference' => 'Pelanggan diperiksa melalui telepon', 'confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull($this->booking->fresh()->contact_snapshot);
        $this->assertSame('lookup_verified', $this->answer('BK-C-1 08123450001')['routing']['reason_code']);
    }

    public function test_full_generate_and_outbox_pipeline_holds_stale_private_reply_and_retry(): void
    {
        $source = $this->source('BK-C-1 08123450001');
        app()->call([new GenerateAiReplyJob($this->conversation->id, $source->id), 'handle']);
        Queue::assertPushed(SendWhatsAppMessageJob::class, 1);
        $this->booking->update(['status' => 'completed']);
        $job = Queue::pushed(SendWhatsAppMessageJob::class)->first();
        app()->call([$job, 'handle']);
        $outgoing = Message::where('direction', 'outgoing')->firstOrFail();
        $this->assertSame('skipped', $outgoing->delivery_status);
        $this->assertSame('lookup_status_changed', $outgoing->failure_code);
        $this->assertStringContainsString('lookup_status_changed', app(OutboundMessages::class)->retryProblem($outgoing));
        Http::assertNothingSent();
    }

    public function test_simulator_http_multi_turn_only_uses_allowlisted_demo_profile_and_encrypts_state(): void
    {
        $this->artisan('ai:seed-lookup-demo')->assertSuccessful();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $profile = DB::table('ai_demo_profiles')->first();
        $this->actingAs($admin);
        $fields = ['include_demo' => 1, 'demo_profile_id' => $profile->id];
        $this->post(route('admin.ai.simulator.test'), $fields + ['message' => 'BK-DEMO-C-SPA-A'])->assertOk()->assertSee('lookup_need_phone');
        $context = session('ai.simulator_context');
        $this->assertArrayNotHasKey('lookup', $context);
        $this->assertStringNotContainsString('BK-DEMO-C-SPA-A', json_encode($context));
        $this->post(route('admin.ai.simulator.test'), $fields + ['message' => '080000000901'])->assertOk()->assertSee('lookup_verified')->assertSee('catatan historis');
        $this->post(route('admin.ai.simulator.test'), $fields + ['message' => 'BK-DEMO-C-SPA-A'])->assertOk()->assertSee('lookup_need_phone');
        $second = DB::table('ai_demo_profiles')->where('id', '!=', $profile->id)->first();
        $this->post(route('admin.ai.simulator.test'), ['include_demo' => 1, 'demo_profile_id' => $second->id, 'message' => '080000000901'])->assertOk()->assertDontSee('lookup_verified');
        $this->post(route('admin.ai.simulator.test'), ['include_demo' => 1, 'demo_profile_id' => 99999, 'message' => 'BK-C-1'])->assertSessionHasErrors('demo_profile_id');
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_lookup_is_unavailable_if_rate_store_fails_and_does_not_read_transactions(): void
    {
        $this->mock(AiLookupLimiter::class, function ($mock) {
            $mock->shouldReceive('consume')->once()->andThrow(new \RuntimeException('storage failure'));
        });
        DB::enableQueryLog();
        $result = $this->answer('BK-C-1 08123450001');
        $this->assertSame('lookup_unavailable', $result['fallback_reason']);
        $this->assertStringNotContainsString('Dikonfirmasi', $result['reply']);
        $this->assertFalse(collect(DB::getQueryLog())->contains(fn ($q) => str_contains($q['query'], 'from "bookings"')));
    }

    public function test_new_message_takeover_and_policy_change_hold_private_status_reply(): void
    {
        $source = $this->source('BK-C-1 08123450001');
        $result = app(AiService::class)->generateReply($this->conversation, $source);
        $policy = app(AiInteractionPolicy::class);
        $context = $policy->replyContext($this->conversation->fresh(), $source, $result['reply'], $policy->snapshot(), false, $result['evidence']);
        $this->assertNull($policy->sendProblem($this->conversation->fresh(), $result['reply'], $context));
        $this->source('menu');
        $this->assertSame('stale_context', $policy->sendProblem($this->conversation->fresh(), $result['reply'], $context));
        $this->conversation->update(['ai_enabled' => false, 'status' => 'human_handled']);
        $this->assertSame('human_takeover', $policy->sendProblem($this->conversation->fresh(), $result['reply'], $context));
        $this->conversation->update(['ai_enabled' => true, 'status' => 'open']);
        DB::table('ai_interaction_policies')->where('id', 1)->increment('revision');
        $this->assertSame('stale_policy', $policy->sendProblem($this->conversation->fresh(), $result['reply'], $context));
    }

    public function test_status_domain_choice_is_not_consumed_as_a_feedback_rating(): void
    {
        Feedback::create(['customer_id' => $this->conversation->customer_id, 'booking_id' => $this->booking->id, 'status' => 'requested']);
        $source = $this->source('cek status');
        $result = app(AiService::class)->generateReply($this->conversation, $source);
        app(AiConversationContext::class)->commit($this->conversation, $source, $result['next_context']);
        $choice = $this->source('1');
        $this->assertNull(app(FeedbackService::class)->captureIncoming($choice));
        $this->assertDatabaseHas('feedback', ['status' => 'requested', 'rating' => null]);
        $this->assertSame('lookup_need_code', app(AiService::class)->generateReply($this->conversation, $choice)['routing']['reason_code']);
    }
}
