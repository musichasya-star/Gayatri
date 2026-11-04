<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\RentalItem;
use App\Models\RentalOrder;
use App\Models\RentalProduct;
use App\Models\RentalUnit;
use App\Models\User;
use App\Services\CRM\RentalInventory;
use App\Services\CRM\RentalSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class RentalTest extends TestCase
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
        Http::preventStrayRequests();
        Queue::fake();
        Cache::flush();
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($this->admin);
        $this->customer = Customer::create(['name' => 'Rental Customer', 'whatsapp_number' => '6281234567890', 'status' => 'lead']);
        $this->product = RentalProduct::create($this->productData());
        $this->unit = RentalUnit::create(['rental_product_id' => $this->product->id, 'code' => 'ST-001', 'status' => 'ready', 'condition_notes' => 'Baik, bersih, lengkap']);
    }

    private function productData(array $overrides = []): array
    {
        return array_replace(['sku' => 'STROLLER', 'name' => 'Stroller Test', 'category' => 'Stroller', 'description' => 'Alat pengujian', 'components' => 'Kanopi dan tas', 'terms' => 'Periksa bersama petugas', 'daily_rate' => 25000, 'weekly_rate' => 140000, 'monthly_rate' => 500000, 'deposit' => 100000, 'turnaround_days' => 1, 'is_active' => true], $overrides);
    }

    private function data(array $overrides = []): array
    {
        return array_replace(['customer_id' => $this->customer->id, 'items' => [['product_id' => $this->product->id, 'quantity' => 1]], 'start_date' => '2026-10-27', 'end_date' => '2026-10-29', 'period' => 'day', 'fulfilment' => 'self'], $overrides);
    }

    private function quote(array $data): array
    {
        return $this->postJson(route('rental.quote'), $data)->assertOk()->json();
    }

    private function order(array $overrides = []): RentalOrder
    {
        $data = $this->data($overrides);
        $data['quote_token'] = $this->quote($data)['token'];
        $this->post(route('admin.rentals.orders.store'), $data)->assertRedirect()->assertSessionHasNoErrors();

        $order = RentalOrder::latest('id')->firstOrFail();
        $order->update(['terms_accepted_at' => now(), 'terms_acceptance_source' => 'portal']);

        return $order;
    }

    private function action(RentalOrder $order, string $action): void
    {
        $this->post(route('admin.rentals.orders.action', $order), ['action' => $action])->assertRedirect()->assertSessionHasNoErrors();
        $order->refresh();
    }

    private function rented(array $overrides = []): RentalOrder
    {
        $order = $this->order($overrides);
        $this->action($order, 'confirm');
        $this->action($order, 'dispatch');

        return $order;
    }

    private function returned(RentalOrder $order, ?RentalItem $item = null): RentalItem
    {
        $item ??= $order->items()->firstOrFail();
        $this->post(route('admin.rentals.orders.return', [$order, $item]), ['notes' => 'Diterima lengkap, cek kebersihan'])->assertRedirect()->assertSessionHasNoErrors();

        return $item->refresh();
    }

    private function inspect(RentalOrder $order, RentalItem $item, array $overrides = []): void
    {
        $this->post(route('admin.rentals.orders.inspect', [$order, $item]), array_replace(['notes' => 'Sudah bersih dan lengkap', 'unit_status' => 'ready', 'late_fee' => 0, 'damage_fee' => 0, 'late_fee_reason' => 'Penyesuaian sesuai pemeriksaan uji'], $overrides))->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_admin_pages_and_public_catalogue_render(): void
    {
        foreach (['products.index', 'products.create', 'units.index', 'calendar', 'settings.index', 'orders.index', 'orders.create'] as $route) {
            $this->get(route('admin.rentals.'.$route))->assertOk();
        }
        $this->get(route('rental.index'))->assertOk()->assertSee('Stroller Test');
        $this->get(route('rental.show', $this->product))->assertOk()->assertSee('Kanopi dan tas');
    }

    public function test_sales_therapist_and_guests_cannot_manage_inventory_or_transactions(): void
    {
        foreach (['sales', 'therapist'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'active']));
            foreach (['products.index', 'units.index', 'calendar', 'orders.index', 'settings.index'] as $route) {
                $this->get(route('admin.rentals.'.$route))->assertForbidden();
            }
            $this->post(route('admin.rentals.orders.store'), $this->data())->assertForbidden();
        }
        auth()->logout();
        $this->get(route('admin.rentals.orders.index'))->assertRedirect(route('login'));
    }

    public function test_admin_can_create_product_and_unit_and_archive_product(): void
    {
        $this->post(route('admin.rentals.products.store'), $this->productData(['sku' => 'CHAIR', 'name' => 'Kursi Makan']))->assertRedirect()->assertSessionHasNoErrors();
        $product = RentalProduct::where('sku', 'CHAIR')->firstOrFail();
        $this->post(route('admin.rentals.units.store'), ['rental_product_id' => $product->id, 'code' => 'CHAIR-001', 'status' => 'ready', 'condition_notes' => 'Lengkap dan bersih'])->assertRedirect()->assertSessionHasNoErrors();
        $this->put(route('admin.rentals.products.update', $product), $this->productData(['sku' => 'CHAIR', 'name' => 'Kursi Makan', 'is_active' => false]))->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('rental.show', $product))->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['action' => 'rental.product.save']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'rental.unit.save']);
    }

    public function test_duplicate_unit_code_and_negative_prices_are_rejected(): void
    {
        $this->post(route('admin.rentals.units.store'), ['rental_product_id' => $this->product->id, 'code' => 'ST-001', 'status' => 'ready', 'condition_notes' => 'Baik'])->assertSessionHasErrors('code');
        $this->put(route('admin.rentals.products.update', $this->product), $this->productData(['daily_rate' => -1]))->assertSessionHasErrors('daily_rate');
    }

    public function test_quotes_count_inclusive_days_and_round_weekly_and_monthly_blocks(): void
    {
        $q = $this->quote($this->data())['quote'];
        $this->assertSame(3, $q['days']);
        $this->assertSame(75000, $q['rental_subtotal']);
        $this->assertSame(100000, $q['deposit_total']);
        $this->assertSame(175000, $q['total']);
        $this->assertSame(280000, $this->quote($this->data(['end_date' => '2026-11-03', 'period' => 'week']))['quote']['rental_subtotal']);
        $this->assertSame(1000000, $this->quote($this->data(['end_date' => '2026-11-26', 'period' => 'month']))['quote']['rental_subtotal']);
        Http::assertNothingSent();
    }

    public function test_invalid_dates_unsupported_rate_and_duplicate_product_rows_fail(): void
    {
        $this->postJson(route('rental.quote'), $this->data(['start_date' => '2026-10-26']))->assertUnprocessable();
        $this->postJson(route('rental.quote'), $this->data(['end_date' => '2026-10-26']))->assertUnprocessable();
        $this->postJson(route('rental.quote'), $this->data(['items' => [['product_id' => $this->product->id, 'quantity' => 1], ['product_id' => $this->product->id, 'quantity' => 1]]]))->assertUnprocessable();
        $this->product->update(['weekly_rate' => null]);
        $this->postJson(route('rental.quote'), $this->data(['period' => 'week']))->assertUnprocessable();
    }

    public function test_stock_is_allocated_to_physical_unit_and_locks_date_and_cleaning_buffer(): void
    {
        $order = $this->order();
        $this->assertSame('ST-001', $order->items()->first()->unit_code);
        $this->assertSame('2026-10-31', $order->items()->first()->reserved_until->toDateString());
        $this->postJson(route('rental.quote'), $this->data(['start_date' => '2026-10-30', 'end_date' => '2026-10-30']))->assertUnprocessable();
        $this->postJson(route('rental.quote'), $this->data(['start_date' => '2026-10-31', 'end_date' => '2026-10-31']))->assertOk();
    }

    public function test_second_customer_cannot_take_last_unit_after_stale_stock_quote(): void
    {
        $data = $this->data();
        $token = $this->quote($data)['token'];
        $this->order();
        $this->post(route('admin.rentals.orders.store'), array_merge($data, ['quote_token' => $token]))->assertSessionHasErrors('rental');
        $this->assertSame(1, RentalOrder::count());
        $this->assertSame(1, RentalItem::count());
    }

    public function test_duplicate_submission_reuses_order_without_double_allocation(): void
    {
        $data = $this->data();
        $data['quote_token'] = $this->quote($data)['token'];
        $this->post(route('admin.rentals.orders.store'), $data)->assertSessionHasNoErrors();
        $this->post(route('admin.rentals.orders.store'), $data)->assertSessionHasNoErrors();
        $this->assertSame(1, RentalOrder::count());
    }

    public function test_forged_or_expired_quote_cannot_save_order_and_client_totals_are_ignored(): void
    {
        $this->post(route('admin.rentals.orders.store'), $this->data(['quote_token' => 'forged']))->assertSessionHasErrors('rental');
        $data = $this->data();
        $data['quote_token'] = $this->quote($data)['token'];
        $this->travel(16)->minutes();
        $this->post(route('admin.rentals.orders.store'), $data)->assertSessionHasErrors('rental');
        $order = $this->order(['rental_subtotal' => 0, 'deposit_total' => 0]);
        $this->assertEquals(75000, $order->rental_subtotal);
        $this->assertEquals(100000, $order->deposit_total);
    }

    public function test_price_snapshot_survives_catalogue_change(): void
    {
        $data = $this->data();
        $data['quote_token'] = $this->quote($data)['token'];
        $this->product->update(['daily_rate' => 99999, 'deposit' => 999999]);
        $this->post(route('admin.rentals.orders.store'), $data)->assertSessionHasNoErrors();
        $order = RentalOrder::firstOrFail();
        $this->assertEquals(75000, $order->rental_subtotal);
        $this->assertEquals(100000, $order->deposit_total);
        $this->assertEquals(75000, $order->items()->first()->rental_price);
    }

    public function test_disabled_or_maintenance_units_cannot_be_booked(): void
    {
        $this->unit->update(['status' => 'maintenance']);
        $this->postJson(route('rental.quote'), $this->data())->assertUnprocessable();
        $this->unit->update(['status' => 'ready']);
        $this->product->update(['is_active' => false]);
        $this->postJson(route('rental.quote'), $this->data())->assertUnprocessable();
    }

    public function test_expired_pending_hold_releases_stock_and_cannot_be_confirmed(): void
    {
        $order = $this->order();
        $this->travel(25)->hours();
        $this->postJson(route('rental.quote'), $this->data(['start_date' => '2026-10-28']))->assertOk();
        $this->post(route('admin.rentals.orders.action', $order), ['action' => 'confirm'])->assertSessionHasErrors('rental');
    }

    public function test_confirmation_and_handover_enforce_state_and_date(): void
    {
        $order = $this->order(['start_date' => '2026-10-28']);
        $this->post(route('admin.rentals.orders.action', $order), ['action' => 'dispatch'])->assertSessionHasErrors('rental');
        $this->action($order, 'confirm');
        $this->post(route('admin.rentals.orders.action', $order), ['action' => 'dispatch'])->assertSessionHasErrors('rental');
        $this->travel(1)->days();
        $this->action($order, 'dispatch');
        $this->assertSame('rented', $this->unit->refresh()->status);
    }

    public function test_partial_returns_and_inspection_release_only_checked_units(): void
    {
        $other = RentalUnit::create(['rental_product_id' => $this->product->id, 'code' => 'ST-002', 'status' => 'ready']);
        $order = $this->rented(['items' => [['product_id' => $this->product->id, 'quantity' => 2]]]);
        $items = $order->items()->orderBy('id')->get();
        $item = $this->returned($order, $items[0]);
        $this->assertSame('rented', $order->refresh()->status);
        $this->assertSame('inspection', $this->unit->refresh()->status);
        $this->assertSame(0, app(RentalInventory::class)->available($this->product, '2026-11-01', '2026-11-02')->whereKey($this->unit->id)->count());
        $this->inspect($order, $item);
        $this->assertSame(1, app(RentalInventory::class)->available($this->product, '2026-10-27', '2026-10-28')->whereKey($this->unit->id)->count());
        $this->inspect($order, $this->returned($order, $items[1]));
        $this->assertSame('completed', $order->refresh()->status);
    }

    public function test_cannot_return_twice_or_inspect_before_return_or_bypass_via_stock_editor(): void
    {
        $order = $this->rented();
        $item = $order->items()->firstOrFail();
        $this->put(route('admin.rentals.units.update', $this->unit), ['rental_product_id' => $this->product->id, 'code' => $this->unit->code, 'status' => 'ready', 'condition_notes' => 'Bypass'])->assertSessionHasErrors('rental');
        $this->post(route('admin.rentals.orders.inspect', [$order, $item]), ['notes' => 'Belum kembali', 'unit_status' => 'ready', 'late_fee' => 0, 'damage_fee' => 0])->assertSessionHasErrors('rental');
        $this->returned($order, $item);
        $this->post(route('admin.rentals.orders.return', [$order, $item]), ['notes' => 'Duplikat'])->assertSessionHasErrors('rental');
    }

    public function test_cleaning_and_maintenance_after_inspection_remain_unavailable(): void
    {
        $order = $this->rented();
        $item = $this->returned($order);
        $this->inspect($order, $item, ['unit_status' => 'cleaning']);
        $this->postJson(route('rental.quote'), $this->data(['start_date' => '2026-11-02', 'end_date' => '2026-11-03']))->assertUnprocessable();
        $this->put(route('admin.rentals.units.update', $this->unit), ['rental_product_id' => $this->product->id, 'code' => $this->unit->code, 'status' => 'ready', 'condition_notes' => 'Bersih dan siap'])->assertSessionHasNoErrors();
        $this->postJson(route('rental.quote'), $this->data())->assertOk();
    }

    public function test_overdue_unreturned_unit_blocks_new_future_reservation(): void
    {
        $order = $this->rented(['end_date' => '2026-10-27']);
        $this->travel(3)->days();
        $this->assertTrue($order->refresh()->isOverdue());
        $this->postJson(route('rental.quote'), $this->data(['start_date' => '2026-11-10', 'end_date' => '2026-11-12']))->assertUnprocessable();
    }

    public function test_payment_damage_deposit_refund_and_limit_are_separate_and_audited(): void
    {
        $order = $this->rented();
        $this->post(route('admin.rentals.orders.payment', $order), ['entry_key' => (string) Str::uuid(), 'kind' => 'payment', 'amount' => 175000, 'method' => 'transfer', 'reference' => 'TEST-001'])->assertSessionHasNoErrors();
        $this->post(route('admin.rentals.orders.payment', $order), ['entry_key' => (string) Str::uuid(), 'kind' => 'refund', 'amount' => 100000, 'method' => 'cash'])->assertSessionHasErrors('rental');
        $this->inspect($order, $this->returned($order), ['damage_fee' => 20000]);
        $order->refresh();
        $this->assertEquals(95000, $order->charges());
        $this->assertEquals(80000, $order->refundable());
        $this->post(route('admin.rentals.orders.payment', $order), ['entry_key' => (string) Str::uuid(), 'kind' => 'refund', 'amount' => 80001, 'method' => 'cash'])->assertSessionHasErrors('rental');
        $this->post(route('admin.rentals.orders.payment', $order), ['entry_key' => (string) Str::uuid(), 'kind' => 'refund', 'amount' => 80000, 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertEquals(0, $order->refresh()->refundable());
        $this->assertEquals(0, $order->balance());
        $this->assertDatabaseHas('audit_logs', ['action' => 'rental.payment.refund']);
    }

    public function test_cancellation_releases_stock_and_all_received_money_becomes_refundable(): void
    {
        $order = $this->order();
        $this->action($order, 'confirm');
        $this->post(route('admin.rentals.orders.payment', $order), ['entry_key' => (string) Str::uuid(), 'kind' => 'payment', 'amount' => 175000, 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->action($order, 'cancel');
        $this->assertEquals(175000, $order->refundable());
        $this->postJson(route('rental.quote'), $this->data())->assertOk();
    }

    public function test_unit_replacement_before_handover_preserves_price_and_history_codes_cannot_be_changed(): void
    {
        $other = RentalUnit::create(['rental_product_id' => $this->product->id, 'code' => 'ST-002', 'status' => 'ready']);
        $order = $this->order();
        $item = $order->items()->firstOrFail();
        $this->post(route('admin.rentals.orders.replace', [$order, $item]), ['unit_id' => $other->id])->assertSessionHasNoErrors();
        $this->assertSame('ST-002', $item->refresh()->unit_code);
        $this->assertEquals(75000, $item->rental_price);
        $this->put(route('admin.rentals.units.update', $other), ['rental_product_id' => $this->product->id, 'code' => 'NEW-CODE', 'status' => 'ready', 'condition_notes' => 'Baik'])->assertSessionHasErrors('rental');
    }

    public function test_product_with_future_reservation_cannot_be_marked_unavailable_manually(): void
    {
        $this->order();
        $this->put(route('admin.rentals.units.update', $this->unit), ['rental_product_id' => $this->product->id, 'code' => 'ST-001', 'status' => 'retired', 'condition_notes' => 'Arsip'])->assertSessionHasErrors('rental');
    }

    public function test_public_checkout_receipt_is_session_scoped_and_sends_no_messages(): void
    {
        $data = $this->data(['name' => 'Pelanggan Publik', 'whatsapp_number' => '081299999999', 'terms_accepted' => 1]);
        $data['quote_token'] = $this->quote($data)['token'];
        $this->post(route('rental.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $order = RentalOrder::firstOrFail();
        $this->get(route('rental.receipt', $order->code))->assertOk()->assertSee('175.000');
        $this->withSession(['rental_order_ids' => []])->get(route('rental.receipt', $order->code))->assertNotFound();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_failed_public_stock_race_does_not_create_customer(): void
    {
        $data = $this->data(['name' => 'Should Not Exist', 'whatsapp_number' => '081288888888', 'terms_accepted' => 1]);
        $data['quote_token'] = $this->quote($data)['token'];
        $this->order();
        $this->post(route('rental.store'), $data)->assertSessionHasErrors('rental');
        $this->assertDatabaseMissing('customers', ['name' => 'Should Not Exist']);
    }

    public function test_delivery_and_collection_are_calculated_from_road_distance_and_snapshot(): void
    {
        $settings = app(RentalSettings::class)->get();
        app(RentalSettings::class)->save(array_replace($settings, ['delivery_enabled' => true, 'latitude' => -7.816, 'longitude' => 112.012, 'delivery_rate_per_km' => 5000, 'delivery_base_fee' => 10000, 'collection_rate_per_km' => 4000, 'collection_base_fee' => 5000, 'free_km' => 2]));
        Http::fake(['router.project-osrm.org/*' => Http::response(['code' => 'Ok', 'routes' => [['distance' => 6200, 'duration' => 1200]]])]);
        $order = $this->order(['fulfilment' => 'both', 'address' => 'Jalan Uji 10, Kediri', 'latitude' => -7.83, 'longitude' => 112.03]);
        $this->assertEquals(35000, $order->delivery_fee);
        $this->assertEquals(25000, $order->collection_fee);
        $this->assertEquals(235000, $order->totalDue());
        $this->get(route('admin.rentals.orders.show', $order))->assertOk()->assertSee('Navigasi antar / jemput');
    }

    public function test_disabled_delivery_missing_pin_out_of_range_and_route_failure_fail_closed(): void
    {
        $data = $this->data(['fulfilment' => 'both', 'address' => 'Jalan Uji 10, Kediri', 'latitude' => -7.83, 'longitude' => 112.03]);
        $this->postJson(route('rental.quote'), $data)->assertUnprocessable();
        Http::assertNothingSent();
        app(RentalSettings::class)->save(array_replace(app(RentalSettings::class)->get(), ['delivery_enabled' => true, 'latitude' => -7.816, 'longitude' => 112.012]));
        Http::fake(['router.project-osrm.org/*' => Http::response(['code' => 'Ok', 'routes' => [['distance' => 20001, 'duration' => 2000]]])]);
        $this->postJson(route('rental.quote'), $data)->assertUnprocessable()->assertJsonMissingPath('token');
        $this->postJson(route('rental.quote'), array_merge($data, ['latitude' => null]))->assertUnprocessable();
    }

    public function test_rental_settings_are_independent_and_bad_inputs_rejected(): void
    {
        $data = app(RentalSettings::class)->get();
        $this->put(route('admin.rentals.settings.update'), array_replace($data, ['hold_hours' => 12, 'delivery_rate_per_km' => 6000]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('12', (string) app(RentalSettings::class)->get()['hold_hours']);
        $this->put(route('admin.rentals.settings.update'), array_replace($data, ['delivery_enabled' => true, 'latitude' => null]))->assertSessionHasErrors('latitude');
        $this->assertDatabaseHas('audit_logs', ['action' => 'rental.settings.update']);
    }

    public function test_route_failure_cannot_produce_a_zero_fee_delivery_quote(): void
    {
        app(RentalSettings::class)->save(array_replace(app(RentalSettings::class)->get(), ['delivery_enabled' => true, 'latitude' => -7.816, 'longitude' => 112.012]));
        Http::fake(['router.project-osrm.org/*' => Http::response([], 503)]);
        $this->postJson(route('rental.quote'), $this->data(['fulfilment' => 'both', 'address' => 'Alamat pengujian Kediri', 'latitude' => -7.83, 'longitude' => 112.03]))->assertUnprocessable()->assertJsonMissingPath('token');
    }

    public function test_customer_with_rental_history_can_be_archived_and_cannot_be_deleted(): void
    {
        $order = $this->order();
        $this->delete(route('admin.customers.destroy', $this->customer))->assertForbidden();
        $owner = User::factory()->create(['role' => 'owner', 'status' => 'active']);
        $this->actingAs($owner)->delete(route('admin.customers.destroy', $this->customer))->assertSessionHasErrors('customer');
        $this->actingAs($this->admin);
        $this->assertDatabaseHas('rental_orders', ['id' => $order->id]);
        $this->post(route('admin.customers.archive', $this->customer))->assertRedirect();
        $this->assertDatabaseHas('customers', ['id' => $this->customer->id]);
    }

    public function test_retrying_payment_form_does_not_duplicate_the_payment(): void
    {
        $order = $this->order();
        $this->action($order, 'confirm');
        $data = ['entry_key' => (string) Str::uuid(), 'kind' => 'payment', 'amount' => 175000, 'method' => 'cash'];
        $this->post(route('admin.rentals.orders.payment', $order), $data)->assertSessionHasNoErrors();
        $this->post(route('admin.rentals.orders.payment', $order), $data)->assertSessionHasNoErrors();
        $this->assertSame(1, $order->payments()->count());
        $this->post(route('admin.rentals.orders.payment', $order), array_replace($data, ['amount' => 999999]))->assertSessionHasErrors('rental');
    }

    public function test_private_return_photo_and_cross_order_item_access_are_protected(): void
    {
        Storage::fake('local');
        $order = $this->rented();
        $item = $order->items()->firstOrFail();
        $file = UploadedFile::fake()->image('condition.png', 10, 10);
        $this->post(route('admin.rentals.orders.return', [$order, $item]), ['notes' => 'Foto kondisi diterima', 'photo' => $file])->assertSessionHasNoErrors();
        Storage::disk('local')->assertExists($item->refresh()->return_photo_path);
        $this->get(route('admin.rentals.items.photo', $item))->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'therapist', 'status' => 'active']))->get(route('admin.rentals.items.photo', $item))->assertForbidden();
    }
}
