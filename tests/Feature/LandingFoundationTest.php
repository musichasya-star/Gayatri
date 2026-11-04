<?php

namespace Tests\Feature;

use App\Models\LandingPageSetting;
use App\Services\AppSettingService;
use App\Services\Web\LandingPresentation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LandingFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_homepage_has_four_destinations_and_a_single_footer_after_all_sections(): void
    {
        $response = $this->get('/')->assertOk()
            ->assertSee('Sentuhan hangat untuk Bunda dan si kecil.')
            ->assertSee('Temukan layanan')->assertSee('public-menu')
            ->assertSee(route('landing.booking-page', ['service_group' => 'spa']))
            ->assertSee(route('landing.booking-page', ['service_group' => 'lactation']))
            ->assertSee(route('rental.index'))->assertSee(route('laundry.index'));
        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, '<h1 '));
        $this->assertSame(1, substr_count($html, '<footer '));
        $this->assertGreaterThan(strrpos($html, '</section>'), strpos($html, '<footer '));
        $this->assertSame(1, substr_count($html, 'id="services"'));
        $this->assertSame(1, substr_count($html, 'id="rental"'));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_laundry_entry_requires_both_online_and_outlet_readiness(): void
    {
        $settings = app(AppSettingService::class);
        $settings->set('laundry.public', json_encode(['enabled' => true]));
        $settings->set('laundry.outlet', json_encode(['accepting_orders' => false, 'address' => 'Alamat outlet uji', 'whatsapp_number' => '628111222333']));
        $this->get('/')->assertOk()->assertSee('Informasi Laundry')->assertSee('Pemesanan belum dibuka')->assertDontSee('Pesan Laundry');
        $settings->set('laundry.outlet', json_encode(['accepting_orders' => true, 'address' => 'Alamat outlet uji', 'whatsapp_number' => '628111222333']));
        $this->get('/')->assertOk()->assertSee('Pesan Laundry')->assertDontSee('Pemesanan belum dibuka');
        $settings->set('laundry.public', json_encode(['enabled' => false]));
        $this->get('/')->assertOk()->assertDontSee('Pesan Laundry');
        $this->assertDatabaseCount('laundry_orders', 0);
    }

    public function test_custom_cms_hero_and_extra_sections_are_preserved_and_escaped(): void
    {
        $page = LandingPageSetting::where('slug', 'home')->firstOrFail();
        $page->sections()->where('type', 'hero')->update(['content' => ['headline' => 'Judul Bunda & keluarga', 'description' => 'Pengantar pilihan admin', 'image_url' => '/images/custom-hero.webp', 'image_alt' => 'Gambar pilihan admin']]);
        $page->sections()->create(['type' => 'informasi_baru', 'title' => 'Informasi tambahan', 'is_active' => true, 'sort_order' => 999, 'content' => ['description' => '<script>unexpected()</script>']]);
        $this->get('/')->assertOk()->assertSee('Judul Bunda &amp; keluarga', false)->assertSee('Pengantar pilihan admin')->assertSee('/images/custom-hero.webp', false)
            ->assertSee('Informasi tambahan')->assertSee('&lt;script&gt;unexpected()&lt;/script&gt;', false)->assertDontSee('<script>unexpected()', false);
    }

    public function test_placeholder_contact_links_are_replaced_with_real_public_contact_paths(): void
    {
        $this->get('/')->assertOk()->assertDontSee('Jakarta Selatan')->assertDontSee('021-555-0123')
            ->assertDontSee('href="#"', false)->assertDontSee('https://maps.google.com"', false)
            ->assertSee('https://wa.me/', false)->assertDontSee('pending confirmation')->assertDontSee('powered by CRM');
    }

    public function test_homepage_remains_available_with_sparse_cms_and_unpublished_page_stays_private(): void
    {
        $page = LandingPageSetting::where('slug', 'home')->firstOrFail();
        $page->sections()->delete();
        $this->get('/')->assertOk()->assertSee('Temukan layanan')->assertSee('Informasi Laundry');
        $page->update(['is_published' => false]);
        $this->get('/')->assertNotFound();
        $this->get('/?preview=1')->assertForbidden();
    }

    public function test_public_asset_and_contact_urls_reject_active_content(): void
    {
        foreach (['javascript:alert(1)', '//untrusted.test', 'data:text/html,test', '/\\untrusted.test', 'https://site.test/ bad'] as $url) {
            $this->assertNull(LandingPresentation::url($url));
        }
        $this->assertSame('/images/hero.webp', LandingPresentation::url('/images/hero.webp'));
        $this->assertSame('https://example.test/image.webp', LandingPresentation::url('https://example.test/image.webp'));
    }
}
