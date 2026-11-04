<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\RentalCareRecord;
use App\Models\RentalOrder;
use App\Models\RentalProduct;
use App\Models\RentalUnit;
use App\Models\User;
use App\Services\CRM\RentalInventory;
use App\Services\CRM\RentalOperations;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class RentalCareTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private RentalProduct $product;

    private RentalUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-27 09:00:00', 'Asia/Jakarta'));
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($this->admin);
        $this->customer = Customer::create(['name' => 'Care Customer', 'whatsapp_number' => '6281234567890', 'status' => 'lead']);
        $this->product = RentalProduct::create(['sku' => 'CARE', 'name' => 'Stroller Care', 'category' => 'Stroller', 'daily_rate' => 25000, 'weekly_rate' => 140000, 'deposit' => 100000, 'turnaround_days' => 1, 'is_active' => true, 'inspection_checklist' => ['Sabuk lengkap', 'Pengunci berfungsi', 'Bersih dan kering']]);
        $this->unit = RentalUnit::create(['rental_product_id' => $this->product->id, 'code' => 'CARE-001', 'status' => 'ready', 'condition_notes' => 'Baik, lengkap', 'care_version' => 0]);
    }

    private function order(): RentalOrder
    {
        $data = ['customer_id' => $this->customer->id, 'items' => [['product_id' => $this->product->id, 'quantity' => 1]], 'start_date' => '2026-10-27', 'end_date' => '2026-10-29', 'period' => 'day', 'fulfilment' => 'self'];
        $data['quote_token'] = $this->postJson(route('rental.quote'), $data)->assertOk()->json('token');
        $this->post(route('admin.rentals.orders.store'), $data)->assertSessionHasNoErrors();
        $order = RentalOrder::latest('id')->firstOrFail();
        $order->update(['terms_accepted_at' => now(), 'terms_acceptance_source' => 'portal']);
        $this->post(route('admin.rentals.orders.action', $order), ['action' => 'confirm'])->assertSessionHasNoErrors();

        return $order->refresh();
    }

    private function checkoutData(array $overrides = []): array
    {
        return array_replace(['entry_key' => (string) Str::uuid(), 'care_version' => $this->unit->fresh()->care_version, 'notes' => 'Kondisi baik dan kelengkapan lengkap sebelum keluar', 'checklist' => ['ok', 'ok', 'ok']], $overrides);
    }

    private function careData(array $overrides = []): array
    {
        return array_replace($this->checkoutData(), ['kind' => 'cleaning', 'unit_status' => 'ready', 'cost' => 15000, 'vendor' => 'Petugas internal', 'next_maintenance_at' => '2026-11-10'], $overrides);
    }

    private function dispatch(RentalOrder $order): void
    {
        $this->post(route('admin.rentals.care.checkout', [$order, $order->items()->first()]), $this->checkoutData())->assertSessionHasNoErrors();
        $this->post(route('admin.rentals.orders.action', $order), ['action' => 'dispatch'])->assertSessionHasNoErrors();
        $order->refresh();
    }

    public function test_care_pages_and_configurable_checklist_render(): void
    {
        $this->get(route('admin.rentals.care.index'))->assertOk()->assertSee('CARE-001');
        $this->get(route('admin.rentals.care.show', $this->unit))->assertOk()->assertSee('Sabuk lengkap');
        $this->get(route('admin.rentals.products.edit', $this->product))->assertOk()->assertSee('inspection_checklist_text');
    }

    public function test_full_checkout_is_required_before_dispatch_and_is_snapshotted(): void
    {
        $order = $this->order();
        $item = $order->items()->first();
        $this->post(route('admin.rentals.orders.action', $order), ['action' => 'dispatch'])->assertSessionHasErrors('rental');
        $this->post(route('admin.rentals.care.checkout', [$order, $item]), $this->checkoutData(['checklist' => ['ok']]))->assertSessionHasErrors('rental');
        $this->post(route('admin.rentals.care.checkout', [$order, $item]), $this->checkoutData(['checklist' => ['ok', 'issue', 'ok']]))->assertSessionHasErrors('rental');
        $this->dispatch($order);
        $this->assertSame('rented', $order->status);
        $this->assertSame('Sabuk lengkap', $item->fresh()->checkout_checklist[0]['label']);
        $this->assertSame('Kondisi baik dan kelengkapan lengkap sebelum keluar', $item->fresh()->checkout_condition);
        $this->assertSame(1, RentalCareRecord::where('kind', 'checkout')->count());
    }

    public function test_checkout_photos_are_private_and_duplicate_form_has_no_extra_photo_or_record(): void
    {
        $order = $this->order();
        $item = $order->items()->first();
        $data = $this->checkoutData(['photo' => UploadedFile::fake()->image('before.jpg')]);
        $this->post(route('admin.rentals.care.checkout', [$order, $item]), $data)->assertSessionHasNoErrors();
        $record = RentalCareRecord::first();
        Storage::disk('local')->assertExists($record->photo_path);
        $this->get(route('admin.rentals.care.photo', $record))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $data['photo'] = UploadedFile::fake()->image('retry.jpg');
        $this->post(route('admin.rentals.care.checkout', [$order, $item]), $data)->assertSessionHasNoErrors();
        $this->assertSame(1, RentalCareRecord::count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_changed_unit_invalidates_checkout_and_stale_form_is_rejected(): void
    {
        $order = $this->order();
        $item = $order->items()->first();
        $old = $this->checkoutData();
        $this->post(route('admin.rentals.care.checkout', [$order, $item]), $old)->assertSessionHasNoErrors();
        $this->put(route('admin.rentals.units.update', $this->unit), ['rental_product_id' => $this->product->id, 'code' => $this->unit->code, 'status' => 'ready', 'condition_notes' => 'Kondisi diperbarui setelah pemeriksaan'])->assertSessionHasNoErrors();
        $this->post(route('admin.rentals.orders.action', $order), ['action' => 'dispatch'])->assertSessionHasErrors('rental');
        $this->post(route('admin.rentals.care.checkout', [$order, $item]), array_replace($old, ['entry_key' => (string) Str::uuid()]))->assertSessionHasErrors('rental');
        $this->dispatch($order);
    }

    public function test_replacement_resets_prepared_checkout_without_erasing_original_unit_history(): void
    {
        $order = $this->order();
        $item = $order->items()->first();
        $this->post(route('admin.rentals.care.checkout', [$order, $item]), $this->checkoutData())->assertSessionHasNoErrors();
        $replacement = RentalUnit::create(['rental_product_id' => $this->product->id, 'code' => 'CARE-002', 'status' => 'ready']);
        $this->post(route('admin.rentals.orders.replace', [$order, $item]), ['unit_id' => $replacement->id])->assertSessionHasNoErrors();
        $this->assertNull($item->fresh()->checkout_inspected_at);
        $this->assertSame($this->unit->id, RentalCareRecord::first()->rental_unit_id);
        $this->post(route('admin.rentals.orders.action', $order), ['action' => 'dispatch'])->assertSessionHasErrors('rental');
    }

    public function test_checklist_snapshot_survives_catalogue_change(): void
    {
        $order = $this->order();
        $this->product->update(['inspection_checklist' => ['Daftar baru']]);
        $this->dispatch($order);
        $this->assertCount(3, $order->items()->first()->checkout_checklist);
    }

    public function test_return_inspection_cannot_mark_issue_ready_and_retains_photo_history(): void
    {
        $order = $this->order();
        $this->dispatch($order);
        $item = $order->items()->first();
        $this->post(route('admin.rentals.orders.return', [$order, $item]), ['notes' => 'Kembali dengan pengunci bermasalah', 'photo' => UploadedFile::fake()->image('after.jpg')])->assertSessionHasNoErrors();
        $data = ['notes' => 'Pengunci perlu diperbaiki', 'unit_status' => 'ready', 'late_fee' => 0, 'damage_fee' => 10000, 'checklist' => ['ok', 'issue', 'ok']];
        $this->post(route('admin.rentals.orders.inspect', [$order, $item]), $data)->assertSessionHasErrors('rental');
        $data['unit_status'] = 'maintenance';
        $this->post(route('admin.rentals.orders.inspect', [$order, $item]), $data)->assertSessionHasNoErrors();
        $record = RentalCareRecord::where('kind', 'return_inspection')->firstOrFail();
        $this->assertSame('issue', $record->checklist[1]['result']);
        $this->assertSame($item->fresh()->return_photo_path, $record->photo_path);
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('maintenance', $this->unit->fresh()->status);
        $this->post(route('admin.rentals.care.store', $this->unit), $this->careData(['kind' => 'maintenance']))->assertSessionHasNoErrors();
        $this->assertSame('ready', $this->unit->fresh()->status);
        $this->assertSame(15000, (int) $this->unit->careRecords()->sum('cost'));
        $this->assertEquals(85000, $order->fresh()->charges());
    }

    public function test_physical_holds_and_foreign_order_item_are_rejected(): void
    {
        $order = $this->order();
        $this->dispatch($order);
        $this->post(route('admin.rentals.care.store', $this->unit), $this->careData())->assertSessionHasErrors('rental');
        $other = $order->replicate(['code', 'quote_id']);
        $other->code = 'CARE-FOREIGN';
        $other->quote_id = (string) Str::uuid();
        $other->save();
        $this->post(route('admin.rentals.care.checkout', [$other, $order->items()->first()]), $this->checkoutData())->assertNotFound();
    }

    public function test_care_cost_history_is_idempotent_and_stale_update_cannot_overwrite_it(): void
    {
        $data = $this->careData();
        $this->post(route('admin.rentals.care.store', $this->unit), $data)->assertSessionHasNoErrors();
        $this->post(route('admin.rentals.care.store', $this->unit), $data)->assertSessionHasNoErrors();
        $this->assertSame(1, $this->unit->careRecords()->count());
        $this->post(route('admin.rentals.care.store', $this->unit), array_replace($data, ['entry_key' => (string) Str::uuid()]))->assertSessionHasErrors('rental');
        $this->assertSame(1, $this->unit->careRecords()->count());
        $this->assertDatabaseCount('rental_payments', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'rental.care.cleaning']);
    }

    public function test_maintenance_date_blocks_new_allocation_and_appears_in_operations(): void
    {
        $this->unit->update(['next_maintenance_at' => '2026-10-29']);
        $inventory = app(RentalInventory::class);
        $this->assertSame(1, $inventory->available($this->product, '2026-10-27', '2026-10-28')->count());
        $this->assertSame(0, $inventory->available($this->product, '2026-10-27', '2026-10-29')->count());
        $this->get(route('admin.rentals.operations'))->assertOk()->assertSee('Jadwal perawatan unit');
        $this->assertTrue(app(RentalOperations::class)->alerts()->contains('type', 'Jadwal perawatan unit'));
    }

    public function test_overdue_maintenance_prevents_dispatch_and_is_cleared_by_actual_care(): void
    {
        $order = $this->order();
        $this->post(route('admin.rentals.care.checkout', [$order, $order->items()->first()]), $this->checkoutData())->assertSessionHasNoErrors();
        $this->unit->update(['next_maintenance_at' => '2026-10-27']);
        $this->post(route('admin.rentals.orders.action', $order), ['action' => 'dispatch'])->assertSessionHasErrors('rental');
        $this->post(route('admin.rentals.care.store', $this->unit), $this->careData(['kind' => 'maintenance', 'next_maintenance_at' => null]))->assertSessionHasNoErrors();
        $this->dispatch($order);
        $this->assertFalse($this->unit->fresh()->maintenanceDue());
    }

    public function test_maintenance_added_after_confirmation_cannot_fall_inside_the_contract(): void
    {
        $order = $this->order();
        $item = $order->items()->first();
        $this->unit->update(['next_maintenance_at' => '2026-10-28']);
        $this->post(route('admin.rentals.care.checkout', [$order, $item]), $this->checkoutData())->assertSessionHasErrors('rental');
        $this->post(route('admin.rentals.orders.action', $order), ['action' => 'dispatch'])->assertSessionHasErrors('rental');
        $this->assertSame('reserved', $order->fresh()->status);
        $this->assertTrue(app(RentalOperations::class)->alerts($order)->contains('type', 'Perawatan berbenturan dengan sewa'));
    }

    public function test_invalid_checklists_cost_dates_photos_and_ready_bypass_are_rejected(): void
    {
        $this->post(route('admin.rentals.care.store', $this->unit), $this->careData(['cost' => -1]))->assertSessionHasErrors('cost');
        $this->post(route('admin.rentals.care.store', $this->unit), $this->careData(['next_maintenance_at' => '2026-10-27']))->assertSessionHasErrors('next_maintenance_at');
        $this->post(route('admin.rentals.care.store', $this->unit), $this->careData(['photo' => UploadedFile::fake()->create('bad.svg', 1, 'image/svg+xml')]))->assertSessionHasErrors('photo');
        $this->post(route('admin.rentals.care.store', $this->unit), $this->careData(['checklist' => ['ok', 'issue', 'ok']]))->assertSessionHasErrors('rental');
        $this->unit->update(['status' => 'cleaning']);
        $this->put(route('admin.rentals.units.update', $this->unit), ['rental_product_id' => $this->product->id, 'code' => $this->unit->code, 'status' => 'ready', 'condition_notes' => 'Bypass checklist'])->assertSessionHasErrors('rental');
        $this->assertSame('cleaning', $this->unit->fresh()->status);
    }

    public function test_care_history_access_is_restricted_to_rental_admin_roles(): void
    {
        $this->post(route('admin.rentals.care.store', $this->unit), $this->careData(['photo' => UploadedFile::fake()->image('care.jpg')]))->assertSessionHasNoErrors();
        $record = RentalCareRecord::firstOrFail();
        foreach (['sales', 'therapist'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'active']));
            $this->get(route('admin.rentals.care.index'))->assertForbidden();
            $this->get(route('admin.rentals.care.show', $this->unit))->assertForbidden();
            $this->get(route('admin.rentals.care.photo', $record))->assertForbidden();
            $this->post(route('admin.rentals.care.store', $this->unit), $this->careData())->assertForbidden();
        }
        auth()->logout();
        $this->get(route('admin.rentals.care.photo', $record))->assertRedirect(route('login'));
    }
}
