<?php

namespace Tests\Feature;

use App\Jobs\SendRentalNotificationJob;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\RentalExtension;
use App\Models\RentalItem;
use App\Models\RentalNotification;
use App\Models\RentalOrder;
use App\Models\RentalProduct;
use App\Models\RentalUnit;
use App\Models\User;
use App\Services\CRM\RentalNotificationService;
use App\Services\CRM\RentalOperations;
use App\Services\CRM\RentalSettings;
use App\Services\WhatsApp\WhatsAppGateway;
use Carbon\Carbon;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class RentalAutomationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private RentalProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-27 09:30:00', 'Asia/Jakarta'));
        Cache::flush();
        Queue::fake();
        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($this->admin);
        $this->customer = Customer::create(['name' => 'Penyewa Uji', 'whatsapp_number' => '6281234000999', 'status' => 'lead']);
        $this->product = RentalProduct::create(['sku' => 'AUTO-ST', 'name' => 'Stroller Uji', 'category' => 'Stroller', 'daily_rate' => 25000, 'weekly_rate' => 140000, 'monthly_rate' => 500000, 'deposit' => 100000, 'turnaround_days' => 1, 'is_active' => true]);
        foreach (['AUTO-001', 'AUTO-002'] as $code) {
            RentalUnit::create(['rental_product_id' => $this->product->id, 'code' => $code, 'status' => 'ready', 'condition_notes' => 'Baik dan lengkap']);
        }
    }

    private function order(array $overrides = [], string $status = 'rented'): RentalOrder
    {
        $data = array_replace(['customer_id' => $this->customer->id, 'items' => [['product_id' => $this->product->id, 'quantity' => 1]], 'start_date' => '2026-10-27', 'end_date' => '2026-10-28', 'period' => 'day', 'fulfilment' => 'self'], $overrides);
        $data['quote_token'] = $this->postJson(route('rental.quote'), $data)->assertOk()->json('token');
        $this->post(route('admin.rentals.orders.store'), $data)->assertSessionHasNoErrors();
        $order = RentalOrder::latest('id')->firstOrFail();
        $order->update(['terms_accepted_at' => now(), 'terms_acceptance_source' => 'portal']);
        if ($status !== 'pending') {
            $this->action($order, 'confirm');
        }
        if ($status === 'rented') {
            $this->action($order, 'dispatch');
        }

        return $order->refresh();
    }

    private function action(RentalOrder $order, string $action): void
    {
        $this->post(route('admin.rentals.orders.action', $order), ['action' => $action])->assertSessionHasNoErrors();
        $order->refresh();
    }

    private function preview(RentalOrder $order, string $end): array
    {
        return $this->postJson(route('admin.rentals.orders.extension-preview', $order), ['new_end_date' => $end])->assertOk()->json();
    }

    private function extend(RentalOrder $order, string $end): void
    {
        $q = $this->preview($order, $end);
        $this->post(route('admin.rentals.orders.extend', $order), ['new_end_date' => $end, 'extension_token' => $q['token'], 'notes' => 'Disetujui pelanggan dan admin'])->assertSessionHasNoErrors();
        $order->refresh();
    }

    private function notify(array $overrides = []): RentalNotificationService
    {
        $service = app(RentalNotificationService::class);
        $service->save(array_replace($service->settings(), ['enabled' => true], $overrides));

        return $service;
    }

    private function returned(RentalOrder $order, ?RentalItem $item = null): RentalItem
    {
        $item ??= $order->items()->firstOrFail();
        $this->post(route('admin.rentals.orders.return', [$order, $item]), ['notes' => 'Diterima, menunggu pemeriksaan'])->assertSessionHasNoErrors();

        return $item->refresh();
    }

    private function inspect(RentalOrder $order, RentalItem $item, string $status = 'ready'): void
    {
        $this->post(route('admin.rentals.orders.inspect', [$order, $item]), ['notes' => 'Kondisi sudah diperiksa', 'unit_status' => $status, 'late_fee' => 0, 'damage_fee' => 0])->assertSessionHasNoErrors();
    }

    public function test_extension_uses_original_rate_updates_bill_and_keeps_deposit(): void
    {
        $order = $this->order();
        $this->product->update(['daily_rate' => 99000, 'deposit' => 999999]);
        $q = $this->preview($order, '2026-10-30');
        $this->assertSame(50000, $q['quote']['additional_charge']);
        $this->extend($order, '2026-10-30');
        $this->assertEquals(100000, $order->rental_subtotal);
        $this->assertEquals(100000, $order->deposit_total);
        $this->assertSame(2, (int) $order->schedule_version);
        $this->assertSame('2026-11-01', $order->items()->first()->reserved_until->toDateString());
        $this->assertDatabaseHas('audit_logs', ['action' => 'rental.extend']);
        $this->get(route('admin.rentals.orders.show', $order))->assertOk()->assertSee('Riwayat Perpanjangan')->assertSee('50.000');
    }

    public function test_extension_inside_paid_week_does_not_charge_a_second_week(): void
    {
        $order = $this->order(['period' => 'week']);
        $this->assertSame(0, $this->preview($order, '2026-11-01')['quote']['additional_charge']);
        $this->extend($order, '2026-11-01');
        $this->assertEquals(140000, $order->rental_subtotal);
        $this->assertSame(140000, $this->preview($order, '2026-11-03')['quote']['additional_charge']);
    }

    public function test_fortnight_extension_uses_fourteen_day_blocks_and_the_original_price(): void
    {
        $this->product->update(['fortnightly_rate' => 200000]);
        $order = $this->order(['period' => 'fortnight']);
        $this->product->update(['fortnightly_rate' => 999000]);
        $this->assertSame(0, $this->preview($order, '2026-11-09')['quote']['additional_charge']);
        $this->assertSame(200000, $this->preview($order, '2026-11-10')['quote']['additional_charge']);
        $this->extend($order, '2026-11-10');
        $this->assertEquals(400000, $order->rental_subtotal);
        $this->assertEquals(100000, $order->deposit_total);
    }

    public function test_extension_rejects_next_reservation_including_cleaning_buffer(): void
    {
        $order = $this->order();
        $next = $this->order(['start_date' => '2026-10-30', 'end_date' => '2026-10-31'], 'reserved');
        $this->postJson(route('admin.rentals.orders.extension-preview', $order), ['new_end_date' => '2026-10-29'])->assertUnprocessable()->assertJsonPath('message', 'AUTO-001 tidak dapat diperpanjang: bentrok dengan '.$next->code.' (30 Oct 2026—31 Oct 2026), termasuk jeda pembersihan.');
        $this->assertSame(0, RentalExtension::count());
    }

    public function test_stock_is_checked_again_after_extension_preview(): void
    {
        $order = $this->order();
        $q = $this->preview($order, '2026-10-31');
        $this->order(['start_date' => '2026-10-30', 'end_date' => '2026-10-31'], 'reserved');
        $this->post(route('admin.rentals.orders.extend', $order), ['new_end_date' => '2026-10-31', 'extension_token' => $q['token'], 'notes' => 'Coba sesudah stok berubah'])->assertSessionHasErrors('rental');
        $this->assertSame('2026-10-28', $order->refresh()->end_date->toDateString());
        $this->assertSame(0, RentalExtension::count());
    }

    public function test_partial_return_extension_only_charges_open_units_and_leaves_returned_unit_dates(): void
    {
        $order = $this->order(['items' => [['product_id' => $this->product->id, 'quantity' => 2]]]);
        $returned = $this->returned($order);
        $this->extend($order, '2026-10-30');
        $this->assertEquals(150000, $order->rental_subtotal);
        $this->assertSame('2026-10-28', $returned->refresh()->rental_end_date->toDateString());
        $this->assertEquals(50000, $returned->rental_price);
        $this->assertEquals(100000, $order->items()->whereNull('returned_at')->first()->rental_price);
    }

    public function test_overdue_rental_can_extend_itself_without_bypassing_other_bookings(): void
    {
        $order = $this->order(['end_date' => '2026-10-27']);
        $this->travel(2)->days();
        $this->extend($order, '2026-10-30');
        $this->assertFalse($order->isOverdue());
        $this->assertEquals(100000, $order->rental_subtotal);
    }

    public function test_extension_replay_is_idempotent_and_makes_one_notification(): void
    {
        $order = $this->order();
        $this->notify();
        $q = $this->preview($order, '2026-10-30');
        $data = ['new_end_date' => '2026-10-30', 'extension_token' => $q['token'], 'notes' => 'Persetujuan perpanjangan'];
        $this->post(route('admin.rentals.orders.extend', $order), $data)->assertSessionHasNoErrors();
        $this->post(route('admin.rentals.orders.extend', $order), $data)->assertSessionHasNoErrors();
        $this->assertSame(1, RentalExtension::count());
        $this->assertSame(1, RentalNotification::where('kind', 'extended')->count());
        $this->assertEquals(100000, $order->refresh()->rental_subtotal);
    }

    public function test_stale_extension_after_partial_return_and_expired_token_are_rejected(): void
    {
        $order = $this->order(['items' => [['product_id' => $this->product->id, 'quantity' => 2]]]);
        $q = $this->preview($order, '2026-10-30');
        $this->returned($order);
        $this->post(route('admin.rentals.orders.extend', $order), ['new_end_date' => '2026-10-30', 'extension_token' => $q['token'], 'notes' => 'Sudah berubah jumlah unit'])->assertSessionHasErrors('rental');
        $q = $this->preview($order, '2026-10-30');
        $this->travel(11)->minutes();
        $this->post(route('admin.rentals.orders.extend', $order), ['new_end_date' => '2026-10-30', 'extension_token' => $q['token'], 'notes' => 'Estimasi lama tidak berlaku'])->assertSessionHasErrors('rental');
    }

    public function test_invalid_extension_status_date_limit_and_forged_token_are_rejected(): void
    {
        $order = $this->order([], 'pending');
        $this->postJson(route('admin.rentals.orders.extension-preview', $order), ['new_end_date' => '2026-10-30'])->assertUnprocessable();
        $this->action($order, 'confirm');
        $this->postJson(route('admin.rentals.orders.extension-preview', $order), ['new_end_date' => '2026-10-28'])->assertUnprocessable();
        $this->post(route('admin.rentals.orders.extend', $order), ['new_end_date' => '2026-10-30', 'extension_token' => 'forged', 'notes' => 'Token palsu pengujian'])->assertSessionHasErrors('rental');
        $settings = app(RentalSettings::class);
        $settings->save(array_replace($settings->get(), ['max_rental_days' => 3]));
        $this->postJson(route('admin.rentals.orders.extension-preview', $order), ['new_end_date' => '2026-11-10'])->assertUnprocessable();
    }

    public function test_operational_warning_points_to_overdue_and_threatened_future_order(): void
    {
        $order = $this->order(['end_date' => '2026-10-27']);
        $next = $this->order(['start_date' => '2026-10-29', 'end_date' => '2026-10-30'], 'reserved');
        $this->travel(2)->days();
        $alerts = app(RentalOperations::class)->alerts();
        $this->assertTrue($alerts->contains('type', 'Terlambat kembali'));
        $this->assertTrue($alerts->contains(fn ($a) => $a['type'] === 'Pesanan berikutnya terancam' && $a['order']->id === $next->id && $a['blocking_order']->id === $order->id));
        $this->get(route('admin.rentals.operations'))->assertOk()->assertSee($next->code)->assertSee('Pesanan berikutnya terancam');
    }

    public function test_returned_unit_still_threatens_next_order_until_inspected(): void
    {
        $order = $this->order(['end_date' => '2026-10-27']);
        $next = $this->order(['start_date' => '2026-10-29', 'end_date' => '2026-10-30'], 'reserved');
        $item = $this->returned($order);
        $this->assertTrue(app(RentalOperations::class)->alerts($next)->contains('type', 'Pesanan berikutnya terancam'));
        $this->inspect($order, $item);
        $this->assertCount(0, app(RentalOperations::class)->alerts());
    }

    public function test_repair_warning_is_cleared_by_replacing_reserved_unit(): void
    {
        $order = $this->order(['end_date' => '2026-10-27']);
        $next = $this->order(['start_date' => '2026-10-29', 'end_date' => '2026-10-30'], 'reserved');
        $this->inspect($order, $this->returned($order), 'maintenance');
        $this->assertTrue(app(RentalOperations::class)->alerts($next)->contains('type', 'Unit pesanan belum siap'));
        $replacement = RentalUnit::where('code', 'AUTO-002')->firstOrFail();
        $this->post(route('admin.rentals.orders.replace', [$next, $next->items()->first()]), ['unit_id' => $replacement->id])->assertSessionHasNoErrors();
        $this->assertCount(0, app(RentalOperations::class)->alerts($next));
    }

    public function test_warning_horizon_and_expired_holds_are_respected(): void
    {
        $order = $this->order(['end_date' => '2026-10-27']);
        $next = $this->order(['start_date' => '2026-11-10', 'end_date' => '2026-11-11'], 'reserved');
        $this->inspect($order, $this->returned($order), 'maintenance');
        $this->assertCount(0, app(RentalOperations::class)->alerts($next));
        $settings = app(RentalSettings::class);
        $settings->save(array_replace($settings->get(), ['ops_lookahead_days' => 30]));
        $this->assertNotEmpty(app(RentalOperations::class)->alerts($next));
        $next->update(['status' => 'pending', 'hold_expires_at' => now()->subMinute()]);
        $this->assertCount(0, app(RentalOperations::class)->alerts());
    }

    public function test_notification_settings_default_off_and_customer_placeholder_numbers_are_skipped(): void
    {
        $order = $this->order();
        $this->assertFalse(app(RentalNotificationService::class)->settings()['enabled']);
        Queue::assertNothingPushed();
        $service = $this->notify();
        $this->customer->update(['whatsapp_number' => '6200000000001']);
        $row = $service->enqueue($order->fresh(), 'confirmed', 'placeholder');
        $this->assertSame('skipped', $row->status);
        Queue::assertNothingPushed();
    }

    public function test_admin_can_save_templates_and_preview_draft_without_sending(): void
    {
        $order = $this->order();
        $service = app(RentalNotificationService::class);
        $settings = $service->settings();
        $this->put(route('admin.rentals.notifications.update'), $settings)->assertSessionHasNoErrors();
        $this->postJson(route('admin.rentals.notifications.preview'), ['order_id' => $order->id, 'kind' => 'confirmed', 'template' => 'Halo {nama}, kode {kode_sewa}.'])->assertOk()->assertJsonPath('preview', 'Halo Penyewa Uji, kode '.$order->code.'.');
        $this->assertStringNotContainsString('Halo {nama}, kode', $service->settings()['templates']['confirmed']);
        $settings['templates']['confirmed'] = 'Tidak dikenal {api_secret}';
        $this->put(route('admin.rentals.notifications.update'), $settings)->assertSessionHasErrors('templates.confirmed');
        $this->get(route('admin.rentals.notifications.index'))->assertOk()->assertSee('Pratinjau tanpa mengirim pesan');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_confirmation_event_queues_once_and_honors_event_switches(): void
    {
        $this->notify(['events' => ['confirmed']]);
        $order = $this->order();
        $this->assertSame(1, RentalNotification::count());
        app(RentalNotificationService::class)->enqueue($order, 'confirmed', 'confirm');
        Queue::assertPushed(SendRentalNotificationJob::class, 1);
        $this->returned($order);
        $this->assertSame(1, RentalNotification::count());
    }

    public function test_scheduler_queues_h_minus_one_and_overdue_once_each_per_day_after_send_time(): void
    {
        $this->order(['end_date' => '2026-10-27']);
        $this->order(['end_date' => '2026-10-30']);
        $service = $this->notify();
        $this->travelTo(Carbon::parse('2026-10-29 08:00:00', 'Asia/Jakarta'));
        $this->assertSame(0, $service->dispatchDue());
        $this->travel(2)->hours();
        $this->assertSame(2, $service->dispatchDue());
        $this->assertSame(0, $service->dispatchDue());
        $this->assertSame(1, RentalNotification::where('kind', 'overdue')->count());
        $this->assertSame(1, RentalNotification::where('kind', 'return_reminder')->count());
    }

    public function test_extension_invalidates_old_reminder_and_next_due_day_gets_new_notice(): void
    {
        $order = $this->order();
        $service = $this->notify();
        $this->assertSame(1, $service->dispatchDue());
        $old = RentalNotification::where('kind', 'return_reminder')->firstOrFail();
        $this->extend($order, '2026-10-30');
        $gateway = $this->mock(WhatsAppGateway::class);
        $gateway->shouldNotReceive('sendText');
        (new SendRentalNotificationJob($old->id))->handle($gateway, $service);
        $this->assertSame('skipped', $old->refresh()->status);
        $this->travel(2)->days();
        $this->assertSame(1, $service->dispatchDue());
        $this->assertSame(2, RentalNotification::where('kind', 'return_reminder')->count());
    }

    public function test_gateway_sends_once_and_records_delivery_in_inbox(): void
    {
        $service = $this->notify();
        $order = $this->order();
        $row = RentalNotification::where('kind', 'confirmed')->firstOrFail();
        $gateway = $this->mock(WhatsAppGateway::class);
        $gateway->shouldReceive('sendText')->once()->withArgs(fn ($session, $chat, $text) => $chat === '6281234000999@c.us' && str_contains($text, $order->code))->andReturn(['id' => ['_serialized' => 'rental-wa-id']]);
        $job = new SendRentalNotificationJob($row->id);
        $job->handle($gateway, $service);
        $job->handle($gateway, $service);
        $this->assertSame('sent', $row->refresh()->status);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertSame(1, Message::where('wa_message_id', 'rental-wa-id')->count());
    }

    public function test_timeout_is_unknown_and_manual_retry_requires_checked_confirmation(): void
    {
        $service = $this->notify();
        $this->order();
        $row = RentalNotification::where('kind', 'confirmed')->firstOrFail();
        $gateway = $this->mock(WhatsAppGateway::class);
        $gateway->shouldReceive('sendText')->once()->andThrow(new ConnectionException('Timeout'));
        (new SendRentalNotificationJob($row->id))->handle($gateway, $service);
        $this->assertSame('unknown', $row->refresh()->status);
        $this->post(route('admin.rentals.notifications.retry', $row))->assertSessionHasErrors('rental');
        $this->post(route('admin.rentals.notifications.retry', $row), ['checked_not_sent' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('queued', $row->refresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'rental.notification.retry']);
    }

    public function test_explicit_gateway_rejection_is_failed_and_invalid_message_id_is_unknown(): void
    {
        $service = $this->notify();
        $this->order();
        $row = RentalNotification::where('kind', 'confirmed')->firstOrFail();
        $exception = new RequestException(new \Illuminate\Http\Client\Response(new Response(401)));
        $gateway = $this->mock(WhatsAppGateway::class);
        $gateway->shouldReceive('sendText')->once()->andThrow($exception);
        (new SendRentalNotificationJob($row->id))->handle($gateway, $service);
        $this->assertSame('failed', $row->refresh()->status);
        $this->post(route('admin.rentals.notifications.retry', $row))->assertSessionHasNoErrors();
        $this->assertSame('queued', $row->refresh()->status);
    }

    public function test_disabled_setting_or_cancelled_order_skips_queued_confirmation(): void
    {
        $service = $this->notify();
        $order = $this->order([], 'reserved');
        $row = RentalNotification::where('kind', 'confirmed')->firstOrFail();
        $this->action($order, 'cancel');
        $gateway = $this->mock(WhatsAppGateway::class);
        $gateway->shouldNotReceive('sendText');
        (new SendRentalNotificationJob($row->id))->handle($gateway, $service);
        $this->assertSame('skipped', $row->refresh()->status);
    }

    public function test_return_complete_and_refund_notifications_have_distinct_deduplicated_events(): void
    {
        $order = $this->order();
        $this->notify();
        $this->inspect($order, $this->returned($order));
        $this->assertSame(1, RentalNotification::where('kind', 'returned')->count());
        $this->assertSame(1, RentalNotification::where('kind', 'completed')->count());
        $payment = ['entry_key' => (string) Str::uuid(), 'kind' => 'payment', 'amount' => 150000, 'method' => 'cash'];
        $this->post(route('admin.rentals.orders.payment', $order), $payment)->assertSessionHasNoErrors();
        $refund = ['entry_key' => (string) Str::uuid(), 'kind' => 'refund', 'amount' => 100000, 'method' => 'cash'];
        $this->post(route('admin.rentals.orders.payment', $order), $refund)->assertSessionHasNoErrors();
        $this->post(route('admin.rentals.orders.payment', $order), $refund)->assertSessionHasNoErrors();
        $this->assertSame(1, RentalNotification::where('kind', 'deposit_refunded')->count());
    }

    public function test_dry_run_and_rental_scheduler_do_not_start_other_campaigns(): void
    {
        $this->order();
        $this->notify();
        $this->artisan('crm:send-rental-reminders', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, RentalNotification::count());
        Queue::assertNothingPushed();
        $this->artisan('crm:work-rentals', ['--once' => true])->assertSuccessful();
        Queue::assertPushed(SendRentalNotificationJob::class, 1);
        $events = collect(app(Schedule::class)->events());
        $this->assertTrue($events->contains(fn ($event) => str_contains($event->command ?? '', 'crm:send-rental-reminders')));
        Http::assertNothingSent();
    }

    public function test_sales_and_therapists_cannot_access_notifications_operations_or_extensions(): void
    {
        $order = $this->order();
        foreach (['sales', 'therapist'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'active']));
            $this->get(route('admin.rentals.notifications.index'))->assertForbidden();
            $this->get(route('admin.rentals.operations'))->assertForbidden();
            $this->postJson(route('admin.rentals.orders.extension-preview', $order), ['new_end_date' => '2026-10-30'])->assertForbidden();
            $this->post(route('admin.rentals.orders.extend', $order), [])->assertForbidden();
            $this->put(route('admin.rentals.notifications.update'), [])->assertForbidden();
        }
    }

    public function test_invalid_gateway_acknowledgement_is_unknown_and_not_automatically_resent(): void
    {
        $service = $this->notify();
        $this->order();
        $row = RentalNotification::where('kind', 'confirmed')->firstOrFail();
        $gateway = $this->mock(WhatsAppGateway::class);
        $gateway->shouldReceive('sendText')->once()->andReturn([]);
        $job = new SendRentalNotificationJob($row->id);
        $job->handle($gateway, $service);
        $job->handle($gateway, $service);
        $this->assertSame('unknown', $row->refresh()->status);
    }

    public function test_notifications_switched_off_after_queueing_are_skipped(): void
    {
        $service = $this->notify();
        $this->order();
        $row = RentalNotification::where('kind', 'confirmed')->firstOrFail();
        $service->save(array_replace($service->settings(), ['enabled' => false]));
        $gateway = $this->mock(WhatsAppGateway::class);
        $gateway->shouldNotReceive('sendText');
        (new SendRentalNotificationJob($row->id))->handle($gateway, $service);
        $this->assertSame('skipped', $row->refresh()->status);
    }

    public function test_reminder_only_lists_units_still_out_and_skips_after_return(): void
    {
        $order = $this->order(['items' => [['product_id' => $this->product->id, 'quantity' => 2]]]);
        $returned = $this->returned($order);
        $service = $this->notify();
        $service->dispatchDue();
        $row = RentalNotification::where('kind', 'return_reminder')->firstOrFail();
        $text = $service->render($order->fresh(), 'return_reminder');
        $this->assertStringNotContainsString($returned->unit_code, $text);
        $this->returned($order, $order->items()->whereNull('returned_at')->firstOrFail());
        $gateway = $this->mock(WhatsAppGateway::class);
        $gateway->shouldNotReceive('sendText');
        (new SendRentalNotificationJob($row->id))->handle($gateway, $service);
        $this->assertSame('skipped', $row->refresh()->status);
    }

    public function test_interrupted_sending_is_marked_unknown_and_expired_queue_is_skipped(): void
    {
        $service = $this->notify(['events' => ['confirmed']]);
        $this->order();
        $row = RentalNotification::where('kind', 'confirmed')->firstOrFail();
        $row->update(['status' => 'sending', 'started_at' => now()->subMinutes(5)]);
        $service->dispatchDue();
        $this->assertSame('unknown', $row->refresh()->status);
        $row->update(['status' => 'queued', 'expires_at' => now()->subMinute()]);
        $service->dispatchDue();
        $this->assertSame('skipped', $row->refresh()->status);
    }

    public function test_inactive_customer_is_skipped_and_existing_lid_conversation_is_supported(): void
    {
        $service = $this->notify();
        $order = $this->order();
        $row = RentalNotification::where('kind', 'confirmed')->firstOrFail();
        $this->customer->update(['status' => 'blacklisted']);
        $gateway = $this->mock(WhatsAppGateway::class);
        $gateway->shouldNotReceive('sendText');
        (new SendRentalNotificationJob($row->id))->handle($gateway, $service);
        $this->assertSame('skipped', $row->refresh()->status);
        $this->customer->update(['status' => 'lead', 'whatsapp_number' => null]);
        Conversation::create(['customer_id' => $this->customer->id, 'wa_chat_id' => '123456789012@lid', 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => false]);
        $this->assertSame('123456789012@lid', $service->recipient($order->fresh()));
    }

    public function test_imported_overlapping_reservations_show_explicit_conflict(): void
    {
        $order = $this->order();
        $next = $this->order(['start_date' => '2026-10-30', 'end_date' => '2026-10-31'], 'reserved');
        $order->update(['end_date' => '2026-10-30']);
        $order->items()->update(['reserved_until' => '2026-11-01']);
        $this->assertTrue(app(RentalOperations::class)->alerts($next)->contains('type', 'Reservasi unit bertumpuk'));
    }
}
