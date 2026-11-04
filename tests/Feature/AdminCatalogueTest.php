<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\LaundryTariff;
use App\Models\RentalProduct;
use App\Models\RentalUnit;
use App\Models\RetailProduct;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminCatalogueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
    }

    private function service(array $changes = []): Service
    {
        return Service::create(array_replace(['name' => 'Laktasi Premium — 1 sesi', 'catalog_family' => 'Laktasi Premium', 'variant_label' => '1 sesi', 'service_group' => 'lactation', 'category' => 'Pendampingan ASI', 'duration_minutes' => 45, 'price' => 98000, 'booking_mode' => 'online', 'is_active' => true], $changes));
    }

    private function payload(Service $service, array $changes = []): array
    {
        return array_replace($service->only(['name', 'catalog_family', 'variant_label', 'service_group', 'category', 'duration_minutes', 'price', 'booking_mode', 'is_active']), $changes);
    }

    public function test_families_keep_variants_and_service_groups_separate_without_mutating_records(): void
    {
        $single = $this->service();
        $package = $this->service(['name' => 'Laktasi Premium — 5 sesi', 'variant_label' => '5 sesi', 'price' => 475000, 'booking_mode' => 'admin', 'catalog_metadata' => ['package_quantity' => 5, 'role' => 'service']]);
        $spa = $this->service(['name' => 'Laktasi Premium SPA', 'service_group' => 'spa']);
        $before = Service::orderBy('id')->get()->toArray();
        $response = $this->get(route('admin.services.index', ['service_group' => 'lactation']))->assertOk();
        $families = $response->viewData('families');
        $this->assertSame(1, $families->total());
        $family = $families->getCollection()->first();
        $this->assertSame([$single->id, $package->id], $family['variants']->modelKeys());
        $this->assertSame(98000.0, $family['min_price']);
        $this->assertSame(475000.0, $family['max_price']);
        $this->assertSame(1, $family['ready_count']);
        $this->assertSame(1, $family['admin_count']);
        $this->get(route('admin.services.index'))->assertOk()->assertViewHas('families', fn ($groups) => $groups->total() === 2);
        $this->get(route('catalogue.index'))->assertOk()->assertViewHas('families', fn ($groups) => $groups->total() === 2);
        $this->assertSame($before, Service::orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_filters_search_codes_and_variants_and_keep_publication_independent_of_readiness(): void
    {
        $ready = $this->service(['catalog_code' => 'KB-%_!']);
        $missing = $this->service(['name' => 'Konseling tanpa durasi', 'catalog_family' => 'Konseling', 'duration_minutes' => null, 'booking_mode' => 'admin']);
        $archived = $this->service(['name' => 'Arsip masih lengkap', 'is_active' => false]);
        $this->get(route('admin.services.index', ['search' => '%_!']))->assertOk()->assertViewHas('services', fn ($rows) => $rows->getCollection()->modelKeys() === [$ready->id]);
        $this->get(route('admin.services.index', ['search' => '1 sesi', 'readiness' => 'ready']))->assertOk()->assertViewHas('variantCount', 1);
        $this->get(route('admin.services.index', ['readiness' => 'incomplete']))->assertOk()->assertSee('Perlu dilengkapi')->assertViewHas('services', fn ($rows) => $rows->getCollection()->modelKeys() === [$missing->id]);
        $this->get(route('admin.services.index', ['active' => '0', 'readiness' => 'ready']))->assertOk()->assertSee('Diarsipkan')->assertViewHas('services', fn ($rows) => $rows->getCollection()->modelKeys() === [$archived->id]);
        $this->getJson(route('admin.services.index', ['readiness' => 'unknown']))->assertUnprocessable();
        $this->getJson(route('admin.services.index', ['search' => ['bad']]))->assertUnprocessable();
    }

    public function test_family_pagination_is_based_on_families_and_primary_services_precede_addons(): void
    {
        $this->service(['name' => 'A Additional', 'catalog_family' => 'A Additional', 'catalog_metadata' => ['role' => 'addon'], 'booking_mode' => 'admin']);
        for ($i = 0; $i < 14; $i++) {
            $this->service(['name' => 'Treatment '.$i, 'catalog_family' => 'Treatment '.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
        }
        $response = $this->get(route('admin.services.index', ['service_group' => 'lactation', 'kind' => 'treatment']))->assertOk();
        $this->assertSame(14, $response->viewData('families')->total());
        $this->assertCount(12, $response->viewData('families')->items());
        $response->assertSee('kind=treatment', false);
        $this->get(route('admin.services.index', ['service_group' => 'lactation']))->assertOk()->assertViewHas('families', fn ($rows) => $rows->items()[0]['name'] === 'Treatment 00');
        $this->get(route('admin.services.index', ['service_group' => 'lactation', 'page' => 2, 'kind' => 'treatment']))->assertOk()->assertViewHas('families', fn ($rows) => count($rows->items()) === 2);
    }

    public function test_new_variation_prefills_identity_without_inventing_price_duration_or_stock(): void
    {
        $source = $this->service();
        $response = $this->get(route('admin.services.create', ['variant_of' => $source->id]))->assertOk();
        $new = $response->viewData('service');
        $this->assertFalse($new->exists);
        $this->assertSame($source->familyName(), $new->catalog_family);
        $this->assertSame('lactation', $new->service_group);
        $this->assertNull($new->price);
        $this->assertNull($new->duration_minutes);
        $this->assertFalse($new->is_active);
        $this->assertDatabaseCount('services', 1);
    }

    public function test_package_kind_cannot_become_a_single_web_booking_even_with_one_quantity(): void
    {
        $single = $this->service();
        $payload = $this->payload($single, ['name' => 'Paket gabungan', 'catalog_kind' => 'package', 'package_quantity' => 1, 'booking_mode' => 'online']);
        $this->post(route('admin.services.store'), $payload)->assertSessionHasErrors('booking_mode');
        $this->post(route('admin.services.store'), array_replace($payload, ['booking_mode' => 'admin']))->assertSessionHasNoErrors();
        $package = Service::where('name', 'Paket gabungan')->sole();
        $this->assertTrue($package->requiresManualBooking());
        $this->put(route('admin.services.update', $package), $this->payload($package, ['catalog_kind' => 'treatment']))->assertSessionHasErrors('catalog_kind');
        $package->update(['booking_mode' => 'online']);
        $this->assertFalse(Service::bookable()->whereKey($package->id)->exists());
        $this->get(route('landing.booking-page', ['service_group' => 'lactation']))->assertOk()->assertViewHas('services', fn ($rows) => ! $rows->contains('id', $package->id));
    }

    public function test_saving_and_detaching_addons_keeps_ids_and_historical_booking_values(): void
    {
        $service = $this->service();
        $addon = $service->addOns()->create(['addon_name' => 'Tambahan khusus', 'price_adjustment' => 10000, 'duration_minutes' => 15, 'is_active' => true, 'requires_confirmation' => false]);
        $customer = Customer::create(['name' => 'Pelanggan riwayat', 'whatsapp_number' => '628111009990', 'status' => 'lead']);
        $booking = Booking::create(['customer_id' => $customer->id, 'service_id' => $service->id, 'booking_code' => 'BK-HISTORY-CATALOG', 'booking_date' => today(), 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'completed', 'payment_status' => 'paid', 'service_price_snapshot' => 98000, 'promo_discount' => 5000, 'source' => 'manual']);
        $before = $booking->refresh()->getRawOriginal();
        $this->put(route('admin.services.update', $service), $this->payload($service, ['price' => 120000]))->assertSessionHasNoErrors();
        $this->assertTrue($addon->fresh()->is_active);
        $this->put(route('admin.services.update', $service), $this->payload($service->fresh(), ['addons_present' => 1]))->assertSessionHasNoErrors();
        $this->assertFalse($addon->fresh()->is_active);
        $this->assertDatabaseHas('service_addons', ['id' => $addon->id, 'service_id' => $service->id]);
        $this->assertSame($before, $booking->fresh()->getRawOriginal());
        $this->assertDatabaseCount('booking_payments', 0);
    }

    public function test_foreign_addon_ids_and_unconfirmed_prices_are_rejected_atomically(): void
    {
        $service = $this->service();
        $foreign = $this->service(['name' => 'Layanan lain']);
        $addon = $foreign->addOns()->create(['addon_name' => 'Milik lain', 'price_adjustment' => 10000, 'duration_minutes' => 15, 'is_active' => true]);
        $data = $this->payload($service, ['name' => 'Perubahan harus batal', 'addons' => [['id' => $addon->id, 'addon_name' => 'Jangan pindah', 'duration_minutes' => 15, 'price_adjustment' => 10000, 'requires_confirmation' => false]]]);
        $this->put(route('admin.services.update', $service), $data)->assertSessionHasErrors('addons');
        $this->assertSame($service->name, $service->fresh()->name);
        $this->assertSame($foreign->id, $addon->fresh()->service_id);
        $data['addons'] = [['addon_name' => 'Belum jelas', 'requires_confirmation' => false]];
        $this->put(route('admin.services.update', $service), $data)->assertSessionHasErrors(['addons.0.duration_minutes', 'addons.0.price_adjustment']);
        $data['addons'][0]['requires_confirmation'] = true;
        $this->put(route('admin.services.update', $service), $data)->assertSessionHasNoErrors();
        $this->assertSame(0, $service->fresh()->activeAddOns()->count());
    }

    public function test_addon_picker_uses_one_catalogue_and_keeps_pending_addons_visible(): void
    {
        $service = $this->service();
        $extra = $this->service(['name' => 'Additional menyusui lengkap', 'catalog_metadata' => ['role' => 'addon'], 'booking_mode' => 'admin']);
        for ($i = 0; $i < 6; $i++) {
            $service->addOns()->create(['addon_name' => 'Tambahan '.$i, 'duration_minutes' => 0, 'price_adjustment' => 10000, 'is_active' => true, 'requires_confirmation' => true]);
        }
        $content = $this->get(route('admin.services.edit', $service))->assertOk()->assertSee($extra->name)->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML($content);
        $xpath = new \DOMXPath($dom);
        $this->assertCount(1, $xpath->query('//script[@id="addon-catalogue-data"]'));
        $this->assertCount(1, $xpath->query('//dialog'));
        $this->assertLessThan(70, $xpath->query('//option')->length);
        $this->get(route('admin.services.index', ['family' => $service->familyName(), 'service_group' => 'lactation']))->assertOk()->assertSee('6 perlu konfirmasi');
    }

    public function test_duplicate_addon_rows_are_rejected_without_partial_updates(): void
    {
        $service = $this->service();
        $extra = $this->service(['name' => 'Tambahan lain']);
        $addon = ['addon_service_id' => $extra->id, 'duration_minutes' => 15, 'price_adjustment' => 10000, 'requires_confirmation' => false, 'is_active' => true];
        $this->put(route('admin.services.update', $service), $this->payload($service, ['name' => 'Tidak tersimpan', 'addons' => [$addon, $addon]]))->assertSessionHasErrors('addons');
        $this->assertSame($service->name, $service->fresh()->name);
        $this->assertDatabaseCount('service_addons', 0);
    }

    public function test_other_catalogue_filters_expose_unknown_values_without_assuming_available_stock(): void
    {
        $product = RentalProduct::create(['name' => 'Pompa Temu', 'sku' => 'RENT-SKU-X', 'category' => 'Pompa', 'daily_rate' => 0, 'weekly_rate' => 100000, 'deposit' => null, 'turnaround_days' => 1, 'is_active' => true, 'catalog_metadata' => ['turnaround_unconfirmed' => true]]);
        $this->get(route('admin.rentals.products.index', ['search' => 'RENT-SKU-X', 'readiness' => 'incomplete']))->assertOk()->assertSee('Deposit dikonfirmasi admin')->assertViewHas('products', fn ($rows) => $rows->total() === 1);
        $product->update(['deposit' => 0, 'catalog_metadata' => []]);
        $this->get(route('admin.rentals.products.index', ['readiness' => 'empty']))->assertOk()->assertViewHas('products', fn ($rows) => $rows->total() === 1);
        RentalUnit::create(['rental_product_id' => $product->id, 'code' => 'UNIT-TEMU', 'status' => 'ready']);
        $this->get(route('admin.rentals.products.index', ['readiness' => 'ready']))->assertOk()->assertSee('1 siap / 1 unit fisik')->assertSee('bukan jaminan ketersediaan');
        $tariff = LaundryTariff::create(['code' => 'LC-X', 'name' => 'Stroller Temu', 'audience' => 'baby', 'unit' => 'piece', 'price' => 100000, 'minimum_milli' => null, 'increment_milli' => null, 'duration_hours' => null, 'requires_confirmation' => true, 'is_active' => true]);
        $this->get(route('admin.laundry.tariffs.index', ['search' => 'LC-X', 'unit' => 'piece', 'readiness' => 'incomplete']))->assertOk()->assertSee('per satuan')->assertSee('belum diisi')->assertViewHas('tariffs', fn ($rows) => $rows->total() === 1);
        $retail = RetailProduct::create(['name' => 'Minyak Temu', 'sku' => 'SHOP-X', 'category' => 'Minyak', 'price' => 79000, 'compare_at_price' => 100000, 'is_active' => false]);
        $this->get(route('admin.retail.index', ['search' => 'SHOP-X', 'active' => '0', 'category' => 'Minyak']))->assertOk()->assertSee($retail->name)->assertSee('Konfirmasi admin')->assertSee('Diarsipkan');
        $this->assertNull($tariff->fresh()->minimum_milli);
    }

    public function test_global_search_includes_rental_catalogue_and_laundry_tariffs_only_for_operators(): void
    {
        $rental = RentalProduct::create(['name' => 'Pompa Temu', 'sku' => 'RENT-TEMU', 'category' => 'Pompa', 'daily_rate' => 0, 'deposit' => null, 'turnaround_days' => 1, 'is_active' => true]);
        $tariff = LaundryTariff::create(['code' => 'LAUNDRY-TEMU', 'name' => 'Tarif Temu', 'audience' => 'baby', 'unit' => 'piece', 'price' => 100000, 'minimum_milli' => null, 'increment_milli' => null, 'duration_hours' => null, 'requires_confirmation' => true, 'is_active' => true]);
        $this->get(route('admin.search', ['q' => 'Temu']))->assertOk()->assertSee(route('admin.rentals.products.edit', $rental), false)->assertSee(route('admin.laundry.tariffs.edit', $tariff), false);
        $this->actingAs(User::factory()->create(['role' => 'sales', 'status' => 'active']))->get(route('admin.search', ['q' => 'Temu']))->assertOk()->assertDontSee($rental->name)->assertDontSee($tariff->name);
        Queue::assertNothingPushed();
    }

    public function test_unconfirmed_rental_turnaround_requires_explicit_admin_confirmation(): void
    {
        $product = RentalProduct::create(['name' => 'Pompa belum lengkap', 'sku' => 'RENT-CONFIRM', 'category' => 'Pompa', 'daily_rate' => 0, 'weekly_rate' => 100000, 'deposit' => 0, 'turnaround_days' => 1, 'is_active' => true, 'catalog_metadata' => ['source_page' => 19, 'turnaround_unconfirmed' => true]]);
        $data = $product->only(['sku', 'name', 'category', 'daily_rate', 'weekly_rate', 'deposit', 'turnaround_days', 'is_active']);
        foreach (['daily_rate', 'weekly_rate', 'deposit'] as $field) {
            $data[$field] = (int) $data[$field];
        }
        $this->put(route('admin.rentals.products.update', $product), $data)->assertSessionHasNoErrors();
        $this->assertTrue($product->fresh()->requiresConfirmation());
        $this->put(route('admin.rentals.products.update', $product), $data + ['confirm_turnaround' => 1])->assertSessionHasNoErrors();
        $this->assertFalse($product->fresh()->requiresConfirmation());
        $this->assertSame(19, $product->fresh()->catalog_metadata['source_page']);
        $this->assertDatabaseCount('rental_units', 0);
    }
}
