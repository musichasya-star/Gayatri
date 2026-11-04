<?php

namespace Tests\Feature;

use App\Jobs\SendRentalNotificationJob;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\RentalNotification;
use App\Models\RentalOrder;
use App\Models\RentalProduct;
use App\Models\RentalUnit;
use App\Models\User;
use App\Services\CRM\RentalCustomerRequestService;
use App\Services\CRM\RentalMessagingService;
use App\Services\CRM\RentalNotificationService;
use App\Services\CRM\RentalPaymentProofService;
use App\Services\CRM\RentalPaymentTerms;
use App\Services\CRM\RentalPortalService;
use App\Services\CRM\RentalService;
use App\Services\CRM\RentalSettings;
use App\Services\WhatsApp\WhatsAppGateway;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class RentalPaymentNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private RentalProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-28 10:00:00', 'Asia/Jakarta'));
        Cache::flush();
        Queue::fake();
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($this->admin);
        $this->customer = Customer::create(['name' => 'Nama CRM Internal', 'whatsapp_number' => '628111222333', 'status' => 'lead']);
        $this->product = RentalProduct::create(['sku' => 'MSG-ST', 'name' => 'Stroller Pesan', 'category' => 'Stroller', 'daily_rate' => 25000, 'deposit' => 100000, 'is_active' => true]);
    }

    private function request(): Request
    {
        $request = Request::create('/local-test', 'POST');
        $request->setLaravelSession($this->app['session.store']);

        return $request;
    }

    private function order(array $changes = []): RentalOrder
    {
        $terms = app(RentalPaymentTerms::class)->snapshot(array_replace(app(RentalSettings::class)->get(), ['dp_type' => 'percent', 'dp_value' => 50]), 75000);
        $order = RentalOrder::create(array_replace(['code' => 'MSG-'.Str::upper(Str::random(8)), 'quote_id' => (string) Str::uuid(), 'request_session_hash' => str_repeat('d', 64), 'customer_id' => $this->customer->id, 'customer_snapshot' => ['name' => 'Nama Penyewa Pesanan', 'whatsapp_number' => '628111222333'],
            'source' => 'admin', 'status' => 'reserved', 'start_date' => '2026-10-30', 'end_date' => '2026-11-01', 'period' => 'day', 'period_count' => 3, 'fulfilment' => 'self', 'rental_subtotal' => 75000, 'deposit_total' => 100000, 'delivery_fee' => 0, 'collection_fee' => 0, 'pricing_snapshot' => ['route' => null],
            'payment_terms' => $terms, 'dp_due_at' => '2026-10-29 10:00:00', 'balance_due_at' => '2026-10-30 23:59:59'], $changes));
        $unit = RentalUnit::create(['rental_product_id' => $this->product->id, 'code' => 'MSG-'.Str::upper(Str::random(8)), 'status' => 'ready']);
        $order->items()->create(['rental_unit_id' => $unit->id, 'product_name' => $this->product->name, 'unit_code' => $unit->code, 'rental_price' => 75000, 'deposit' => 100000, 'rental_end_date' => $order->end_date, 'reserved_until' => $order->end_date->copy()->addDays(2)]);

        return $order;
    }

    private function configure(array $changes = []): RentalNotificationService
    {
        $service = app(RentalNotificationService::class);
        $service->save(array_replace($service->settings(), ['enabled' => true, 'public_base_url' => 'https://gayatri-kediri.web.id', 'events' => array_keys(RentalNotificationService::EVENTS)], $changes));

        return $service;
    }

    private function verify(RentalOrder $order, string $phone = '08111222333'): void
    {
        $this->post(route('admin.rentals.messaging.recipient', $order), ['notification_phone' => $phone, 'recipient_confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $order->refresh();
    }

    private function pay(RentalOrder $order, int $amount): void
    {
        app(RentalService::class)->payment($order, ['entry_key' => (string) Str::uuid(), 'kind' => 'payment', 'amount' => $amount, 'method' => 'transfer'], $this->admin, $this->request());
        $order->refresh();
    }

    public function test_contact_is_order_scoped_confirmed_and_portal_token_is_encrypted_and_hidden(): void
    {
        $order = $this->order();
        $route = route('admin.rentals.messaging.recipient', $order);
        $this->post($route, ['notification_phone' => '08111222333'])->assertSessionHasErrors('recipient_confirmed');
        $this->post($route, ['notification_phone' => '6200000001', 'recipient_confirmed' => 1])->assertSessionHasErrors('messaging');
        $this->verify($order);
        $token = $order->portal_token_secret;
        $this->assertSame(64, strlen($token));
        $this->assertSame(hash('sha256', $token), $order->portal_token_hash);
        $this->assertFalse(str_contains(DB::table('rental_orders')->where('id', $order->id)->value('portal_token_secret'), $token));
        $this->assertArrayNotHasKey('portal_token_secret', $order->toArray());
        $this->assertSame('628111222333', $order->notification_phone);
        $this->customer->update(['whatsapp_number' => '628999888777']);
        $this->assertSame('628111222333@c.us', app(RentalNotificationService::class)->recipient($order->fresh()));
        $this->assertSame('628999888777', $this->customer->fresh()->whatsapp_number);
        $this->verify($order);
        $this->assertSame($token, $order->portal_token_secret);
    }

    public function test_portal_preview_is_redacted_does_not_create_tokens_and_uses_submitted_order_name(): void
    {
        $order = $this->order();
        $this->configure();
        $route = route('admin.rentals.notifications.preview');
        $this->postJson($route, ['order_id' => $order->id, 'kind' => 'portal_shared'])->assertOk()->assertJsonPath('preview', fn ($text) => str_contains($text, 'Nama Penyewa Pesanan') && ! str_contains($text, 'Nama CRM Internal'));
        $this->assertNull($order->fresh()->portal_token_hash);
        $this->verify($order);
        $token = $order->portal_token_secret;
        $response = $this->postJson($route, ['order_id' => $order->id, 'kind' => 'portal_shared'])->assertOk();
        $this->assertStringContainsString('[TAUTAN-PRIBADI]', $response->json('preview'));
        $this->assertFalse(str_contains($response->json('preview'), $token));
        $this->assertSame($token, $order->fresh()->portal_token_secret);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_portal_queue_requires_enabled_settings_verified_contact_public_url_and_recoverable_token(): void
    {
        $order = $this->order();
        $route = route('admin.rentals.messaging.portal', $order);
        $this->post($route)->assertSessionHasErrors('messaging');
        $service = $this->configure();
        $this->post($route)->assertSessionHasErrors('messaging');
        $this->verify($order);
        $service->save(array_replace($service->settings(), ['public_base_url' => 'http://127.0.0.1:8000']));
        $this->post($route)->assertSessionHasErrors('messaging');
        $this->configure();
        $order->update(['portal_token_secret' => null]);
        $this->post($route)->assertSessionHasErrors('messaging');
        app(RentalPortalService::class)->issue($order, $this->request(), $this->admin);
        $this->post($route)->assertSessionHasNoErrors();
        $this->post($route)->assertSessionHasNoErrors();
        $this->assertSame(1, RentalNotification::where('kind', 'portal_shared')->count());
        Queue::assertPushed(SendRentalNotificationJob::class, 1);
    }

    public function test_private_link_reaches_only_the_gateway_and_is_redacted_in_history_and_payload(): void
    {
        $order = $this->order();
        $service = $this->configure();
        $this->verify($order);
        $row = app(RentalMessagingService::class)->queuePortal($order);
        $token = $order->portal_token_secret;
        $gateway = $this->mock(WhatsAppGateway::class);
        $gateway->shouldReceive('sendText')->once()->withArgs(fn ($session, $chat, $text) => $chat === '628111222333@c.us' && str_contains($text, '/rental/portal/access/'.$token))
            ->andReturn(['id' => [' _unused' => 'x', '_serialized' => 'private-message-id'], 'body' => 'https://gayatri-kediri.web.id/rental/portal/access/'.$token]);
        $job = new SendRentalNotificationJob($row->id);
        $job->handle($gateway, $service);
        $job->handle($gateway, $service);
        $this->assertSame('sent', $row->refresh()->status);
        $this->assertSame(1, $row->attempts);
        $this->assertFalse(str_contains($row->content, $token));
        $message = Message::where('wa_message_id', 'private-message-id')->firstOrFail();
        $this->assertFalse(str_contains($message->content, $token));
        $this->assertFalse(str_contains(json_encode($message->payload), $token));
        $this->assertStringContainsString('[TAUTAN-PRIBADI]', $message->content);
    }

    public function test_inbox_model_also_masks_customer_forwarded_portal_tokens(): void
    {
        $conversation = Conversation::create(['wa_chat_id' => '628111222333@c.us', 'customer_id' => $this->customer->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true]);
        $url = 'https://gayatri-kediri.web.id/rental/portal/access/'.str_repeat('a', 64);
        $message = Message::create(['conversation_id' => $conversation->id, 'customer_id' => $this->customer->id, 'wa_message_id' => 'forwarded', 'direction' => 'incoming', 'sender_type' => 'customer', 'message_type' => 'text', 'content' => $url, 'payload' => ['nested' => ['body' => $url]]]);
        $this->assertFalse(str_contains($message->fresh()->content, str_repeat('a', 64)));
        $this->assertFalse(str_contains(json_encode($message->fresh()->payload), str_repeat('a', 64)));
        $this->assertFalse(str_contains(RentalMessagingService::redact(json_encode(['error' => $url])), str_repeat('a', 64)));
    }

    public function test_rotated_link_or_changed_contact_skips_old_queued_private_message(): void
    {
        $order = $this->order();
        $service = $this->configure();
        $this->verify($order);
        $row = app(RentalMessagingService::class)->queuePortal($order);
        $oldVersion = $order->portal_version;
        $this->verify($order, '08122334455');
        $this->assertGreaterThan($oldVersion, $order->portal_version);
        $gateway = $this->mock(WhatsAppGateway::class);
        $gateway->shouldNotReceive('sendText');
        (new SendRentalNotificationJob($row->id))->handle($gateway, $service);
        $this->assertSame('skipped', $row->fresh()->status);
        $new = app(RentalMessagingService::class)->queuePortal($order);
        app(RentalPortalService::class)->issue($order, $this->request(), $this->admin);
        (new SendRentalNotificationJob($new->id))->handle($gateway, $service);
        $this->assertSame('skipped', $new->fresh()->status);
    }

    public function test_unverified_payment_events_and_templates_containing_portal_links_are_not_sent(): void
    {
        $order = $this->order();
        $service = $this->configure();
        $this->pay($order, 10000);
        $this->assertSame('skipped', RentalNotification::where('kind', 'payment_received')->firstOrFail()->status);
        $settings = $service->settings();
        $settings['templates']['confirmed'] = 'Portal {tautan_portal}';
        $service->save($settings);
        $row = $service->enqueue($order, 'confirmed', 'check-private');
        $this->assertSame('skipped', $row->status);
        Queue::assertNothingPushed();
    }

    public function test_payment_scheduler_deduplicates_dp_then_balance_and_stops_when_settled(): void
    {
        $order = $this->order();
        $service = $this->configure(['events' => ['dp_reminder', 'balance_reminder']]);
        $this->verify($order);
        $this->assertSame(1, $service->dispatchDue());
        $this->assertSame(0, $service->dispatchDue());
        $dp = RentalNotification::where('kind', 'dp_reminder')->firstOrFail();
        $this->pay($order, 37500);
        $gateway = $this->mock(WhatsAppGateway::class);
        $gateway->shouldNotReceive('sendText');
        (new SendRentalNotificationJob($dp->id))->handle($gateway, $service);
        $this->assertSame('skipped', $dp->fresh()->status);
        $this->assertSame(0, $service->dispatchDue());
        $this->travel(1)->days();
        $this->assertSame(1, $service->dispatchDue());
        $balance = RentalNotification::where('kind', 'balance_reminder')->firstOrFail();
        $this->assertStringContainsString('Rp 137.500', $service->render($order->fresh(), 'balance_reminder'));
        $this->pay($order, 137500);
        (new SendRentalNotificationJob($balance->id))->handle($gateway, $service);
        $this->assertSame('skipped', $balance->fresh()->status);
        $this->travel(1)->days();
        $this->assertSame(0, $service->dispatchDue());
    }

    public function test_pending_proof_or_cancellation_and_cancelled_status_suppress_payment_reminders(): void
    {
        $order = $this->order();
        $service = $this->configure(['events' => ['dp_reminder', 'balance_reminder']]);
        $this->verify($order);
        $proof = $order->paymentProofs()->create(['entry_key' => (string) Str::uuid(), 'kind' => 'dp', 'amount' => 37500, 'payer_name' => 'Penyewa', 'payment_date' => '2026-10-28', 'file_path' => 'proof.jpg', 'file_mime' => 'image/jpeg', 'file_hash' => str_repeat('e', 64), 'file_size' => 10, 'status' => 'pending']);
        $this->assertSame(0, $service->dispatchDue());
        $proof->update(['status' => 'rejected']);
        $entry = $order->customerRequests()->create(['entry_key' => (string) Str::uuid(), 'kind' => 'cancellation', 'reason' => 'Pengajuan pembatalan uji', 'estimate' => ['fee' => 0, 'received' => 0, 'refund' => 0], 'status' => 'pending']);
        $this->assertSame(0, $service->dispatchDue());
        $entry->update(['status' => 'rejected']);
        $this->assertSame(1, $service->dispatchDue());
        $row = RentalNotification::where('kind', 'dp_reminder')->firstOrFail();
        $order->update(['status' => 'cancelled']);
        $gateway = $this->mock(WhatsAppGateway::class);
        $gateway->shouldNotReceive('sendText');
        (new SendRentalNotificationJob($row->id))->handle($gateway, $service);
        $this->assertSame('skipped', $row->fresh()->status);
    }

    public function test_payment_reminder_interval_maximum_deadline_and_send_time_are_respected(): void
    {
        $order = $this->order();
        $service = $this->configure(['events' => ['dp_reminder', 'balance_reminder'], 'send_time' => '11:00', 'payment_reminder_interval_days' => 2, 'payment_max_reminders' => 2]);
        $this->verify($order);
        $this->assertSame(0, $service->dispatchDue());
        $this->travel(2)->hours();
        $this->assertSame(1, $service->dispatchDue());
        RentalNotification::where('kind', 'dp_reminder')->update(['status' => 'sent', 'sent_at' => now()]);
        $this->travel(1)->days();
        $this->assertSame(0, $service->dispatchDue());
        $this->travel(1)->days();
        $this->assertSame(1, $service->dispatchDue());
        RentalNotification::where('kind', 'dp_reminder')->update(['status' => 'sent', 'sent_at' => now()]);
        $this->travel(2)->days();
        $this->assertSame(0, $service->dispatchDue());
        $this->assertSame(2, RentalNotification::where('kind', 'dp_reminder')->count());
    }

    public function test_proof_approval_queues_one_result_and_not_a_second_manual_payment_message(): void
    {
        $order = $this->order();
        $service = $this->configure();
        $this->verify($order);
        $proof = app(RentalPaymentProofService::class)->submit($order, ['entry_key' => (string) Str::uuid(), 'kind' => 'dp', 'amount' => 37500, 'payer_name' => 'Pembayar Uji', 'payment_date' => '2026-10-28'], UploadedFile::fake()->image('dp.jpg'));
        foreach (range(1, 2) as $i) {
            app(RentalPaymentProofService::class)->review($proof, 'approve', 'Dana uji sudah dicocokkan', $this->admin, $this->request());
        }
        $this->assertSame(1, RentalNotification::where('kind', 'proof_approved')->count());
        $this->assertSame(0, RentalNotification::where('kind', 'payment_received')->count());
        $row = RentalNotification::where('kind', 'proof_approved')->firstOrFail();
        $text = $service->render($order->fresh(), $row->kind, $row->context);
        $this->assertStringContainsString('Rp 37.500', $text);
        $this->assertStringContainsString('Dana uji sudah dicocokkan', $text);
        $this->assertTrue($service->current($row));
    }

    public function test_old_rejection_is_skipped_after_replacement_proof_and_request_approval_is_skipped_after_refund_settles(): void
    {
        $order = $this->order();
        $service = $this->configure();
        $this->verify($order);
        $proofs = app(RentalPaymentProofService::class);
        $data = ['entry_key' => (string) Str::uuid(), 'kind' => 'dp', 'amount' => 37500, 'payer_name' => 'Pembayar Uji', 'payment_date' => '2026-10-28'];
        $proof = $proofs->submit($order, $data, UploadedFile::fake()->image('first.jpg'));
        $proofs->review($proof, 'reject', 'Bukti uji belum cocok', $this->admin, $this->request());
        $rejection = RentalNotification::where('kind', 'proof_rejected')->firstOrFail();
        $this->assertTrue($service->current($rejection));
        $proofs->submit($order, array_replace($data, ['entry_key' => (string) Str::uuid()]), UploadedFile::fake()->image('second.png'));
        $this->assertFalse($service->current($rejection->fresh()));
        $order->paymentProofs()->where('status', 'pending')->update(['status' => 'rejected']);
        $this->pay($order, 175000);
        $order->update(['status' => 'completed']);
        $entry = $order->customerRequests()->create(['entry_key' => (string) Str::uuid(), 'kind' => 'refund', 'reason' => 'Pengembalian deposit uji', 'estimate' => ['received' => 175000, 'fee' => 0, 'refund' => 100000], 'status' => 'pending']);
        app(RentalCustomerRequestService::class)->review($entry, 'approve', 'Refund uji dapat diproses', $this->admin, $this->request());
        $approval = RentalNotification::where('kind', 'request_approved')->firstOrFail();
        $this->assertTrue($service->current($approval));
        $this->assertStringContainsString('belum berarti dana dikembalikan', $service->render($order->fresh(), $approval->kind, $approval->context));
        app(RentalCustomerRequestService::class)->settle($entry, ['method' => 'other', 'party_name' => 'Penerima Uji', 'reference' => 'REFUND', 'refund_date' => '2026-10-28'], UploadedFile::fake()->image('refund.jpg'), $this->admin, $this->request());
        $this->assertFalse($service->current($approval->fresh()));
        $this->assertSame(1, RentalNotification::where('kind', 'deposit_refunded')->count());
    }

    public function test_conversation_belonging_to_another_customer_prevents_gateway_send(): void
    {
        $order = $this->order();
        $service = $this->configure();
        $this->verify($order);
        $other = Customer::create(['name' => 'Pelanggan lain', 'whatsapp_number' => '628999000111', 'status' => 'lead']);
        Conversation::create(['wa_chat_id' => '628111222333@c.us', 'customer_id' => $other->id, 'channel' => 'whatsapp', 'status' => 'open']);
        $row = app(RentalMessagingService::class)->queuePortal($order);
        $gateway = $this->mock(WhatsAppGateway::class);
        $gateway->shouldNotReceive('sendText');
        (new SendRentalNotificationJob($row->id))->handle($gateway, $service);
        $this->assertSame('skipped', $row->refresh()->status);
        $this->assertSame(0, (int) $row->attempts);
    }

    public function test_failure_details_mask_tokens_and_unknown_delivery_needs_manual_review(): void
    {
        $order = $this->order();
        $service = $this->configure();
        $this->verify($order);
        $row = app(RentalMessagingService::class)->queuePortal($order);
        $token = $order->portal_token_secret;
        $gateway = $this->mock(WhatsAppGateway::class);
        $gateway->shouldReceive('sendText')->once()->andThrow(new ConnectionException('Timeout https://gayatri-kediri.web.id/rental/portal/access/'.$token));
        (new SendRentalNotificationJob($row->id))->handle($gateway, $service);
        $this->assertSame('unknown', $row->fresh()->status);
        $this->assertFalse(str_contains($row->failure_reason, $token));
        $this->post(route('admin.rentals.notifications.retry', $row))->assertSessionHasErrors('rental');
    }

    public function test_new_settings_validation_public_urls_and_roles(): void
    {
        $service = $this->configure();
        $data = $service->settings();
        $route = route('admin.rentals.notifications.update');
        foreach (['http://example.com', 'https://localhost', 'https://127.0.0.1', 'https://example.com/path', 'https://user:pass@example.com', 'https://example.com?token=x'] as $base) {
            $this->put($route, array_replace($data, ['public_base_url' => $base]))->assertSessionHasErrors('public_base_url');
        }
        $this->put($route, array_replace($data, ['payment_max_reminders' => 0]))->assertSessionHasErrors('payment_max_reminders');
        $this->put($route, array_replace($data, ['payment_reminder_interval_days' => 0]))->assertSessionHasErrors('payment_reminder_interval_days');
        $this->put($route, $data)->assertSessionHasNoErrors();
        $order = $this->order();
        foreach (['sales', 'therapist'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'active']));
            $this->post(route('admin.rentals.messaging.recipient', $order), ['notification_phone' => '08111222333', 'recipient_confirmed' => 1])->assertForbidden();
            $this->post(route('admin.rentals.messaging.portal', $order))->assertForbidden();
        }
        Http::assertNothingSent();
    }

    public function test_verified_customer_cancellation_sends_one_decision_message_and_repeated_reviews_do_not_duplicate_it(): void
    {
        $order = $this->order();
        $this->configure();
        $this->verify($order);
        $entry = $order->customerRequests()->create(['entry_key' => (string) Str::uuid(), 'kind' => 'cancellation', 'reason' => 'Pelanggan ingin membatalkan', 'estimate' => ['received' => 0, 'fee' => 0, 'refund' => 0], 'status' => 'pending']);
        $service = app(RentalCustomerRequestService::class);
        $service->review($entry, 'approve', 'Pembatalan telah ditinjau', $this->admin, $this->request());
        $service->review($entry, 'approve', 'Pembatalan telah ditinjau', $this->admin, $this->request());
        $this->assertSame(1, RentalNotification::where('kind', 'request_approved')->count());
        $this->assertSame(0, RentalNotification::where('kind', 'cancelled')->count());
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_new_events_can_be_disabled_and_private_recipient_status_is_rechecked(): void
    {
        $order = $this->order();
        $service = $this->configure(['events' => ['portal_shared']]);
        $this->verify($order);
        $this->pay($order, 10000);
        $this->assertSame(0, RentalNotification::count());
        $row = app(RentalMessagingService::class)->queuePortal($order);
        $this->customer->update(['status' => 'inactive']);
        $gateway = $this->mock(WhatsAppGateway::class);
        $gateway->shouldNotReceive('sendText');
        (new SendRentalNotificationJob($row->id))->handle($gateway, $service);
        $this->assertSame('skipped', $row->fresh()->status);
    }
}
