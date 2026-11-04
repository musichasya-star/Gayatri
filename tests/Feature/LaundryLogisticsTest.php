<?php

namespace Tests\Feature;

use App\Jobs\SendLaundryNotificationJob;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\LaundryCourier;
use App\Models\LaundryNotification;
use App\Models\LaundryOrder;
use App\Models\LaundryTariff;
use App\Models\LaundryTransportTask;
use App\Models\Message;
use App\Models\User;
use App\Services\CRM\LaundryNotificationService;
use App\Services\CRM\LaundrySettings;
use App\Services\CRM\LaundryTransportPricing;
use App\Services\CRM\LaundryTransportService;
use App\Services\WhatsApp\WhatsAppGateway;
use Carbon\Carbon;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class LaundryLogisticsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private LaundryCourier $courier;

    private LaundryTariff $tariff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 31)->setTime(10, 0));
        Storage::fake('local');
        Queue::fake();
        Http::preventStrayRequests();
        config(['waha.default_session' => 'laundry-test', 'baileys.default_session' => 'laundry-test']);
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($this->admin);
        $this->customer = Customer::create(['name' => 'Ibu Laundry Uji', 'whatsapp_number' => '6280000018001', 'status' => 'lead']);
        $this->courier = LaundryCourier::create(['name' => 'PETUGAS UJI', 'is_active' => true]);
        $this->tariff = LaundryTariff::create(['code' => 'LOG-KG', 'name' => 'Cuci Lipat Uji', 'unit' => 'kg', 'audience' => 'both', 'price' => 7500, 'minimum_milli' => 3000, 'increment_milli' => 500, 'duration_hours' => 24, 'is_active' => true]);
        app(LaundrySettings::class)->save(['accepting_orders' => true, 'outlet_name' => 'Outlet Uji Laundry', 'address' => 'Alamat outlet uji laundry', 'whatsapp_number' => '6280000018000', 'opening_hours' => '08.00–18.00 WIB', 'terms' => 'Ketentuan uji', 'allow_collection' => true], $this->admin, Request::create('/'));
        $this->pricing();
    }

    private function pricing(array $changes = []): array
    {
        $data = array_replace(['enabled' => true, 'origin_latitude' => '-7.8', 'origin_longitude' => '112', 'mode' => 'per_km', 'max_distance_km' => '15', 'pickup_flat_fee' => 10000, 'delivery_flat_fee' => 12000, 'pickup_rate_per_km' => 2500, 'delivery_rate_per_km' => 3000, 'minimum_fee' => 5000], $changes);
        app(LaundryTransportPricing::class)->save($data, $this->admin, Request::create('/'));

        return $data;
    }

    private function order(string $mode = 'walkin'): LaundryOrder
    {
        $order = LaundryOrder::create(['code' => 'LND-UJI-'.Str::random(8), 'entry_key' => (string) Str::uuid(), 'input_hash' => hash('sha256', Str::random()), 'customer_id' => $this->customer->id, 'created_by' => $this->admin->id, 'customer_snapshot' => $this->customer->only(['id', 'name', 'whatsapp_number', 'phone']), 'outlet_snapshot' => app(LaundrySettings::class)->get(), 'received_at' => now()->subHours(2), 'estimated_ready_at' => now()->addDay(), 'intake_mode' => $mode, 'estimated_total' => 30000, 'actual_total' => 22500, 'status' => 'approved', 'approved_at' => now(), 'approved_name' => $this->customer->name, 'approval_channel' => 'outlet', 'approval_reference' => 'Pelanggan menyetujui nilai jasa.', 'revision' => 1]);
        $order->items()->create(['laundry_tariff_id' => $this->tariff->id, 'bag_code' => $order->code.'-01', 'bag_label' => 'Baju Bayi', 'tariff_snapshot' => $this->tariff->snapshot(), 'received_pieces' => 12, 'estimated_milli' => 3800, 'estimated_billable_milli' => 4000, 'estimated_amount' => 30000, 'condition_notes' => 'Kondisi awal diperiksa.', 'actual_pieces' => 12, 'actual_milli' => 2310, 'actual_billable_milli' => 3000, 'actual_amount' => 22500]);

        return $order->fresh();
    }

    private function taskData(?LaundryOrder $order = null, string $kind = 'pickup', array $changes = []): array
    {
        return array_replace(['entry_key' => (string) Str::uuid(), 'kind' => $kind, 'laundry_order_id' => $order?->id, 'customer_id' => $this->customer->id, 'laundry_courier_id' => $this->courier->id, 'scheduled_start' => '2026-10-31T11:00', 'scheduled_end' => '2026-10-31T12:00', 'address' => 'Alamat lengkap tujuan pengujian Kediri', 'distance_source' => 'manual', 'distance_km' => '4.01', 'distance_note' => 'Jarak jalan diperiksa admin.', 'approved_name' => $this->customer->name, 'approval_reference' => 'Pelanggan menyetujui ongkir melalui telepon.', 'notes' => 'Kunjungan uji lokal.'], $changes);
    }

    private function preview(array $data): array
    {
        return $this->post(route('admin.laundry.transport.preview'), $data)->assertOk()->viewData('preview');
    }

    private function task(?LaundryOrder $order = null, string $kind = 'pickup', array $changes = []): LaundryTransportTask
    {
        $preview = $this->preview($this->taskData($order, $kind, $changes));
        $this->post(route('admin.laundry.transport.store'), ['token' => $preview['token'], 'confirmed' => 1])->assertSessionHasNoErrors();

        return LaundryTransportTask::latest('id')->firstOrFail();
    }

    private function action(LaundryTransportTask $task, string $action, array $extra = [])
    {
        return $this->from(route('admin.laundry.transport.show', $task))->post(route('admin.laundry.transport.action', $task), $extra + ['revision' => $task->fresh()->revision, 'action' => $action, 'notes' => 'Catatan hasil kunjungan uji.']);
    }

    private function ready(LaundryOrder $order): void
    {
        $order->update(['workflow_status' => 'ready', 'ready_at' => now()]);
        $order->qualityChecks()->create(['actor_id' => $this->admin->id, 'passed' => true, 'items' => [], 'revision' => $order->revision, 'created_at' => now()]);
    }

    private function pay(LaundryOrder $order, ?int $amount = null): void
    {
        $order->refresh();
        $this->post(route('admin.laundry.payments.store', $order), ['revision' => $order->revision, 'entry_key' => (string) Str::uuid(), 'amount' => $amount ?? $order->balance(), 'method' => 'cash', 'paid_at' => now()->format('Y-m-d\TH:i'), 'payer_name' => $this->customer->name, 'confirmed' => 1])->assertSessionHasNoErrors();
    }

    private function notificationSettings(array $changes = []): array
    {
        $service = app(LaundryNotificationService::class);
        $data = array_replace($service->settings(), ['enabled' => true, 'dry_run' => true], $changes);
        $service->save($data, $this->admin, Request::create('/'));

        return $data;
    }

    public function test_quotes_use_separate_rates_minimum_radius_and_explicit_distance_sources(): void
    {
        $pricing = app(LaundryTransportPricing::class);
        $data = $this->taskData();
        $this->assertSame(10250, $pricing->quote($data)['fee']);
        $this->assertSame(4100, $pricing->quote($data)['distance_milli']);
        $this->assertSame(12300, $pricing->quote(array_replace($data, ['kind' => 'delivery']))['fee']);
        $this->assertSame(5000, $pricing->quote(array_replace($data, ['distance_km' => '0']))['fee']);
        $this->assertSame(0, $pricing->quote(array_replace($data, ['distance_source' => 'coordinates', 'latitude' => '-7.8', 'longitude' => '112']))['distance_milli']);
        $this->pricing(['mode' => 'flat']);
        $this->assertSame(10000, $pricing->quote($data)['fee']);
        $this->post(route('admin.laundry.transport.preview'), array_replace($data, ['distance_km' => '15.001']))->assertSessionHasErrors('distance_km');
        $this->post(route('admin.laundry.transport.preview'), array_replace($data, ['distance_km' => '4.0001']))->assertSessionHasErrors('distance_km');
        $this->pricing(['enabled' => false]);
        $this->post(route('admin.laundry.transport.preview'), $data)->assertSessionHasErrors('transport');
        $this->get(route('admin.laundry.transport.settings'))->assertOk();
    }

    public function test_transport_preview_is_read_only_confirmation_idempotent_and_changed_settings_rejected(): void
    {
        $preview = $this->preview($this->taskData());
        $this->assertSame(0, LaundryTransportTask::count());
        $this->post(route('admin.laundry.transport.store'), ['token' => $preview['token']])->assertSessionHasErrors('confirmed');
        for ($i = 0; $i < 2; $i++) {
            $this->post(route('admin.laundry.transport.store'), ['token' => $preview['token'], 'confirmed' => 1])->assertSessionHasNoErrors();
        }
        $this->assertSame(1, LaundryTransportTask::count());
        $this->assertSame(1, Customer::count());
        $this->assertSame(0, LaundryOrder::count());
        $preview = $this->preview($this->taskData(null, 'pickup', ['scheduled_start' => '2026-10-31T12:00', 'scheduled_end' => '2026-10-31T13:00']));
        $this->pricing(['pickup_rate_per_km' => 9999]);
        $this->post(route('admin.laundry.transport.store'), ['token' => $preview['token'], 'confirmed' => 1])->assertSessionHasErrors('transport');
        $this->assertSame(1, LaundryTransportTask::count());
    }

    public function test_foreign_actor_expired_preview_and_changed_contact_never_save_tasks(): void
    {
        $preview = $this->preview($this->taskData());
        $this->actingAs(User::factory()->create(['role' => 'manager', 'status' => 'active']));
        $this->post(route('admin.laundry.transport.store'), ['token' => $preview['token'], 'confirmed' => 1])->assertSessionHasErrors('transport');
        $this->actingAs($this->admin);
        $this->travel(16)->minutes();
        $this->post(route('admin.laundry.transport.store'), ['token' => $preview['token'], 'confirmed' => 1])->assertSessionHasErrors('transport');
        $this->travelTo(Carbon::parse('2026-10-31 10:00:00', 'Asia/Jakarta'));
        $preview = $this->preview($this->taskData());
        $this->customer->update(['name' => 'Identitas diubah']);
        $this->post(route('admin.laundry.transport.store'), ['token' => $preview['token'], 'confirmed' => 1])->assertSessionHasErrors('transport');
        $this->assertSame(0, LaundryTransportTask::count());
    }

    public function test_pickup_before_intake_is_linked_once_to_matching_customer_and_adds_fee_to_invoice(): void
    {
        $task = $this->task();
        $this->delete(route('admin.customers.destroy', $this->customer))->assertForbidden();
        $owner = User::factory()->create(['role' => 'owner', 'status' => 'active']);
        $this->actingAs($owner)->delete(route('admin.customers.destroy', $this->customer))->assertSessionHasErrors('customer');
        $this->actingAs($this->admin);
        $this->action($task, 'start')->assertSessionHasNoErrors();
        $this->action($task, 'complete', ['receiver_name' => 'Petugas Outlet', 'pieces' => 12, 'confirmed' => 1, 'proof' => UploadedFile::fake()->image('jemput.png')])->assertSessionHasNoErrors();
        $task->refresh();
        Storage::disk('local')->assertExists($task->proof_path);
        $this->get(route('admin.laundry.transport.proof', $task))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $order = $this->order('collection');
        $this->post(route('admin.laundry.transport.attach', $task), ['revision' => $task->revision, 'order_revision' => $order->revision, 'laundry_order_id' => $order->id, 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertSame(10250, $order->fresh()->pickup_fee);
        $this->assertSame(32750, $order->fresh()->totalDue());
        $this->post(route('admin.laundry.transport.attach', $task), ['revision' => $task->fresh()->revision, 'order_revision' => $order->fresh()->revision, 'laundry_order_id' => $order->id, 'confirmed' => 1])->assertSessionHasErrors('transport');
        $this->post(route('admin.laundry.documents.invoice', $order), ['revision' => $order->fresh()->revision])->assertRedirect();
        $doc = $order->documents()->sole();
        $this->assertSame(32750, $doc->snapshot['total']);
        $this->assertSame(22500, $doc->snapshot['service_total']);
        $this->assertSame(10250, $doc->snapshot['pickup_fee']);
        $this->get(route('admin.laundry.documents.show', [$order, $doc]))->assertOk()->assertSee('Ongkir penjemputan')->assertSee('Rp 10.250');
        $this->get(route('admin.laundry.transport.show', $task))->assertOk()->assertSee($order->code);
    }

    public function test_ongoing_pickup_blocks_processing_and_cancellation_of_linked_order(): void
    {
        $order = $this->order('collection');
        $task = $this->task($order);
        $this->post(route('admin.laundry.orders.start', $order), ['revision' => $order->fresh()->revision, 'steps' => ['finishing', 'packing']])->assertSessionHasErrors('laundry');
        $this->post(route('admin.laundry.orders.cancel', $order), ['revision' => $order->fresh()->revision, 'reason' => 'Pelanggan batal.'])->assertSessionHasErrors('laundry');
        $this->action($task, 'start')->assertSessionHasNoErrors();
        $this->action($task, 'complete', ['receiver_name' => 'Outlet Uji', 'pieces' => 12, 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->post(route('admin.laundry.orders.start', $order), ['revision' => $order->fresh()->revision, 'steps' => ['finishing', 'packing']])->assertSessionHasNoErrors();
    }

    public function test_overlap_rescheduling_and_actual_ongoing_visit_are_guarded_for_each_courier(): void
    {
        $first = $this->task();
        $this->post(route('admin.laundry.transport.preview'), $this->taskData(null, 'pickup', ['scheduled_start' => '2026-10-31T11:30', 'scheduled_end' => '2026-10-31T12:30']))->assertSessionHasErrors('scheduled_start');
        $second = $this->task(null, 'pickup', ['scheduled_start' => '2026-10-31T12:00', 'scheduled_end' => '2026-10-31T13:00']);
        $this->post(route('admin.laundry.transport.reschedule', $second), ['revision' => $second->revision, 'scheduled_start' => '2026-10-31T11:30', 'scheduled_end' => '2026-10-31T12:30', 'laundry_courier_id' => $this->courier->id, 'reason' => 'Ganti waktu kunjungan.'])->assertSessionHasErrors('scheduled_start');
        $this->action($first, 'start')->assertSessionHasNoErrors();
        $this->action($second, 'start')->assertSessionHasErrors('transport');
        $this->action($first, 'fail')->assertSessionHasNoErrors();
        $this->action($second, 'start')->assertSessionHasNoErrors();
        $this->assertSame('in_transit', $second->fresh()->status);
        $this->assertSame('failed', $first->fresh()->status);
    }

    public function test_failed_cancelled_and_rescheduled_visits_preserve_fees_until_explicit_waiver(): void
    {
        $order = $this->order('collection');
        $task = $this->task($order);
        $this->action($task, 'start')->assertSessionHasNoErrors();
        $this->action($task, 'fail')->assertSessionHasNoErrors();
        $this->pricing(['pickup_rate_per_km' => 9000]);
        $this->post(route('admin.laundry.transport.reschedule', $task), ['revision' => $task->fresh()->revision, 'scheduled_start' => '2026-10-31T12:00', 'scheduled_end' => '2026-10-31T13:00', 'laundry_courier_id' => $this->courier->id, 'reason' => 'Pelanggan meminta jam baru.'])->assertSessionHasNoErrors();
        $this->assertSame(10250, $task->fresh()->quote_snapshot['fee']);
        $this->action($task, 'cancel')->assertSessionHasNoErrors();
        $this->assertSame(10250, $order->fresh()->pickup_fee);
        $this->post(route('admin.laundry.transport.waive', $order), ['revision' => $order->fresh()->revision, 'kind' => 'pickup', 'approved_name' => 'Pelanggan Uji', 'reason' => 'Disepakati bebas ongkir.', 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertSame(0, $order->fresh()->pickup_fee);
        $this->assertSame(22500, $order->fresh()->totalDue());
    }

    public function test_shipping_cannot_change_past_invoice_or_paid_totals_and_service_only_payment_is_partial(): void
    {
        $order = $this->order();
        $this->task($order, 'delivery');
        $this->assertSame(34800, $order->fresh()->totalDue());
        $this->pay($order, 22500);
        $this->assertSame(12300, $order->fresh()->balance());
        $this->post(route('admin.laundry.documents.invoice', $order), ['revision' => $order->fresh()->revision])->assertRedirect();
        $snapshot = $order->documents()->where('type', 'invoice')->sole()->snapshot;
        $this->post(route('admin.laundry.transport.waive', $order), ['revision' => $order->fresh()->revision, 'kind' => 'delivery', 'approved_name' => 'Pelanggan', 'reason' => 'Uji perubahan terlarang.', 'confirmed' => 1])->assertSessionHasErrors('transport');
        $this->assertSame($snapshot, $order->documents()->where('type', 'invoice')->sole()->snapshot);
        $this->get(route('admin.laundry.orders.show', $order))->assertOk()->assertSee('Rp 34.800')->assertSee('Rp 12.300');
    }

    public function test_delivery_requires_qc_full_payment_and_completes_handover_atomically(): void
    {
        $order = $this->order();
        $task = $this->task($order, 'delivery');
        $this->action($task, 'start')->assertSessionHasErrors('transport');
        $this->ready($order);
        $this->pay($order, 22500);
        $this->action($task, 'start')->assertSessionHasErrors('transport');
        $this->pay($order);
        $items = $order->items()->get()->map(fn ($item) => ['id' => $item->id, 'returned_pieces' => 12, 'label_confirmed' => 1])->all();
        $data = ['receiver_name' => 'Penerima Uji', 'confirmed' => 1, 'items' => $items];
        $this->post(route('admin.laundry.orders.handover', $order), $data + ['revision' => $order->fresh()->revision])->assertSessionHasErrors('laundry');
        $this->action($task, 'start')->assertSessionHasNoErrors();
        $bad = $data;
        $bad['items'][0]['returned_pieces'] = 1;
        $this->action($task, 'complete', $bad)->assertSessionHasErrors('items');
        $this->assertSame('in_transit', $task->fresh()->status);
        $this->assertNull($order->fresh()->handed_over_at);
        $this->action($task, 'complete', $data + ['proof' => UploadedFile::fake()->image('antar.png')])->assertSessionHasNoErrors();
        $this->assertSame('completed', $task->fresh()->status);
        $this->assertSame('handed_over', $order->fresh()->workflow_status);
        $this->assertSame(0, $order->fresh()->balance());
        $this->assertSame(1, $order->events()->where('kind', 'handed_over')->count());
        $this->action($task, 'complete', $data)->assertSessionHasErrors('transport');
        $this->get(route('admin.laundry.orders.receipt', $order))->assertOk()->assertSee('BUKTI SERAH TERIMA');
    }

    public function test_task_stale_revision_and_inactive_courier_cannot_mutate_schedule(): void
    {
        $task = $this->task();
        $revision = $task->revision;
        $this->action($task, 'start')->assertSessionHasNoErrors();
        $this->action($task, 'fail', ['revision' => $revision])->assertSessionHasErrors('transport');
        $this->courier->update(['is_active' => false]);
        $this->post(route('admin.laundry.transport.preview'), $this->taskData(null, 'pickup', ['scheduled_start' => '2026-10-31T12:00', 'scheduled_end' => '2026-10-31T13:00']))->assertSessionHasErrors('laundry_courier_id');
        $this->get(route('admin.laundry.transport.index'))->assertOk();
        $this->get(route('admin.laundry.transport.create'))->assertOk();
        $this->get(route('admin.laundry.transport.show', $task))->assertOk();
    }

    public function test_transport_filters_dates_and_demo_and_settings_validate_numbers_and_unique_couriers(): void
    {
        $task = $this->task();
        $this->task(null, 'pickup', ['is_demo' => 1, 'scheduled_start' => '2026-10-31T12:00', 'scheduled_end' => '2026-10-31T13:00']);
        $this->get(route('admin.laundry.transport.index', ['start_date' => '2026-10-31', 'end_date' => '2026-10-31']))->assertOk()->assertViewHas('tasks', fn ($rows) => $rows->total() === 1);
        $this->get(route('admin.laundry.transport.index', ['include_demo' => 1]))->assertViewHas('tasks', fn ($rows) => $rows->total() === 2);
        $this->put(route('admin.laundry.transport.settings.update'), array_replace($this->pricing(), ['pickup_rate_per_km' => -1]))->assertSessionHasErrors('pickup_rate_per_km');
        $this->post(route('admin.laundry.transport.couriers.store'), ['name' => 'petugas uji'])->assertSessionHasErrors('name');
        $this->post(route('admin.laundry.transport.couriers.store'), ['name' => 'Petugas Kedua', 'phone' => '081234567890'])->assertSessionHasNoErrors();
        $this->post(route('admin.laundry.transport.couriers.update', $this->courier), ['is_active' => 0])->assertSessionHasNoErrors();
        $this->assertFalse($this->courier->fresh()->is_active);
    }

    public function test_notifications_are_off_by_default_and_simulation_deduplicates_without_jobs(): void
    {
        $order = $this->order();
        $service = app(LaundryNotificationService::class);
        $this->assertNull($service->captureOrder($order, 'received', []));
        $this->notificationSettings();
        $row = $service->captureOrder($order, 'received', []);
        $service->captureOrder($order, 'received', []);
        $this->assertSame(1, LaundryNotification::count());
        $this->assertSame('simulated', $row->status);
        $this->assertStringContainsString($order->code, $row->content);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->get(route('admin.laundry.notifications.index'))->assertOk()->assertSee('Simulasi')->assertSee($order->code);
    }

    public function test_live_job_sends_once_through_existing_gateway_and_records_inbox(): void
    {
        $this->notificationSettings(['dry_run' => false]);
        $service = app(LaundryNotificationService::class);
        $row = $service->captureOrder($this->order(), 'received', []);
        $this->assertSame('queued', $row->status);
        Queue::assertPushedOn('laundry', SendLaundryNotificationJob::class);
        $gateway = Mockery::mock(WhatsAppGateway::class);
        $gateway->shouldReceive('sendText')->once()->with('laundry-test', '6280000018001@c.us', Mockery::type('string'))->andReturn(['id' => 'WA-LAUNDRY-UJI-1']);
        $job = new SendLaundryNotificationJob($row->id);
        $job->handle($gateway, $service);
        $job->handle($gateway, $service);
        $this->assertSame('sent', $row->fresh()->status);
        $this->assertSame(1, $row->fresh()->attempts);
        $this->assertSame(1, Message::where('wa_message_id', 'WA-LAUNDRY-UJI-1')->count());
        $this->assertSame(1, Conversation::count());
        Http::assertNothingSent();
    }

    public function test_gateway_uncertainty_is_not_automatically_retried_and_requires_explicit_review(): void
    {
        $this->notificationSettings(['dry_run' => false]);
        $service = app(LaundryNotificationService::class);
        $row = $service->captureOrder($this->order(), 'received', []);
        $gateway = Mockery::mock(WhatsAppGateway::class);
        $gateway->shouldReceive('sendText')->once()->andThrow(new \RuntimeException('SECRET-KEY-NOT-FOR-LOGS'));
        $job = new SendLaundryNotificationJob($row->id);
        $job->handle($gateway, $service);
        $job->handle($gateway, $service);
        $this->assertSame('unknown', $row->fresh()->status);
        $this->assertStringNotContainsString('SECRET', $row->fresh()->failure_reason);
        $this->post(route('admin.laundry.notifications.retry', $row), ['reason' => 'Sudah diperiksa di WhatsApp.'])->assertSessionHasErrors('confirmed');
        $this->post(route('admin.laundry.notifications.retry', $row), ['confirmed' => 1, 'reason' => 'Sudah diperiksa dan belum terkirim.'])->assertSessionHasNoErrors();
        $this->assertSame('queued', $row->fresh()->status);
    }

    public function test_changed_number_demo_inactive_customer_and_foreign_inbox_never_receive_live_messages(): void
    {
        $this->notificationSettings(['dry_run' => false]);
        $service = app(LaundryNotificationService::class);
        $order = $this->order();
        $row = $service->captureOrder($order, 'received', []);
        $this->customer->update(['whatsapp_number' => '6280000018002']);
        $gateway = Mockery::mock(WhatsAppGateway::class);
        $gateway->shouldNotReceive('sendText');
        (new SendLaundryNotificationJob($row->id))->handle($gateway, $service);
        $this->assertSame('skipped', $row->fresh()->status);
        $order->update(['is_demo' => true]);
        $this->assertSame('skipped', $service->captureOrder($order->fresh(), 'weighed', [])->status);
        $this->customer->update(['whatsapp_number' => '6280000018001', 'status' => 'inactive']);
        $order->update(['is_demo' => false, 'revision' => 2]);
        $this->assertSame('skipped', $service->captureOrder($order->fresh(), 'received', [])->status);
        $this->customer->update(['status' => 'lead']);
        $order->increment('revision');
        $row = $service->captureOrder($order->fresh(), 'received', []);
        $foreign = Customer::create(['name' => 'Pemilik inbox lain', 'whatsapp_number' => '6280000099999', 'status' => 'lead']);
        Conversation::create(['customer_id' => $foreign->id, 'wa_chat_id' => '6280000018001@c.us', 'channel' => 'whatsapp', 'status' => 'open']);
        (new SendLaundryNotificationJob($row->id))->handle($gateway, $service);
        $this->assertSame('skipped', $row->fresh()->status);
    }

    public function test_ready_payment_and_balance_messages_are_revalidated_before_sending(): void
    {
        $this->notificationSettings(['dry_run' => false]);
        $service = app(LaundryNotificationService::class);
        $order = $this->order();
        $this->ready($order);
        $row = $service->captureOrder($order->fresh(), 'quality_passed', []);
        $order->update(['workflow_status' => 'handed_over']);
        $gateway = Mockery::mock(WhatsAppGateway::class);
        $gateway->shouldNotReceive('sendText');
        (new SendLaundryNotificationJob($row->id))->handle($gateway, $service);
        $this->assertSame('skipped', $row->fresh()->status);
        $order->update(['workflow_status' => 'ready']);
        $this->pay($order, 10000);
        $payment = $order->payments()->sole();
        $notice = LaundryNotification::where('kind', 'payment')->sole();
        $this->post(route('admin.laundry.payments.void', [$order, $payment]), ['revision' => $order->fresh()->revision, 'reason' => 'Koreksi pencatatan uji.', 'confirmed' => 1])->assertSessionHasNoErrors();
        (new SendLaundryNotificationJob($notice->id))->handle($gateway, $service);
        $this->assertSame('skipped', $notice->fresh()->status);
    }

    public function test_reminders_obey_daily_dedup_maximum_balance_and_off_switch(): void
    {
        $order = $this->order();
        $order->update(['received_at' => now()->subDays(3)]);
        $this->notificationSettings(['max_reminders' => 2]);
        $service = app(LaundryNotificationService::class);
        $this->assertSame(1, $service->reminders());
        $this->assertSame(0, $service->reminders());
        $this->travel(1)->days();
        $this->assertSame(1, $service->reminders());
        $this->travel(1)->days();
        $this->assertSame(0, $service->reminders());
        $this->pay($order);
        $this->assertSame(0, $service->reminders());
        $this->notificationSettings(['enabled' => false]);
        $this->artisan('crm:send-laundry-reminders')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_rescheduled_notifications_skip_old_schedule_and_disabled_live_mode_cannot_send(): void
    {
        $this->notificationSettings(['dry_run' => false]);
        $task = $this->task();
        $old = LaundryNotification::where('kind', 'task_scheduled')->sole();
        $this->post(route('admin.laundry.transport.reschedule', $task), ['revision' => $task->revision, 'scheduled_start' => '2026-10-31T13:00', 'scheduled_end' => '2026-10-31T14:00', 'laundry_courier_id' => $this->courier->id, 'reason' => 'Waktu diubah pelanggan.'])->assertSessionHasNoErrors();
        $service = app(LaundryNotificationService::class);
        $gateway = Mockery::mock(WhatsAppGateway::class);
        $gateway->shouldNotReceive('sendText');
        (new SendLaundryNotificationJob($old->id))->handle($gateway, $service);
        $this->assertSame('skipped', $old->fresh()->status);
        $this->notificationSettings(['dry_run' => true]);
        $new = LaundryNotification::latest('id')->first();
        (new SendLaundryNotificationJob($new->id))->handle($gateway, $service);
        $this->assertSame('skipped', $new->fresh()->status);
    }

    public function test_notification_settings_validate_templates_and_require_live_acknowledgement(): void
    {
        $data = $this->notificationSettings(['enabled' => false]);
        $data['enabled'] = 1;
        $data['dry_run'] = 0;
        $this->put(route('admin.laundry.notifications.update'), $data)->assertSessionHasErrors('live_confirmed');
        $data['live_confirmed'] = 1;
        $data['templates']['received'] = 'Halo {rahasia_tidak_ada}';
        $this->put(route('admin.laundry.notifications.update'), $data)->assertSessionHasErrors('templates');
        $data['templates']['received'] = 'Halo {nama}, laundry {kode} diterima.';
        $this->put(route('admin.laundry.notifications.update'), $data)->assertSessionHasNoErrors();
        $this->assertFalse(app(LaundryNotificationService::class)->settings()['dry_run']);
    }

    public function test_logistics_and_notifications_routes_require_admin_roles(): void
    {
        $task = $this->task();
        $order = $this->order();
        $this->notificationSettings();
        $row = app(LaundryNotificationService::class)->captureOrder($order, 'received', []);
        foreach (['sales', 'therapist'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'active']));
            foreach (['transport.index', 'transport.create', 'transport.settings', 'notifications.index'] as $route) {
                $this->get(route('admin.laundry.'.$route))->assertForbidden();
            }
            $this->get(route('admin.laundry.transport.show', $task))->assertForbidden();
            $this->get(route('admin.laundry.transport.proof', $task))->assertForbidden();
            foreach (['preview', 'store'] as $action) {
                $this->post(route('admin.laundry.transport.'.$action), [])->assertForbidden();
            }
            foreach (['reschedule', 'action', 'attach'] as $action) {
                $this->post(route('admin.laundry.transport.'.$action, $task), [])->assertForbidden();
            }
            $this->post(route('admin.laundry.transport.waive', $order), [])->assertForbidden();
            $this->post(route('admin.laundry.notifications.retry', $row), [])->assertForbidden();
            $this->put(route('admin.laundry.notifications.update'), [])->assertForbidden();
            $this->put(route('admin.laundry.transport.settings.update'), [])->assertForbidden();
        }
        $this->app['auth']->guard('web')->logout();
        $this->get(route('admin.laundry.transport.index'))->assertRedirect(route('login'));
    }

    public function test_quiet_hours_delay_sending_and_gateway_without_message_id_is_unknown(): void
    {
        $this->notificationSettings(['dry_run' => false, 'start_hour' => 11]);
        $service = app(LaundryNotificationService::class);
        $row = $service->captureOrder($this->order(), 'received', []);
        $gateway = Mockery::mock(WhatsAppGateway::class);
        $gateway->shouldNotReceive('sendText');
        $job = (new SendLaundryNotificationJob($row->id))->withFakeQueueInteractions();
        $job->handle($gateway, $service);
        $job->assertReleased(3600);
        $this->assertSame('queued', $row->fresh()->status);
        $this->assertSame(0, $row->fresh()->attempts);
        $this->travel(1)->hours();
        $gateway = Mockery::mock(WhatsAppGateway::class);
        $gateway->shouldReceive('sendText')->once()->andReturn([]);
        (new SendLaundryNotificationJob($row->id))->handle($gateway, $service);
        $this->assertSame('unknown', $row->fresh()->status);
    }

    public function test_gateway_rejection_is_failed_and_simulations_do_not_consume_live_reminder_limit(): void
    {
        $this->notificationSettings(['max_reminders' => 1]);
        $order = $this->order();
        $order->update(['received_at' => now()->subDays(3)]);
        $service = app(LaundryNotificationService::class);
        $this->assertSame(1, $service->reminders());
        $this->notificationSettings(['dry_run' => false, 'max_reminders' => 1]);
        $this->travel(1)->days();
        $this->assertSame(1, $service->reminders());
        $row = LaundryNotification::latest('id')->first();
        $response = new \Illuminate\Http\Client\Response(new Response(401, [], 'SECRET-MUST-NOT-LEAK'));
        $gateway = Mockery::mock(WhatsAppGateway::class);
        $gateway->shouldReceive('sendText')->once()->andThrow(new RequestException($response));
        (new SendLaundryNotificationJob($row->id))->handle($gateway, $service);
        $this->assertSame('failed', $row->fresh()->status);
        $this->assertStringNotContainsString('SECRET', $row->fresh()->failure_reason);
        $this->travel(1)->days();
        $this->assertSame(0, $service->reminders());
    }

    public function test_delivery_failure_rolls_back_handover_outbox_and_uploaded_proof(): void
    {
        $this->notificationSettings();
        $order = $this->order();
        $task = $this->task($order, 'delivery');
        $this->ready($order);
        $this->pay($order);
        $this->action($task, 'start')->assertSessionHasNoErrors();
        Event::listen('eloquent.updated: '.LaundryTransportTask::class, function ($model) {
            if ($model->status === 'completed') {
                throw new \RuntimeException('Simulasi gagal menyimpan kunjungan.');
            }
        });
        try {
            app(LaundryTransportService::class)->action($task->fresh(), ['revision' => $task->fresh()->revision, 'action' => 'complete', 'receiver_name' => 'Penerima Uji', 'confirmed' => 1, 'notes' => 'Seluruh barang diperiksa.', 'items' => [['id' => $order->items()->sole()->id, 'returned_pieces' => 12, 'label_confirmed' => 1]]], UploadedFile::fake()->image('bukti.png'), $this->admin, Request::create('/'));
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulasi gagal menyimpan kunjungan.', $exception->getMessage());
        }
        $this->assertSame('ready', $order->fresh()->workflow_status);
        $this->assertSame('in_transit', $task->fresh()->status);
        $this->assertSame(0, $order->events()->where('kind', 'handed_over')->count());
        $this->assertSame(0, LaundryNotification::where('kind', 'handed_over')->count());
        $this->assertSame([], Storage::disk('local')->allFiles('laundry/transport-proofs'));
    }

    public function test_pickup_link_rejects_another_customer_without_changing_either_bill(): void
    {
        $task = $this->task();
        $this->action($task, 'start')->assertSessionHasNoErrors();
        $this->action($task, 'complete', ['receiver_name' => 'Outlet Uji', 'pieces' => 12, 'confirmed' => 1])->assertSessionHasNoErrors();
        $foreign = Customer::create(['name' => 'Pelanggan berbeda', 'whatsapp_number' => '6280000018999', 'status' => 'lead']);
        $order = $this->order('collection');
        $order->update(['customer_id' => $foreign->id]);
        $this->post(route('admin.laundry.transport.attach', $task), ['revision' => $task->fresh()->revision, 'order_revision' => $order->revision, 'laundry_order_id' => $order->id, 'confirmed' => 1])->assertSessionHasErrors('transport');
        $this->assertNull($task->fresh()->laundry_order_id);
        $this->assertSame(0, $order->fresh()->pickup_fee);
    }

    public function test_oversized_template_output_is_logged_as_skipped_without_dispatching(): void
    {
        $settings = $this->notificationSettings();
        $settings['templates']['task_scheduled'] = str_repeat('{alamat}', 10);
        $this->notificationSettings($settings);
        $this->task(null, 'pickup', ['address' => str_repeat('a', 1500)]);
        $row = LaundryNotification::sole();
        $this->assertSame('skipped', $row->status);
        $this->assertSame(6001, mb_strlen($row->content));
        $this->assertStringContainsString('terlalu panjang', $row->failure_reason);
        Queue::assertNothingPushed();
    }
}
