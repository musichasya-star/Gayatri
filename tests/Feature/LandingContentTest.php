<?php

namespace Tests\Feature;

use App\Models\LandingPageSetting;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LandingContentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function service(string $code, array $extra = []): Service
    {
        return Service::create(array_replace(['name' => 'Pilihan '.$code, 'catalog_code' => $code, 'catalog_family' => 'Keluarga '.$code, 'variant_label' => 'Reguler', 'service_group' => 'spa', 'booking_mode' => 'online', 'is_active' => true, 'price' => 45000, 'duration_minutes' => 30, 'catalog_metadata' => ['role' => 'service', 'package_quantity' => 1]], $extra));
    }

    private function page(): LandingPageSetting
    {
        return LandingPageSetting::where('slug', 'home')->firstOrFail();
    }

    public function test_homepage_uses_natural_illustrations_and_contacts_from_the_supplied_catalogue(): void
    {
        $this->get('/')->assertOk()->assertSee('spa-desktop-v2.mp4')->assertSee('spa-mobile-v1.mp4')->assertSee('service-spa-natural-v1.webp')->assertSee('service-lactation-natural-v1.webp')
            ->assertSee('service-rental-natural-v1.webp')->assertSee('service-laundry-natural-v1.webp')->assertSee('Ilustrasi foto')
            ->assertSee('Jl. Semeru No. 260 Kota Kediri')->assertSee('08.00')->assertSee('reservasi terakhir 19.00')
            ->assertSee('https://www.instagram.com/gayatribabyspa/', false)->assertDontSee('images.unsplash.com')->assertDontSee('hero-family-v1.webp');
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_featured_selection_preserves_order_and_excludes_unpublished_or_nonprimary_records(): void
    {
        $a = $this->service('GM-T-A');
        $b = $this->service('GM-T-B');
        $addon = $this->service('GM-T-ADD', ['catalog_metadata' => ['role' => 'addon']]);
        $inactive = $this->service('GM-T-OFF', ['is_active' => false]);
        $demo = $this->service('GM-T-DEMO', ['name' => '[DEMO] Pilihan contoh']);
        $package = $this->service('GM-T-PKG', ['catalog_metadata' => ['role' => 'bundle', 'package_quantity' => 3]]);
        $this->page()->update(['page_settings' => ['featured_catalog_codes' => [$b->catalog_code, $a->catalog_code, $addon->catalog_code, $inactive->catalog_code, $demo->catalog_code, $package->catalog_code]]]);
        $this->get('/')->assertOk()->assertViewHas('featuredServices', fn ($items) => $items->pluck('catalog_code')->all() === ['GM-T-B', 'GM-T-A']);
    }

    public function test_featured_prices_and_actions_follow_current_catalogue_readiness(): void
    {
        $online = $this->service('GM-T-ONLINE');
        $manual = $this->service('GM-T-MANUAL', ['duration_minutes' => null, 'booking_mode' => 'admin']);
        $this->page()->update(['page_settings' => ['featured_catalog_codes' => [$online->catalog_code, $manual->catalog_code]]]);
        $this->get('/')->assertOk()->assertSee(e(route('landing.booking-page', ['service_group' => 'spa', 'service_id' => $online->id])), false)
            ->assertSee(e(route('catalogue.index', ['group' => 'spa', 'search' => $manual->familyName()])), false)->assertSee('Durasi dikonfirmasi admin')->assertSee('Rp 45.000');
        $online->update(['price' => 99000]);
        $this->get('/')->assertOk()->assertSee('Rp 99.000');
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_admin_can_shorten_and_clear_featured_list_without_changing_other_cms_settings(): void
    {
        $a = $this->service('GM-T-A');
        $b = $this->service('GM-T-B');
        $c = $this->service('GM-T-C');
        $this->page()->update(['page_settings' => ['featured_catalog_codes' => [$a->catalog_code, $b->catalog_code, $c->catalog_code], 'booking_page' => ['headline' => 'Tetap tersimpan']]]);
        $this->actingAs(User::factory()->create(['role' => 'owner', 'status' => 'active']));
        $this->get(route('admin.landing.index'))->assertOk()->assertSee('Simpan layanan pilihan');
        $this->from(route('admin.landing.index'))->post(route('admin.landing.settings.update'), ['site_name' => 'Gayatri', 'page_settings' => ['featured_catalog_codes' => [$b->catalog_code, $a->catalog_code]]])->assertSessionHasNoErrors();
        $this->assertSame([$b->catalog_code, $a->catalog_code], $this->page()->page_settings['featured_catalog_codes']);
        $this->assertSame('Tetap tersimpan', $this->page()->page_settings['booking_page']['headline']);
        $this->post(route('admin.landing.settings.update'), ['site_name' => 'Gayatri', 'page_settings' => ['featured_catalog_codes' => ['', '', '', '', '', '']]])->assertSessionHasNoErrors();
        $this->assertSame([], $this->page()->page_settings['featured_catalog_codes']);
        $this->get('/')->assertOk()->assertDontSee('id="featured"', false);
    }

    public function test_featured_editor_rejects_addons_and_invalid_array_payloads_without_saving(): void
    {
        $a = $this->service('GM-T-A');
        $addon = $this->service('GM-T-ADD', ['catalog_metadata' => ['role' => 'addon']]);
        $this->page()->update(['page_settings' => ['featured_catalog_codes' => [$a->catalog_code]]]);
        $this->actingAs(User::factory()->create(['role' => 'owner', 'status' => 'active']));
        $this->post(route('admin.landing.settings.update'), ['site_name' => 'Gayatri', 'page_settings' => ['featured_catalog_codes' => [$addon->catalog_code]]])->assertSessionHasErrors('page_settings.featured_catalog_codes.0');
        $this->post(route('admin.landing.settings.update'), ['site_name' => 'Gayatri', 'page_settings' => ['featured_catalog_codes' => 'invalid']])->assertSessionHasErrors('page_settings.featured_catalog_codes');
        $this->post(route('admin.landing.settings.update'), ['site_name' => 'Gayatri', 'page_settings' => ['featured_catalog_codes' => array_fill(0, 7, $a->catalog_code)]])->assertSessionHasErrors('page_settings.featured_catalog_codes');
        $this->assertSame([$a->catalog_code], $this->page()->page_settings['featured_catalog_codes']);
    }

    public function test_cleared_or_custom_gallery_is_not_replaced_with_generated_outlet_documentation(): void
    {
        $gallery = $this->page()->sections()->where('type', 'gallery')->firstOrFail();
        $gallery->update(['items' => []]);
        $this->get('/')->assertOk()->assertDontSee('id="gallery"', false);
        $gallery->update(['items' => [['title' => 'Dokumentasi pilihan admin', 'image_url' => '/images/outlet-asli.jpg']]]);
        $this->get('/')->assertOk()->assertSee('Dokumentasi pilihan admin')->assertSee('/images/outlet-asli.jpg', false)->assertDontSee('bukan dokumentasi outlet atau tim');
    }

    public function test_legacy_testimonials_promos_and_unsupported_business_claims_are_not_published(): void
    {
        $this->get('/')->assertOk()->assertDontSee('BABY10')->assertDontSee('Bunda Alya')->assertDontSee('Bunda Rania')->assertDontSee('5+ tahun pengalaman')->assertDontSee('Ribuan keluarga dilayani');
    }

    public function test_default_faq_covers_all_services_while_custom_and_cleared_content_is_respected(): void
    {
        $this->get('/')->assertOk()->assertSee('Bagaimana pemesanan sewa alat bayi?')->assertSee('Apakah Laundry sudah bisa dipesan?')->assertSee('Bagaimana cara pembayarannya?');
        $faq = $this->page()->sections()->where('type', 'faq')->firstOrFail();
        $faq->update(['items' => [['title' => 'Pertanyaan khusus admin', 'description' => 'Jawaban yang sudah diperiksa']]]);
        $this->get('/')->assertOk()->assertSee('Pertanyaan khusus admin')->assertDontSee('Bagaimana pemesanan sewa alat bayi?');
        $faq->update(['items' => []]);
        $this->get('/')->assertOk()->assertDontSee('id="faq"', false);
    }
}
