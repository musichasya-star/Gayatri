<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\LaundryOrder;
use App\Models\LaundryTariff;
use App\Models\RentalOrder;
use App\Models\TherapistFee;
use App\Models\User;
use App\Services\CRM\LaundryPricing;
use App\Services\CRM\LaundrySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class LaundryFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private LaundryTariff $kg;

    private LaundryTariff $piece;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 29)->setTime(10, 0));
        Http::preventStrayRequests();
        Queue::fake();
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($this->admin);
        $this->customer = Customer::create(['name' => 'Pelanggan Laundry', 'whatsapp_number' => '6280000012001', 'status' => 'lead']);
        $this->outlet();
        $this->kg = LaundryTariff::create(['code' => 'BAYI-KG', 'name' => 'Cuci Lipat Bayi', 'audience' => 'baby', 'unit' => 'kg', 'price' => 7500, 'minimum_milli' => 3000, 'increment_milli' => 500, 'duration_hours' => 24, 'is_active' => true]);
        $this->piece = LaundryTariff::create(['code' => 'SELIMUT', 'name' => 'Selimut Satuan', 'audience' => 'both', 'unit' => 'piece', 'price' => 15000, 'minimum_milli' => 1000, 'increment_milli' => 1000, 'duration_hours' => 48, 'is_active' => true]);
    }

    private function outlet(array $changes = []): void
    {
        app(LaundrySettings::class)->save(array_replace(['accepting_orders' => true, 'outlet_name' => 'Outlet Uji Laundry', 'address' => 'Alamat outlet uji laundry', 'whatsapp_number' => '081234000800', 'opening_hours' => '08.00–17.00', 'terms' => 'Ketentuan awal laundry', 'allow_collection' => false], $changes), $this->admin, Request::create('/'));
    }

    private function data(array $changes = []): array
    {
        return array_replace(['entry_key' => (string) Str::uuid(), 'customer_id' => $this->customer->id, 'received_at' => '2026-10-29T09:00', 'intake_mode' => 'walkin', 'instructions' => 'Tanpa pewangi.', 'items' => [
            ['laundry_tariff_id' => $this->kg->id, 'bag_label' => 'Baju bayi', 'received_pieces' => 15, 'estimated_quantity' => '3.8', 'condition_notes' => 'Noda kecil, jumlah diperiksa.'],
            ['laundry_tariff_id' => $this->piece->id, 'bag_label' => 'Selimut bayi', 'received_pieces' => 2, 'condition_notes' => 'Dua selimut diterima lengkap.'],
        ]], $changes);
    }

    private function preview(array $data): array
    {
        return $this->post(route('admin.laundry.orders.preview'), $data)->assertOk()->viewData('preview');
    }

    private function order(array $changes = []): LaundryOrder
    {
        $preview = $this->preview($this->data($changes));
        $this->post(route('admin.laundry.orders.store'), ['token' => $preview['token'], 'confirmed' => 1])->assertSessionHasNoErrors();

        return LaundryOrder::latest('id')->firstOrFail();
    }

    private function weighing(LaundryOrder $order, array $changes = []): array
    {
        $items = $order->items()->orderBy('id')->get();

        return array_replace(['revision' => $order->fresh()->revision, 'reason' => 'Penimbangan di outlet', 'items' => [
            ['id' => $items[0]->id, 'actual_pieces' => 15, 'quantity' => '2.31', 'notes' => 'Jumlah sesuai penerimaan.'],
            ['id' => $items[1]->id, 'actual_pieces' => 2, 'notes' => 'Jumlah selimut lengkap.'],
        ]], $changes);
    }

    private function weigh(LaundryOrder $order): void
    {
        $this->post(route('admin.laundry.orders.weigh', $order), $this->weighing($order))->assertSessionHasNoErrors();
        $order->refresh();
    }

    private function approval(LaundryOrder $order, array $changes = []): array
    {
        return array_replace(['revision' => $order->fresh()->revision, 'approved_name' => 'Pelanggan Laundry', 'approval_channel' => 'outlet', 'approval_reference' => 'Pelanggan memeriksa hasil timbang di outlet dan menyetujui.', 'confirmed' => 1], $changes);
    }

    public function test_kg_and_piece_pricing_use_exact_decimal_minimum_and_upward_increments(): void
    {
        $this->assertSame(2310, LaundryPricing::milli('2.310'));
        $this->assertSame(['quantity_milli' => 2310, 'billable_milli' => 3000, 'amount' => 22500], LaundryPricing::calculate($this->kg->snapshot(), 2310));
        $this->assertSame(33750, LaundryPricing::calculate($this->kg->snapshot(), 4100)['amount']);
        $this->assertSame(30000, LaundryPricing::calculate($this->piece->snapshot(), 2000)['amount']);
        $this->assertNull(LaundryPricing::calculate($this->kg->snapshot(), null)['amount']);
        $cent = $this->kg->snapshot();
        $cent['price'] = 501;
        $cent['minimum_milli'] = 1;
        $cent['increment_milli'] = 1;
        $this->assertSame(1, LaundryPricing::calculate($cent, 1)['amount']);
    }

    public function test_outlet_and_tariff_crud_validate_units_prices_and_preserve_other_module_settings(): void
    {
        $this->get(route('admin.laundry.settings'))->assertOk()->assertSee('Pengaturan Outlet Laundry');
        $this->put(route('admin.laundry.settings.update'), ['accepting_orders' => 1, 'outlet_name' => 'Outlet', 'allow_collection' => 0])->assertSessionHasErrors(['address', 'whatsapp_number']);
        $base = ['code' => 'IBU-SETRIKA', 'name' => 'Setrika Ibu', 'audience' => 'mother', 'unit' => 'kg', 'price' => 10000, 'minimum_quantity' => '1.000', 'rounding_increment' => '0.100', 'duration_hours' => 24, 'is_active' => 1];
        $this->post(route('admin.laundry.tariffs.store'), $base)->assertSessionHasNoErrors();
        $tariff = LaundryTariff::where('code', 'IBU-SETRIKA')->sole();
        $this->assertSame(100, $tariff->increment_milli);
        $this->post(route('admin.laundry.tariffs.store'), array_replace($base, ['code' => 'ibu-setrika']))->assertSessionHasErrors('code');
        $this->put(route('admin.laundry.tariffs.update', $tariff), array_replace($base, ['unit' => 'piece', 'minimum_quantity' => '1.5']))->assertSessionHasErrors('minimum_quantity');
        $this->put(route('admin.laundry.tariffs.update', $tariff), array_replace($base, ['price' => 0]))->assertSessionHasErrors('price');
        $this->put(route('admin.laundry.tariffs.update', $tariff), array_replace($base, ['unit' => 'piece', 'minimum_quantity' => 1, 'rounding_increment' => 1]))->assertSessionHasNoErrors();
        $this->assertSame('piece', $tariff->fresh()->unit);
        foreach (['admin.laundry.tariffs.index', 'admin.laundry.tariffs.create', 'admin.laundry.orders.index', 'admin.laundry.orders.create'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('admin.laundry.tariffs.edit', $tariff))->assertOk();
    }

    public function test_preview_is_read_only_store_is_idempotent_and_snapshot_receipt_renders(): void
    {
        $preview = $this->preview($this->data());
        $this->assertSame(60000, $preview['total']);
        $this->assertSame(0, LaundryOrder::count());
        $this->assertSame(1, Customer::count());
        $this->post(route('admin.laundry.orders.store'), ['token' => $preview['token']])->assertSessionHasErrors('confirmed');
        for ($i = 0; $i < 2; $i++) {
            $this->post(route('admin.laundry.orders.store'), ['token' => $preview['token'], 'confirmed' => 1])->assertSessionHasNoErrors();
        }
        $order = LaundryOrder::sole();
        $this->assertSame('received', $order->status);
        $this->assertSame(60000, $order->estimated_total);
        $this->assertNull($order->actual_total);
        $this->assertSame(2, $order->items()->count());
        $this->assertSame(1, $order->events()->count());
        $this->assertCount(2, $order->items->pluck('bag_code')->unique());
        $this->get(route('admin.laundry.orders.show', $order))->assertOk()->assertSee('Belum ditimbang')->assertSee('Baju bayi');
        $this->get(route('admin.laundry.orders.receipt', $order))->assertOk()->assertSee('BUKTI PENERIMAAN LAUNDRY')->assertSee('Ketentuan awal laundry');
        $this->assertNull(session('laundry.intake_draft'));
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertSame(0, Booking::count());
        $this->assertSame(0, RentalOrder::count());
        $this->assertSame(0, TherapistFee::count());
    }

    public function test_unknown_weight_stays_unknown_without_zero_price_claim_and_weighing_fills_it(): void
    {
        $data = $this->data();
        $data['items'][0]['estimated_quantity'] = null;
        $order = $this->order(['items' => $data['items']]);
        $this->assertNull($order->estimated_total);
        $this->assertNull($order->items()->first()->estimated_amount);
        $this->weigh($order);
        $this->assertSame(52500, $order->actual_total);
        $this->get(route('admin.laundry.orders.show', $order))->assertOk()->assertSee('Belum lengkap');
    }

    public function test_catalogue_and_outlet_changes_do_not_change_existing_prices_or_terms(): void
    {
        $order = $this->order();
        $this->kg->update(['price' => 99999, 'minimum_milli' => 10000, 'increment_milli' => 1000, 'name' => 'Nama baru', 'is_active' => false]);
        $this->outlet(['accepting_orders' => false, 'terms' => 'Ketentuan BARU']);
        $this->weigh($order);
        $this->assertSame(52500, $order->actual_total);
        $this->assertSame('Ketentuan awal laundry', $order->outlet_snapshot['terms']);
        $this->get(route('admin.laundry.orders.receipt', $order))->assertOk()->assertSee('Cuci Lipat Bayi')->assertDontSee('Ketentuan BARU');
    }

    public function test_reweighing_invalidates_consent_preserves_old_revisions_and_blocks_stale_approval(): void
    {
        $order = $this->order();
        $this->weigh($order);
        $oldRevision = $order->revision;
        $this->post(route('admin.laundry.orders.approve', $order), $this->approval($order))->assertSessionHasNoErrors();
        $this->assertSame('approved', $order->fresh()->status);
        $data = $this->weighing($order);
        $data['items'][0]['quantity'] = '4.1';
        $data['reason'] = '';
        $this->post(route('admin.laundry.orders.weigh', $order), $data)->assertSessionHasErrors('reason');
        $this->assertSame('approved', $order->fresh()->status);
        $data['reason'] = 'Timbang ulang menggunakan timbangan cadangan.';
        $this->post(route('admin.laundry.orders.weigh', $order), $data)->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame('awaiting_approval', $order->status);
        $this->assertNull($order->approved_at);
        $this->assertSame(63750, $order->actual_total);
        $this->assertSame(60000, $order->estimated_total);
        $event = $order->events()->where('kind', 'weighed')->latest('id')->first();
        $this->assertSame(52500, $event->details['before']['actual_total']);
        $this->assertSame('Pelanggan Laundry', $event->details['before']['approved_name']);
        $this->post(route('admin.laundry.orders.approve', $order), $this->approval($order, ['revision' => $oldRevision]))->assertSessionHasErrors('laundry');
        $this->assertSame(1, $order->events()->where('kind', 'approved')->count());
        $this->get(route('admin.laundry.orders.show', $order))->assertOk()->assertSee('63.750');
    }

    public function test_all_bags_must_belong_to_order_and_piece_discrepancies_need_notes_atomically(): void
    {
        $order = $this->order();
        $other = $this->order();
        $data = $this->weighing($order);
        $data['items'][1]['id'] = $other->items()->first()->id;
        $this->post(route('admin.laundry.orders.weigh', $order), $data)->assertSessionHasErrors('items');
        $this->assertNull($order->fresh()->actual_total);
        $data = $this->weighing($order);
        $data['items'][1]['actual_pieces'] = 3;
        $data['items'][1]['notes'] = '';
        $this->post(route('admin.laundry.orders.weigh', $order), $data)->assertSessionHasErrors('items');
        $this->assertNull($order->items()->first()->actual_amount);
        $data['items'][1]['notes'] = 'Ditemukan selimut ketiga saat pemeriksaan ulang.';
        $this->post(route('admin.laundry.orders.weigh', $order), $data)->assertSessionHasNoErrors();
        $this->assertSame(67500, $order->fresh()->actual_total);
        $duplicate = $this->weighing($other);
        $duplicate['items'][1]['id'] = $duplicate['items'][0]['id'];
        $this->post(route('admin.laundry.orders.weigh', $other), $duplicate)->assertSessionHasErrors();
    }

    public function test_approval_requires_measuring_and_actual_consent_fields_not_payment_status(): void
    {
        $order = $this->order();
        $this->post(route('admin.laundry.orders.approve', $order), $this->approval($order))->assertSessionHasErrors('laundry');
        $this->weigh($order);
        $this->post(route('admin.laundry.orders.approve', $order), $this->approval($order, ['confirmed' => 0]))->assertSessionHasErrors('confirmed');
        $this->post(route('admin.laundry.orders.approve', $order), $this->approval($order))->assertSessionHasNoErrors();
        $this->assertSame('approved', $order->fresh()->status);
        $this->assertSame(52500, $order->fresh()->actual_total);
        $this->get(route('admin.laundry.orders.receipt', $order))->assertOk()->assertSee('belum menyatakan pembayaran diterima');
    }

    public function test_stale_tariff_outlet_expiry_and_foreign_actor_do_not_create_or_change_crm(): void
    {
        $data = $this->data(['customer_id' => null, 'new_name' => 'Pelanggan Baru', 'new_whatsapp' => '081234009999']);
        $preview = $this->preview($data);
        $this->kg->update(['price' => 8000]);
        $this->post(route('admin.laundry.orders.store'), ['token' => $preview['token'], 'confirmed' => 1])->assertSessionHasErrors('laundry');
        $this->assertSame(1, Customer::count());
        $this->assertSame(0, LaundryOrder::count());
        $preview = $this->preview($data);
        $this->outlet(['terms' => 'Terms changed']);
        $this->post(route('admin.laundry.orders.store'), ['token' => $preview['token'], 'confirmed' => 1])->assertSessionHasErrors('laundry');
        $preview = $this->preview($data);
        $this->travel(16)->minutes();
        $this->post(route('admin.laundry.orders.store'), ['token' => $preview['token'], 'confirmed' => 1])->assertSessionHasErrors('laundry');
        $this->travelBack();
        $preview = $this->preview($data);
        $this->actingAs(User::factory()->create(['role' => 'manager', 'status' => 'active']));
        $this->post(route('admin.laundry.orders.store'), ['token' => $preview['token'], 'confirmed' => 1])->assertSessionHasErrors('laundry');
        $this->assertSame(0, LaundryOrder::count());
    }

    public function test_new_customer_is_created_only_after_confirmation_and_existing_phone_never_overwrites_crm(): void
    {
        $data = $this->data(['customer_id' => null, 'new_name' => 'Pelanggan Baru', 'new_whatsapp' => '081234009999']);
        $preview = $this->preview($data);
        $this->assertSame(1, Customer::count());
        $this->post(route('admin.laundry.orders.store'), ['token' => $preview['token'], 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('customers', ['name' => 'Pelanggan Baru', 'whatsapp_number' => '6281234009999']);
        $this->post(route('admin.laundry.orders.preview'), $this->data(['customer_id' => null, 'new_name' => 'Nama berbeda', 'new_whatsapp' => '6281234009999']))->assertSessionHasErrors('new_whatsapp');
        $this->assertSame('Pelanggan Baru', Customer::where('whatsapp_number', '6281234009999')->sole()->name);
    }

    public function test_closed_outlet_archived_tariffs_invalid_decimals_and_unsupported_collection_fail_closed(): void
    {
        $this->outlet(['accepting_orders' => false]);
        $this->post(route('admin.laundry.orders.preview'), $this->data())->assertSessionHasErrors('laundry');
        $this->outlet();
        $this->kg->update(['is_active' => false]);
        $this->post(route('admin.laundry.orders.preview'), $this->data())->assertSessionHasErrors('items');
        $this->kg->update(['is_active' => true]);
        $this->post(route('admin.laundry.orders.preview'), $this->data(['intake_mode' => 'collection', 'collection_address' => 'Alamat lengkap pelanggan']))->assertSessionHasErrors('intake_mode');
        $data = $this->data();
        $data['items'][0]['estimated_quantity'] = '1.2345';
        $this->post(route('admin.laundry.orders.preview'), $data)->assertSessionHasErrors();
        $data = $this->data();
        $data['items'][1]['estimated_quantity'] = '1.5';
        $this->post(route('admin.laundry.orders.preview'), $data)->assertSessionHasErrors('items');
        $this->assertSame(0, LaundryOrder::count());
    }

    public function test_cancellation_retains_history_blocks_changes_and_customer_can_only_be_archived(): void
    {
        $order = $this->order();
        $this->weigh($order);
        $this->post(route('admin.laundry.orders.cancel', $order), ['revision' => $order->revision, 'reason' => 'Pelanggan membatalkan penerimaan.'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(52500, $order->fresh()->actual_total);
        $this->post(route('admin.laundry.orders.weigh', $order), $this->weighing($order))->assertSessionHasErrors('laundry');
        $this->delete(route('admin.customers.destroy', $this->customer))->assertForbidden();
        $owner = User::factory()->create(['role' => 'owner', 'status' => 'active']);
        $this->actingAs($owner)->delete(route('admin.customers.destroy', $this->customer))->assertSessionHasErrors('customer');
        $this->actingAs($this->admin);
        $this->post(route('admin.customers.archive', $this->customer))->assertSessionHasNoErrors();
        $this->assertSame(3, $order->events()->count());
        $this->get(route('admin.laundry.orders.receipt', $order))->assertOk()->assertSee('PENERIMAAN DIBATALKAN');
    }

    public function test_orders_filter_inclusive_dates_search_and_demo_without_mixing_other_modules(): void
    {
        $order = $this->order();
        $this->order(['is_demo' => 1]);
        $filters = ['start_date' => '2026-10-29', 'end_date' => '2026-10-29', 'search' => 'Pelanggan Laundry'];
        $this->get(route('admin.laundry.orders.index', $filters))->assertOk()->assertViewHas('orders', fn ($rows) => $rows->total() === 1)->assertSee($order->code);
        $this->get(route('admin.laundry.orders.index', $filters + ['include_demo' => 1]))->assertViewHas('orders', fn ($rows) => $rows->total() === 2);
        $this->assertSame(0, Booking::count());
        $this->assertSame(0, RentalOrder::count());
        $this->assertSame(0, TherapistFee::count());
    }

    public function test_only_owner_manager_admin_can_access_laundry_read_and_write_routes(): void
    {
        $order = $this->order();
        foreach (['owner', 'manager', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'active']))->get(route('admin.laundry.orders.index'))->assertOk();
        }
        foreach (['sales', 'therapist'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'active']));
            foreach (['orders.index', 'orders.create', 'settings', 'tariffs.index', 'tariffs.create'] as $endpoint) {
                $this->get(route('admin.laundry.'.$endpoint))->assertForbidden();
            }
            $this->get(route('admin.laundry.orders.show', $order))->assertForbidden();
            $this->get(route('admin.laundry.orders.receipt', $order))->assertForbidden();
            foreach (['orders.preview', 'orders.store', 'tariffs.store'] as $endpoint) {
                $this->post(route('admin.laundry.'.$endpoint), [])->assertForbidden();
            }
            foreach (['weigh', 'approve', 'cancel'] as $action) {
                $this->post(route('admin.laundry.orders.'.$action, $order), [])->assertForbidden();
            }
            $this->put(route('admin.laundry.settings.update'), [])->assertForbidden();
            $this->put(route('admin.laundry.tariffs.update', $this->kg), [])->assertForbidden();
        }
        $this->app['auth']->guard('web')->logout();
        $this->get(route('admin.laundry.orders.index'))->assertRedirect(route('login'));
    }
}
