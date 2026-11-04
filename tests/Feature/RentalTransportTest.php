<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\RentalItem;
use App\Models\RentalOrder;
use App\Models\RentalProduct;
use App\Models\RentalTransportTask;
use App\Models\RentalUnit;
use App\Models\User;
use App\Services\CRM\RentalTransportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class RentalTransportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private RentalProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-27 09:00:00', 'Asia/Jakarta'));
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('local');
        Storage::fake('public');
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($this->admin);
        $this->customer = Customer::create(['name' => 'Pelanggan Logistik', 'whatsapp_number' => '6281234000789', 'status' => 'lead']);
        $this->product = RentalProduct::create(['sku' => 'TRANSPORT', 'name' => 'Stroller Logistik', 'category' => 'Stroller', 'daily_rate' => 25000, 'deposit' => 100000, 'is_active' => true]);
    }

    private function order(array $overrides = []): RentalOrder
    {
        $order = RentalOrder::create(array_replace([
            'code' => 'TRANS-'.Str::upper(Str::random(10)), 'quote_id' => (string) Str::uuid(),
            'request_session_hash' => hash('sha256', (string) Str::uuid()), 'customer_id' => $this->customer->id,
            'created_by' => $this->admin->id, 'source' => 'admin', 'status' => 'reserved',
            'start_date' => '2026-10-27', 'end_date' => '2026-10-29', 'period' => 'day', 'period_count' => 3,
            'fulfilment' => 'both', 'address' => 'Jalan Logistik 10', 'rental_subtotal' => 75000,
            'deposit_total' => 100000, 'delivery_fee' => 10000, 'collection_fee' => 10000,
            'pricing_snapshot' => ['total' => 195000, 'route' => null], 'schedule_version' => 1,
        ], $overrides));
        $this->addItem($order);

        return $order;
    }

    private function addItem(RentalOrder $order): RentalItem
    {
        $unit = RentalUnit::create(['rental_product_id' => $this->product->id, 'code' => 'TRANS-'.Str::upper(Str::random(12)), 'status' => 'ready']);

        return $order->items()->create([
            'rental_unit_id' => $unit->id, 'product_name' => $this->product->name, 'unit_code' => $unit->code,
            'rental_price' => 75000, 'deposit' => 100000, 'rental_end_date' => $order->end_date,
            'reserved_until' => $order->end_date->copy()->addDays(2),
        ]);
    }

    private function scheduleData(array $overrides = []): array
    {
        return array_replace([
            'type' => 'delivery', 'assigned_user_id' => $this->admin->id,
            'scheduled_start' => '2026-10-27T10:00', 'scheduled_end' => '2026-10-27T11:00',
            'notes' => 'Hubungi pelanggan saat tiba.', 'version' => 0, 'entry_key' => (string) Str::uuid(),
        ], $overrides);
    }

    private function task(RentalOrder $order, array $overrides = []): RentalTransportTask
    {
        return app(RentalTransportService::class)->schedule($order, $this->scheduleData($overrides), $this->admin);
    }

    private function mutation(RentalTransportTask $task, array $overrides = []): array
    {
        return array_replace(['version' => $task->fresh()->version, 'entry_key' => (string) Str::uuid()], $overrides);
    }

    private function action(RentalOrder $order, RentalTransportTask $task, string $action, array $overrides = [])
    {
        return $this->postJson(route('admin.rentals.transport.action', [$order, $task]), $this->mutation($task, ['action' => $action] + $overrides));
    }

    private function updateData(RentalTransportTask $task, array $overrides = []): array
    {
        return $this->scheduleData(array_replace([
            'type' => $task->type, 'assigned_user_id' => $task->assigned_user_id, 'version' => $task->fresh()->version,
            'scheduled_start' => $task->scheduled_start->setTimezone('Asia/Jakarta')->format('Y-m-d\TH:i'),
            'scheduled_end' => $task->scheduled_end->setTimezone('Asia/Jakarta')->format('Y-m-d\TH:i'),
        ], $overrides));
    }

    public function test_only_one_task_per_order_type_and_identical_retries_are_idempotent(): void
    {
        $order = $this->order();
        $data = $this->scheduleData();
        $url = route('admin.rentals.transport.store', $order);
        $this->postJson($url, $data)->assertRedirect();
        $this->postJson($url, $data)->assertRedirect();
        $task = RentalTransportTask::sole();
        $this->assertSame(1, $task->version);
        $this->postJson($url, array_replace($data, ['notes' => 'Data berbeda']))->assertUnprocessable()->assertJsonValidationErrors('entry_key');
        $this->postJson($url, $this->scheduleData())->assertUnprocessable()->assertJsonValidationErrors('type');
        $collection = $this->task($order, ['type' => 'collection', 'scheduled_start' => '2026-10-29T10:00', 'scheduled_end' => '2026-10-29T11:00']);
        $this->assertSame('collection', $collection->type);
        $this->assertDatabaseCount('rental_transport_tasks', 2);
    }

    public function test_overlapping_assignment_is_rejected_but_adjacent_windows_and_other_staff_are_allowed(): void
    {
        $this->task($this->order());
        $second = $this->order();
        $url = route('admin.rentals.transport.store', $second);
        foreach ([['10:30', '11:30'], ['09:30', '10:30'], ['10:00', '11:00'], ['09:00', '12:00']] as [$start, $end]) {
            $this->postJson($url, $this->scheduleData(['scheduled_start' => '2026-10-27T'.$start, 'scheduled_end' => '2026-10-27T'.$end]))->assertUnprocessable()->assertJsonValidationErrors('assigned_user_id');
        }
        $this->postJson($url, $this->scheduleData(['scheduled_start' => '2026-10-27T11:00', 'scheduled_end' => '2026-10-27T12:00']))->assertRedirect();
        $manager = User::factory()->create(['role' => 'manager', 'status' => 'active']);
        $this->postJson(route('admin.rentals.transport.store', $this->order()), $this->scheduleData(['assigned_user_id' => $manager->id]))->assertRedirect();
        $this->postJson(route('admin.rentals.transport.store', $this->order()), $this->scheduleData(['scheduled_start' => '2026-10-27T09:00', 'scheduled_end' => '2026-10-27T10:00']))->assertRedirect();
    }

    public function test_in_progress_tasks_block_assignments_and_cancellation_releases_staff(): void
    {
        $order = $this->order();
        $task = $this->task($order);
        $this->action($order, $task, 'start')->assertRedirect();
        $other = $this->order();
        $this->postJson(route('admin.rentals.transport.store', $other), $this->scheduleData())->assertUnprocessable()->assertJsonValidationErrors('assigned_user_id');
        $this->action($order, $task, 'cancel', ['notes' => 'Pengantaran dijadwalkan ulang'])->assertRedirect();
        $this->postJson(route('admin.rentals.transport.store', $other), $this->scheduleData())->assertRedirect();
        $this->assertSame('cancelled', $task->fresh()->status);
        $this->assertSame('reserved', $order->fresh()->status);
    }

    public function test_reassignment_checks_conflicts_and_keeps_the_original_on_failure(): void
    {
        $this->task($this->order());
        $manager = User::factory()->create(['role' => 'manager', 'status' => 'active']);
        $order = $this->order();
        $task = $this->task($order, ['assigned_user_id' => $manager->id]);
        $this->putJson(route('admin.rentals.transport.update', [$order, $task]), $this->updateData($task, ['assigned_user_id' => $this->admin->id]))->assertUnprocessable()->assertJsonValidationErrors('assigned_user_id');
        $this->assertSame($manager->id, $task->fresh()->assigned_user_id);
        $this->assertSame(1, $task->fresh()->version);
    }

    public function test_assignee_must_be_an_active_owner_manager_or_admin(): void
    {
        $order = $this->order();
        foreach ([['sales', 'active'], ['therapist', 'active'], ['owner', 'inactive'], ['manager', 'inactive'], ['admin', 'inactive']] as [$role, $status]) {
            $staff = User::factory()->create(compact('role', 'status'));
            $this->postJson(route('admin.rentals.transport.store', $order), $this->scheduleData(['assigned_user_id' => $staff->id]))->assertUnprocessable()->assertJsonValidationErrors('assigned_user_id');
        }
        $this->postJson(route('admin.rentals.transport.store', $order), $this->scheduleData(['assigned_user_id' => 999999]))->assertUnprocessable()->assertJsonValidationErrors('assigned_user_id');
        foreach (['owner', 'manager', 'admin'] as $role) {
            $staff = User::factory()->create(['role' => $role, 'status' => 'active']);
            $this->postJson(route('admin.rentals.transport.store', $this->order()), $this->scheduleData(['assigned_user_id' => $staff->id]))->assertRedirect();
        }
    }

    public function test_routes_reject_sales_therapists_guests_and_inactive_accounts(): void
    {
        $order = $this->order();
        $task = $this->task($order);
        foreach (['sales', 'therapist'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'active']));
            $this->get(route('admin.rentals.transport.index'))->assertForbidden();
            $this->get(route('admin.rentals.transport.create', ['order' => $order, 'type' => 'collection']))->assertForbidden();
            $this->get(route('admin.rentals.transport.edit', [$order, $task]))->assertForbidden();
            $this->get(route('admin.rentals.transport.photo', [$order, $task]))->assertForbidden();
            $this->postJson(route('admin.rentals.transport.store', $order), $this->scheduleData())->assertForbidden();
            $this->putJson(route('admin.rentals.transport.update', [$order, $task]), $this->updateData($task))->assertForbidden();
            $this->action($order, $task, 'start')->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->get(route('admin.rentals.transport.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'inactive']));
        $this->get(route('admin.rentals.transport.index'))->assertRedirect(route('login'));
        $this->assertSame('scheduled', $task->fresh()->status);
    }

    public function test_tasks_and_photos_are_scoped_to_the_order_in_every_nested_endpoint(): void
    {
        $order = $this->order();
        $task = $this->task($order);
        $wrongOrder = $this->order();
        $this->get(route('admin.rentals.transport.edit', [$wrongOrder, $task]))->assertNotFound();
        $this->putJson(route('admin.rentals.transport.update', [$wrongOrder, $task]), $this->updateData($task))->assertNotFound();
        $this->action($wrongOrder, $task, 'start')->assertNotFound();
        $this->get(route('admin.rentals.transport.photo', [$wrongOrder, $task]))->assertNotFound();
        $this->assertSame(1, $task->fresh()->version);
    }

    public function test_pending_expired_cancelled_completed_and_unsupported_orders_cannot_be_scheduled(): void
    {
        foreach ([
            ['status' => 'pending'], ['status' => 'pending', 'hold_expires_at' => now()->subHour()],
            ['status' => 'expired'], ['status' => 'cancelled'], ['status' => 'completed'],
        ] as $overrides) {
            $this->postJson(route('admin.rentals.transport.store', $this->order($overrides)), $this->scheduleData())->assertUnprocessable()->assertJsonValidationErrors('status');
        }
        foreach (['self', 'collection', 'unsupported'] as $fulfilment) {
            $this->postJson(route('admin.rentals.transport.store', $this->order(compact('fulfilment'))), $this->scheduleData())->assertUnprocessable()->assertJsonValidationErrors('type');
        }
        $this->postJson(route('admin.rentals.transport.store', $this->order(['fulfilment' => 'delivery'])), $this->scheduleData(['type' => 'collection', 'scheduled_start' => '2026-10-29T10:00', 'scheduled_end' => '2026-10-29T11:00']))->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->assertDatabaseCount('rental_transport_tasks', 0);
    }

    public function test_delivery_window_is_inside_contract_and_collection_is_on_or_after_its_end_date(): void
    {
        $order = $this->order();
        $url = route('admin.rentals.transport.store', $order);
        foreach ([['2026-10-26T23:59', '2026-10-27T00:30'], ['2026-10-29T23:30', '2026-10-30T00:01']] as [$start, $end]) {
            $this->postJson($url, $this->scheduleData(['scheduled_start' => $start, 'scheduled_end' => $end]))->assertUnprocessable()->assertJsonValidationErrors('scheduled_start');
        }
        $this->postJson($url, $this->scheduleData(['type' => 'collection', 'scheduled_start' => '2026-10-28T23:59', 'scheduled_end' => '2026-10-29T01:00']))->assertUnprocessable()->assertJsonValidationErrors('scheduled_start');
        $this->postJson($url, $this->scheduleData(['scheduled_start' => '2026-10-29T23:00', 'scheduled_end' => '2026-10-30T00:00']))->assertRedirect();
        $this->postJson($url, $this->scheduleData(['type' => 'collection', 'scheduled_start' => '2026-10-30T00:00', 'scheduled_end' => '2026-10-30T01:00']))->assertRedirect();
    }

    public function test_invalid_windows_versions_and_entry_keys_are_rejected(): void
    {
        $order = $this->order();
        $url = route('admin.rentals.transport.store', $order);
        foreach ([['scheduled_end' => '2026-10-27T10:00'], ['scheduled_end' => '2026-10-27T09:00'], ['scheduled_end' => 'not-a-date']] as $invalid) {
            $this->postJson($url, $this->scheduleData($invalid))->assertUnprocessable()->assertJsonValidationErrors('scheduled_end');
        }
        $this->postJson($url, $this->scheduleData(['scheduled_start' => '2026-10-27T10:00:00+08:00']))->assertUnprocessable()->assertJsonValidationErrors('scheduled_start');
        $data = $this->scheduleData();
        unset($data['version'], $data['entry_key']);
        $this->postJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors(['version', 'entry_key']);
    }

    public function test_windows_are_interpreted_in_wib_even_when_the_app_uses_utc(): void
    {
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
        try {
            $order = $this->order();
            $task = $this->task($order, ['scheduled_start' => '2026-10-27T00:00', 'scheduled_end' => '2026-10-27T01:00']);
            $this->assertSame('2026-10-26 17:00', $task->fresh()->scheduled_start->format('Y-m-d H:i'));
            $this->get(route('admin.rentals.transport.index', ['date' => '2026-10-27']))->assertOk()->assertViewHas('tasks', fn ($tasks) => $tasks->contains('id', $task->id));
            $this->get(route('admin.rentals.transport.edit', [$order, $task]))->assertOk()->assertSee('2026-10-27T00:00', false);
        } finally {
            date_default_timezone_set('Asia/Jakarta');
        }
    }

    public function test_stale_edits_and_actions_are_rejected_and_exact_old_retries_do_not_undo_later_changes(): void
    {
        $order = $this->order();
        $original = $this->scheduleData();
        $task = app(RentalTransportService::class)->schedule($order, $original, $this->admin);
        $edit = $this->updateData($task, ['notes' => 'Catatan terbaru']);
        $url = route('admin.rentals.transport.update', [$order, $task]);
        $this->putJson($url, $edit)->assertRedirect();
        $this->putJson($url, $edit)->assertRedirect();
        $this->assertSame(2, $task->fresh()->version);
        $this->putJson($url, array_replace($edit, ['entry_key' => (string) Str::uuid()]))->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->action($order, $task, 'start', ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->action($order, $task, 'start')->assertRedirect();
        $this->postJson(route('admin.rentals.transport.store', $order), $original)->assertRedirect();
        $this->putJson($url, $edit)->assertRedirect();
        $this->assertSame('in_progress', $task->fresh()->status);
        $this->assertSame(3, $task->fresh()->version);
    }

    public function test_schedule_edits_revalidate_the_live_order_and_do_not_change_the_task_type(): void
    {
        $order = $this->order();
        $task = $this->task($order);
        $url = route('admin.rentals.transport.update', [$order, $task]);
        $this->putJson($url, $this->updateData($task, ['type' => 'collection']))->assertUnprocessable()->assertJsonValidationErrors('type');
        $order->update(['status' => 'completed']);
        $this->putJson($url, $this->updateData($task))->assertUnprocessable()->assertJsonValidationErrors('status');
        $order->update(['status' => 'reserved', 'fulfilment' => 'self']);
        $this->putJson($url, $this->updateData($task))->assertUnprocessable()->assertJsonValidationErrors('type');
        $order->update(['fulfilment' => 'delivery', 'start_date' => '2026-10-28']);
        $this->putJson($url, $this->updateData($task))->assertUnprocessable()->assertJsonValidationErrors('scheduled_start');
        $this->assertSame(1, $task->fresh()->version);
    }

    public function test_cancelled_task_can_be_rescheduled_with_a_current_version_and_valid_contract(): void
    {
        $order = $this->order();
        $task = $this->task($order);
        $this->action($order, $task, 'start')->assertRedirect();
        $this->action($order, $task, 'cancel', ['notes' => 'Ganti jadwal'])->assertRedirect();
        $this->putJson(route('admin.rentals.transport.update', [$order, $task]), $this->updateData($task, ['scheduled_start' => '2026-10-28T10:00', 'scheduled_end' => '2026-10-28T11:00']))->assertRedirect();
        $task->refresh();
        $this->assertSame('scheduled', $task->status);
        $this->assertNull($task->cancelled_at);
        $this->assertNull($task->started_at);
        $this->assertSame(4, $task->version);
        $this->assertDatabaseCount('rental_transport_tasks', 1);
    }

    public function test_delivery_completion_requires_start_dispatch_recipient_and_notes_without_mutating_inventory(): void
    {
        $order = $this->order();
        $task = $this->task($order);
        $proof = ['recipient_name' => 'Ibu Penerima', 'notes' => 'Diterima lengkap'];
        $this->action($order, $task, 'complete', $proof)->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->action($order, $task, 'start')->assertRedirect();
        $this->action($order, $task, 'complete')->assertUnprocessable()->assertJsonValidationErrors(['recipient_name', 'notes']);
        $this->action($order, $task, 'complete', $proof)->assertUnprocessable()->assertJsonValidationErrors('status');
        $order->update(['status' => 'rented', 'dispatched_at' => now()]);
        $beforeOrder = $order->fresh()->getAttributes();
        $beforeItem = $order->items()->first()->getAttributes();
        $beforeUnit = $order->items()->first()->unit->getAttributes();
        $data = $this->mutation($task, ['action' => 'complete'] + $proof);
        $url = route('admin.rentals.transport.action', [$order, $task]);
        $this->postJson($url, $data)->assertRedirect();
        $this->postJson($url, $data)->assertRedirect();
        $this->assertSame('completed', $task->fresh()->status);
        $this->assertSame(3, $task->fresh()->version);
        $this->assertSame('Ibu Penerima', $task->fresh()->recipient_name);
        $this->assertSame($beforeOrder, $order->fresh()->getAttributes());
        $this->assertSame($beforeItem, $order->items()->first()->getAttributes());
        $this->assertSame($beforeUnit, $order->items()->first()->unit->getAttributes());
        $this->putJson(route('admin.rentals.transport.update', [$order, $task]), $this->updateData($task))->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->action($order, $task, 'cancel', ['notes' => 'Tidak boleh'])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_collection_completion_requires_every_item_returned_and_allows_core_inspection_to_finish_first(): void
    {
        $order = $this->order(['status' => 'rented', 'dispatched_at' => now()]);
        $this->addItem($order);
        $task = $this->task($order, ['type' => 'collection', 'scheduled_start' => '2026-10-29T10:00', 'scheduled_end' => '2026-10-29T11:00']);
        $this->action($order, $task, 'start')->assertRedirect();
        $proof = ['recipient_name' => 'Admin Penerima', 'notes' => 'Semua unit diterima'];
        $this->action($order, $task, 'complete', $proof)->assertUnprocessable()->assertJsonValidationErrors('status');
        $order->items()->first()->update(['returned_at' => now()]);
        $this->action($order, $task, 'complete', $proof)->assertUnprocessable()->assertJsonValidationErrors('status');
        $order->items()->update(['returned_at' => now(), 'inspected_at' => now()]);
        $order->update(['status' => 'completed', 'completed_at' => now()]);
        $this->action($order, $task, 'complete', $proof)->assertRedirect();
        $this->assertSame('completed', $task->fresh()->status);
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_extension_invalidates_collection_start_and_completion_until_rescheduled(): void
    {
        $order = $this->order(['status' => 'rented', 'dispatched_at' => now()]);
        $task = $this->task($order, ['type' => 'collection', 'scheduled_start' => '2026-10-31T10:00', 'scheduled_end' => '2026-10-31T11:00']);
        // The old window is still after the new end: version changes alone must invalidate it.
        $order->update(['end_date' => '2026-10-30', 'schedule_version' => 2]);
        $this->action($order, $task, 'start')->assertUnprocessable()->assertJsonValidationErrors('schedule_version');
        $url = route('admin.rentals.transport.update', [$order, $task]);
        $this->putJson($url, $this->updateData($task))->assertRedirect();
        $this->assertSame(2, $task->fresh()->schedule_version);
        $this->action($order, $task, 'start')->assertRedirect();
        $order->items()->update(['returned_at' => now()]);
        $order->update(['end_date' => '2026-10-31', 'schedule_version' => 3]);
        $proof = ['recipient_name' => 'Admin', 'notes' => 'Bukti unit kembali'];
        $this->action($order, $task, 'complete', $proof)->assertUnprocessable()->assertJsonValidationErrors('schedule_version');
        $this->putJson($url, $this->updateData($task))->assertRedirect();
        $this->assertSame('in_progress', $task->fresh()->status);
        $this->action($order, $task, 'complete', $proof)->assertRedirect();
    }

    public function test_live_fulfilment_status_and_staff_changes_block_execution(): void
    {
        $order = $this->order();
        $task = $this->task($order);
        $order->update(['status' => 'cancelled']);
        $this->action($order, $task, 'start')->assertUnprocessable()->assertJsonValidationErrors('status');
        $order->update(['status' => 'reserved', 'fulfilment' => 'self']);
        $this->action($order, $task, 'start')->assertUnprocessable()->assertJsonValidationErrors('type');
        $order->update(['fulfilment' => 'both']);
        $manager = User::factory()->create(['role' => 'manager', 'status' => 'active']);
        $this->putJson(route('admin.rentals.transport.update', [$order, $task]), $this->updateData($task, ['assigned_user_id' => $manager->id]))->assertRedirect();
        $manager->update(['status' => 'inactive']);
        $this->action($order, $task, 'start')->assertUnprocessable()->assertJsonValidationErrors('assigned_user_id');
    }

    public function test_completed_order_can_reconcile_an_existing_stale_in_progress_collection_only(): void
    {
        $order = $this->order(['status' => 'rented', 'dispatched_at' => now()]);
        $task = $this->task($order, ['type' => 'collection', 'scheduled_start' => '2026-10-29T10:00', 'scheduled_end' => '2026-10-29T11:00']);
        $this->action($order, $task, 'start')->assertRedirect();
        $order->items()->update(['returned_at' => now(), 'inspected_at' => now()]);
        $order->update(['end_date' => '2026-10-30', 'schedule_version' => 2, 'status' => 'completed', 'completed_at' => now()]);
        $proof = ['recipient_name' => 'Admin', 'notes' => 'Semua unit sudah diterima'];
        $this->action($order, $task, 'complete', $proof)->assertUnprocessable()->assertJsonValidationErrors('schedule_version');
        $url = route('admin.rentals.transport.update', [$order, $task]);
        $this->putJson($url, $this->updateData($task))->assertUnprocessable()->assertJsonValidationErrors('scheduled_start');
        $this->get(route('admin.rentals.transport.edit', [$order, $task]))->assertOk()->assertSee('Simpan Jadwal');
        $this->putJson($url, $this->updateData($task, ['scheduled_start' => '2026-10-30T10:00', 'scheduled_end' => '2026-10-30T11:00']))->assertRedirect();
        $this->assertSame('in_progress', $task->fresh()->status);
        $this->assertSame(2, $task->fresh()->schedule_version);
        $this->action($order, $task, 'complete', $proof)->assertRedirect();
        $this->assertSame('completed', $task->fresh()->status);
        $this->assertSame('completed', $order->fresh()->status);
        $this->postJson(route('admin.rentals.transport.store', $order), $this->scheduleData())->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_existing_pickup_can_be_recorded_after_inspection_finishes_first(): void
    {
        $order = $this->order(['status' => 'rented', 'dispatched_at' => now()]);
        $task = $this->task($order, ['type' => 'collection', 'scheduled_start' => '2026-10-29T10:00', 'scheduled_end' => '2026-10-29T11:00']);
        $order->items()->update(['returned_at' => now(), 'inspected_at' => now()]);
        $order->update(['status' => 'completed', 'completed_at' => now(), 'end_date' => '2026-10-30', 'schedule_version' => 2]);
        $this->get(route('admin.rentals.transport.edit', [$order, $task]))->assertOk()->assertSee('Simpan Jadwal');
        $this->putJson(route('admin.rentals.transport.update', [$order, $task]), $this->updateData($task, ['scheduled_start' => '2026-10-30T10:00', 'scheduled_end' => '2026-10-30T11:00']))->assertRedirect();
        $this->action($order, $task, 'start')->assertRedirect();
        $this->action($order, $task, 'complete', ['recipient_name' => 'Admin penerima', 'notes' => 'Penerimaan barang telah tercatat lebih dahulu'])->assertRedirect();
        $this->assertSame('completed', $task->fresh()->status);
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_order_cancellation_cancels_active_tasks_idempotently_and_preserves_completed_tasks(): void
    {
        $order = $this->order(['status' => 'rented', 'dispatched_at' => now()]);
        $delivery = $this->task($order);
        $collection = $this->task($order, ['type' => 'collection', 'scheduled_start' => '2026-10-29T10:00', 'scheduled_end' => '2026-10-29T11:00']);
        $this->action($order, $delivery, 'start')->assertRedirect();
        $this->action($order, $delivery, 'complete', ['recipient_name' => 'Penerima', 'notes' => 'Diterima'])->assertRedirect();
        $completed = $delivery->fresh()->getAttributes();
        $order->update(['status' => 'cancelled']);
        app(RentalTransportService::class)->cancelForOrder($order);
        app(RentalTransportService::class)->cancelForOrder($order);
        $this->assertSame('cancelled', $collection->fresh()->status);
        $this->assertSame(2, $collection->fresh()->version);
        $this->assertSame($completed, $delivery->fresh()->getAttributes());
    }

    public function test_private_proof_is_stored_locally_and_only_served_through_the_scoped_protected_response(): void
    {
        $order = $this->order(['status' => 'rented', 'dispatched_at' => now()]);
        $task = $this->task($order);
        $this->action($order, $task, 'start')->assertRedirect();
        $photo = UploadedFile::fake()->image('proof.png', 32, 32);
        $data = $this->mutation($task, ['action' => 'complete', 'recipient_name' => 'Penerima', 'notes' => 'Lengkap', 'photo' => $photo]);
        $this->post(route('admin.rentals.transport.action', [$order, $task]), $data)->assertSessionHasNoErrors()->assertRedirect();
        $task->refresh();
        Storage::disk('local')->assertExists($task->proof_photo_path);
        Storage::disk('public')->assertMissing($task->proof_photo_path);
        $this->assertArrayNotHasKey('proof_photo_path', $task->toArray());
        $response = $this->get(route('admin.rentals.transport.photo', [$order, $task]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get(route('admin.rentals.transport.photo', [$this->order(), $task]))->assertNotFound();
        $this->assertCount(1, Storage::disk('local')->allFiles('rental-transport'));
    }

    public function test_proof_rejects_oversize_nonimages_and_svg_without_writing_files(): void
    {
        $order = $this->order(['status' => 'rented', 'dispatched_at' => now()]);
        $task = $this->task($order);
        $this->action($order, $task, 'start')->assertRedirect();
        foreach ([
            UploadedFile::fake()->image('large.jpg')->size(5121),
            UploadedFile::fake()->create('document.pdf', 10, 'application/pdf'),
            UploadedFile::fake()->createWithContent('script.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        ] as $photo) {
            $data = $this->mutation($task, ['action' => 'complete', 'recipient_name' => 'Penerima', 'notes' => 'Lengkap', 'photo' => $photo]);
            $this->postJson(route('admin.rentals.transport.action', [$order, $task]), $data)->assertUnprocessable()->assertJsonValidationErrors('photo');
        }
        $this->assertSame('in_progress', $task->fresh()->status);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_daily_overview_filters_date_status_staff_and_counts_overdue_across_dates(): void
    {
        $selectedOrder = $this->order(['start_date' => '2026-10-26']);
        $selected = $this->task($selectedOrder, ['scheduled_start' => '2026-10-27T07:00', 'scheduled_end' => '2026-10-27T08:00']);
        $this->task($this->order(['start_date' => '2026-10-26']), ['scheduled_start' => '2026-10-26T10:00', 'scheduled_end' => '2026-10-26T11:00']);
        $manager = User::factory()->create(['role' => 'manager', 'status' => 'active']);
        $this->task($this->order(), ['assigned_user_id' => $manager->id]);
        $response = $this->get(route('admin.rentals.transport.index', ['date' => '2026-10-27', 'status' => 'scheduled', 'staff' => $this->admin->id]));
        $response->assertOk()->assertViewHas('overdueCount', 2)->assertViewHas('tasks', fn ($tasks) => $tasks->count() === 1 && $tasks->first()->id === $selected->id);
        $this->get(route('admin.rentals.transport.index', ['date' => '2026-10-27', 'status' => 'completed', 'staff' => $this->admin->id]))->assertOk()->assertViewHas('tasks', fn ($tasks) => $tasks->isEmpty());
        $this->getJson(route('admin.rentals.transport.index', ['date' => 'bad-date']))->assertUnprocessable()->assertJsonValidationErrors('date');
    }

    public function test_alerts_match_operations_shape_and_report_missing_overdue_and_stale_collection(): void
    {
        $order = $this->order();
        $this->task($order, ['scheduled_start' => '2026-10-27T07:00', 'scheduled_end' => '2026-10-27T08:00']);
        $service = app(RentalTransportService::class);
        $alerts = $service->alerts($order);
        $this->assertCount(2, $alerts);
        foreach ($alerts as $alert) {
            $this->assertSame($order->id, $alert['order']->id);
            $this->assertArrayHasKey('unit', $alert);
            $this->assertNull($alert['unit']);
            $this->assertNull($alert['blocking_order']);
            foreach (['type', 'severity', 'message', 'action'] as $key) {
                $this->assertNotEmpty($alert[$key]);
            }
        }
        $this->task($order, ['type' => 'collection', 'scheduled_start' => '2026-10-30T10:00', 'scheduled_end' => '2026-10-30T11:00']);
        $order->update(['end_date' => '2026-10-30', 'schedule_version' => 2]);
        $this->assertTrue($service->alerts($order)->contains('type', 'Jadwal penjemputan kedaluwarsa'));
        $this->order();
        $this->assertTrue($service->alerts($order)->every(fn ($alert) => $alert['order']->id === $order->id));
        $this->assertTrue($service->alerts($this->order(['fulfilment' => 'self']))->isEmpty());
        $this->assertTrue($service->alerts($this->order(['status' => 'pending']))->isEmpty());
    }

    public function test_create_edit_and_order_partial_link_to_standalone_task_pages(): void
    {
        $order = $this->order();
        $create = route('admin.rentals.transport.create', ['order' => $order, 'type' => 'delivery']);
        $this->get($create)->assertOk()->assertSee('datetime-local', false)->assertSee('WIB');
        $this->view('admin.rentals.partials.transport', ['order' => $order])->assertSee($create, false);
        $task = $this->task($order);
        $edit = route('admin.rentals.transport.edit', [$order, $task]);
        $this->get($create)->assertRedirect($edit);
        $this->get($edit)->assertOk()->assertSee('Mulai Perjalanan');
        $this->view('admin.rentals.partials.transport', ['order' => $order, 'transportTasks' => collect([$task])])->assertSee($edit, false);
    }

    public function test_completed_order_alerts_only_report_outstanding_overdue_or_stale_tasks(): void
    {
        $order = $this->order(['status' => 'rented', 'dispatched_at' => now()]);
        $task = $this->task($order, ['type' => 'collection', 'scheduled_start' => '2026-10-29T10:00', 'scheduled_end' => '2026-10-29T11:00']);
        $this->action($order, $task, 'start')->assertRedirect();
        $order->update(['end_date' => '2026-10-30', 'schedule_version' => 2, 'status' => 'completed', 'completed_at' => now()]);
        $this->travelTo(CarbonImmutable::parse('2026-10-30 12:00:00', 'Asia/Jakarta'));
        $alerts = app(RentalTransportService::class)->alerts($order);
        $this->assertCount(2, $alerts);
        $this->assertTrue($alerts->contains('type', 'Jadwal penjemputan kedaluwarsa'));
        $this->assertTrue($alerts->contains('type', 'Angkutan terlambat'));
        $this->assertFalse($alerts->contains(fn ($alert) => str_contains($alert['type'], 'belum tersedia')));
        $this->assertTrue(app(RentalTransportService::class)->alerts($this->order(['status' => 'completed']))->isEmpty());
        $this->action($order, $task, 'cancel', ['notes' => 'Rekonsiliasi terpisah'])->assertRedirect();
        $this->assertTrue(app(RentalTransportService::class)->alerts($order)->isEmpty());
    }
}
