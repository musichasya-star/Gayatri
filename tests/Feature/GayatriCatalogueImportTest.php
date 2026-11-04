<?php

namespace Tests\Feature;

use App\Models\AvailabilitySlot;
use App\Models\Branch;
use App\Models\LaundryTariff;
use App\Models\RentalProduct;
use App\Models\RentalUnit;
use App\Models\RetailProduct;
use App\Models\Service;
use App\Models\User;
use App\Services\AI\AiService;
use App\Services\CRM\CatalogueImport;
use App\Services\CRM\LaundryPricing;
use App\Services\CRM\RentalQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GayatriCatalogueImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['app.url' => 'http://localhost']);
    }

    public function test_complete_import_is_idempotent_and_preserves_later_admin_edits(): void
    {
        $first = app(CatalogueImport::class)->apply();
        $this->assertSame(263, $first['services_created']);
        $this->assertSame(20, $first['rentals_created']);
        $this->assertSame(9, $first['retail_created']);
        $this->assertSame(11, $first['laundry_created']);
        $this->assertSame(82, Service::distinct()->count('catalog_family'));
        $this->assertDatabaseCount('rental_units', 0);
        $item = Service::where('catalog_family', 'Baby SPA')->sole();
        $item->update(['price' => 111000]);
        $second = app(CatalogueImport::class)->apply();
        $this->assertSame(0, $second['services_created']);
        $this->assertSame('111000.00', $item->fresh()->price);
        $this->assertDatabaseCount('services', 263);
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('jobs', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_pdf_prices_age_restrictions_bundles_and_unknown_values_are_preserved(): void
    {
        app(CatalogueImport::class)->apply();
        $this->assertDatabaseHas('services', ['catalog_family' => 'Baby SPA', 'price' => 110000, 'duration_minutes' => 60]);
        $this->assertDatabaseHas('services', ['catalog_family' => 'Busui Kembali Bekerja', 'variant_label' => 'Private', 'price' => 179000]);
        $this->assertDatabaseHas('services', ['catalog_family' => 'Pijat Laktasi Premium', 'price' => 475000, 'booking_mode' => 'admin']);
        $this->assertSame(6, Service::where('catalog_family', 'Terapi Tumbuh Gigi')->count());
        $this->assertSame(0, Service::where('catalog_family', 'Terapi Tumbuh Gigi')->where('variant_label', 'like', 'Baby%')->count());
        $this->assertDatabaseHas('services', ['catalog_family' => 'Konseling Laktasi', 'price' => null, 'duration_minutes' => null, 'booking_mode' => 'admin']);
        $this->assertDatabaseHas('retail_products', ['name' => 'Tiwa Oil 100 ml', 'price' => 79000, 'compare_at_price' => 100000]);
        $this->assertDatabaseHas('rental_products', ['name' => 'Mom Cozy M6', 'weekly_rate' => null, 'fortnightly_rate' => 450000, 'monthly_rate' => 550000, 'deposit' => null]);
        $this->assertSame(0, Service::where('price', 0)->count());
    }

    public function test_public_variations_are_visible_without_booking_pending_packages_or_zero_price_placeholders(): void
    {
        app(CatalogueImport::class)->apply();
        $this->get(route('catalogue.index', ['search' => 'Pijat Laktasi Premium']))->assertOk()->assertSee('475.000')->assertSee('data-admin="1"', false)->assertSee('45 menit per sesi');
        $this->get(route('catalogue.index', ['type' => 'rental']))->assertOk()->assertSee('Dua Minggu')->assertSee('Deposit dikonfirmasi admin')->assertDontSee('Rp 0/hari');
        $this->get(route('catalogue.index', ['type' => 'retail']))->assertOk()->assertSee('Tiwa Oil')->assertSee('79.000');
        $this->get(route('catalogue.index', ['type' => 'laundry']))->assertOk()->assertSee('Baju Bayi')->assertSee('15.000');
        $this->get(route('landing.booking-page', ['service_group' => 'lactation']))->assertOk()->assertDontSee('5 sesi · 45 menit per sesi');
        $pending = Service::where('catalog_family', 'Pijat Laktasi Premium')->where('price', 475000)->sole();
        $this->assertTrue($pending->requiresManualBooking());
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_rental_two_week_tariff_uses_fourteen_day_blocks_and_missing_deposit_is_not_free(): void
    {
        app(CatalogueImport::class)->apply();
        $product = RentalProduct::where('name', 'Mom Uung HandFree')->sole();
        RentalUnit::create(['rental_product_id' => $product->id, 'code' => 'REAL-TEST-UNIT', 'status' => 'ready']);
        $data = ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'start_date' => today()->toDateString(), 'end_date' => today()->addDays(13)->toDateString(), 'period' => 'fortnight', 'fulfilment' => 'self'];
        try {
            app(RentalQuote::class)->calculate($data);
            $this->fail('Unknown deposit must be held.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('deposit', $e->getMessage());
        }
        $product->update(['deposit' => 50000, 'catalog_metadata' => array_replace($product->catalog_metadata, ['turnaround_unconfirmed' => false])]);
        $quote = app(RentalQuote::class)->calculate($data)['quote'];
        $this->assertSame(85000, $quote['rental_subtotal']);
        $this->assertSame(1, $quote['period_count']);
        $data['end_date'] = today()->addDays(14)->toDateString();
        $this->assertSame(170000, app(RentalQuote::class)->calculate($data)['quote']['rental_subtotal']);
        $this->assertDatabaseCount('rental_orders', 0);
    }

    public function test_laundry_missing_operational_rules_are_explicitly_held(): void
    {
        app(CatalogueImport::class)->apply();
        $tariff = LaundryTariff::where('name', 'Baju Bayi')->sole();
        $this->assertNull($tariff->duration_hours);
        $this->assertTrue($tariff->requires_confirmation);
        $this->expectException(ValidationException::class);
        LaundryPricing::calculate($tariff->snapshot(), 1000);
    }

    public function test_ai_reads_real_catalogue_and_keeps_status_and_handoff_priority_for_retail(): void
    {
        app(CatalogueImport::class)->apply();
        $ai = app(AiService::class);
        $this->assertStringContainsString('Rp79.000', $ai->simulate('Harga Tiwa Oil 100 ml')['reply']);
        $this->assertSame('escalated', $ai->simulate('Komplain produk Kidos')['status']);
        $this->assertSame('lookup_identity_unverified', $ai->simulate('Cek status BK-PRIVATE-1 produk Kidos')['fallback_reason']);
        $this->assertStringContainsString('deposit dikonfirmasi admin', $ai->simulate('harga sewa Mom Cozy M6')['reply']);
        $this->assertStringNotContainsString('Rp0/hari', $ai->simulate('harga sewa Mom Cozy M6')['reply']);
        Http::assertNothingSent();
    }

    public function test_retail_admin_access_and_updates_do_not_create_service_bookings(): void
    {
        app(CatalogueImport::class)->apply();
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active']);
        $this->actingAs($sales)->get(route('admin.retail.index'))->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)->get(route('admin.retail.index'))->assertOk()->assertSee('Produk Pendukung');
        $product = RetailProduct::first();
        $this->put(route('admin.retail.update', $product), ['sku' => $product->sku, 'name' => $product->name, 'category' => 'Produk Pendukung', 'price' => 70000, 'compare_at_price' => null, 'is_active' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('bookings', 0);
        Queue::assertNothingPushed();
    }

    public function test_forged_public_package_booking_does_not_create_a_customer_or_reserve_a_slot(): void
    {
        app(CatalogueImport::class)->apply();
        $service = Service::where('catalog_family', 'Pijat Laktasi Premium')->where('price', 475000)->sole();
        $branch = Branch::create(['code' => 'CATALOG-TEST', 'name' => 'Outlet Uji']);
        $slot = AvailabilitySlot::create(['branch_id' => $branch->id, 'service_id' => $service->id, 'slot_date' => today()->addDay(),
            'start_time' => '10:00:00', 'end_time' => '10:45:00', 'capacity' => 1, 'booked_count' => 0, 'status' => 'available']);
        $this->post(route('landing.booking'), ['name' => 'Pemesan Uji', 'whatsapp_number' => '081234500111',
            'service_group' => 'lactation', 'service_id' => $service->id, 'branch_id' => $branch->id, 'availability_slot_id' => $slot->id,
            'booking_date' => today()->addDay()->toDateString(), 'start_time' => '10:00'])->assertSessionHasErrors('booking');
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('bookings', 0);
        $this->assertSame(0, $slot->fresh()->booked_count);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_admin_can_complete_single_service_details_but_cannot_convert_multi_visit_package_to_one_booking(): void
    {
        app(CatalogueImport::class)->apply();
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        $package = Service::where('catalog_family', 'Pijat Laktasi Premium')->where('price', 475000)->sole();
        $payload = ['name' => $package->name, 'service_group' => 'lactation', 'duration_minutes' => 45, 'price' => 475000, 'booking_mode' => 'online', 'is_active' => 1];
        $this->put(route('admin.services.update', $package), $payload)->assertSessionHasErrors('booking_mode');
        $this->assertSame('admin', $package->fresh()->booking_mode);
        $this->get(route('admin.bookings.create'))->assertOk()->assertDontSee($package->name);
        $this->get(route('admin.availability.index'))->assertOk()->assertDontSee($package->name);
        // The public scope also rejects inconsistent data from an external import.
        $package->update(['booking_mode' => 'online']);
        $this->assertFalse(Service::bookable()->whereKey($package->id)->exists());

        $single = Service::where('catalog_family', 'Konseling Laktasi')->sole();
        $this->put(route('admin.services.update', $single), array_replace($payload, ['name' => $single->name, 'price' => 100000]))->assertSessionHasNoErrors();
        $this->assertFalse($single->fresh()->requiresManualBooking());
        $this->assertTrue(Service::bookable()->whereKey($single->id)->exists());
    }

    public function test_ai_distinguishes_family_age_class_and_package_variations_without_inventing_an_unlisted_choice(): void
    {
        app(CatalogueImport::class)->apply();
        $ai = app(AiService::class);
        $premium = $ai->simulate('Harga Pijat Laktasi Premium 5 sesi')['reply'];
        $this->assertStringContainsString('Rp475.000', $premium);
        $this->assertStringNotContainsString('Rp75.000', $premium);
        $this->assertStringContainsString('/layanan?', $premium);
        $kid = $ai->simulate('Harga Terapi Tumbuh Gigi Kid 3 sesi')['reply'];
        $this->assertStringContainsString('Kid', $kid);
        $this->assertStringNotContainsString('Toddler', $kid);
        $this->assertStringContainsString('Rp179.000', $ai->simulate('Harga Busui Kembali Bekerja private')['reply']);
        $this->assertStringContainsString('belum ditemukan', $ai->simulate('Harga Pijat Laktasi Premium 7 sesi')['reply']);
        $this->assertStringNotContainsString('Rp475.000', $ai->simulate('Harga Pijat Laktasi Premium 7 sesi')['reply']);
        $this->assertStringNotContainsString('Kidos', $ai->simulate('harga Tiwa Oil')['reply']);
        $this->assertStringContainsString('Rp79.000', $ai->simulate('harga Tiwa Oil')['reply']);
        $this->assertDatabaseCount('bookings', 0);
        Http::assertNothingSent();
    }

    public function test_all_source_pages_and_real_product_photos_are_packaged_with_the_import(): void
    {
        $manifest = app(CatalogueImport::class)->manifest();
        foreach (range(1, 23) as $page) {
            $this->assertFileExists(storage_path('app/public/catalog/gayatri-menu/pages/page-'.str_pad($page, 2, '0', STR_PAD_LEFT).'.webp'));
        }
        foreach (['rentals', 'retail'] as $type) {
            foreach ($manifest[$type] as $item) {
                $this->assertFileExists(storage_path('app/public/catalog/gayatri-menu/'.$type.'/'.strtolower($item['sku']).'.webp'));
            }
        }
    }
}
