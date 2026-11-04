<?php

namespace Tests\Feature;

use App\Models\LandingPageSetting;
use App\Models\RentalProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LandingEditorialSeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_can_upload_and_publish_content_with_consistent_public_contact_and_metadata(): void
    {
        config(['app.url' => 'https://gayatri.example']);
        Storage::fake('public');
        $page = $this->page();
        $page->update(['is_published' => false, 'page_settings' => ['featured_catalog_codes' => [], 'booking_page' => ['headline' => 'Judul booking lama']]]);
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        $this->get(route('admin.landing.index'))->assertOk()->assertSee('Konten & SEO')->assertSee('Unggah gambar')->assertSee('Simpan layanan pilihan');
        $this->post(route('admin.landing.media.store'), [
            'name' => 'Dokumentasi outlet', 'type' => 'image', 'alt_text' => 'Ruang tunggu outlet',
            'file' => UploadedFile::fake()->image('outlet.jpg', 1600, 1000), 'is_active' => '1',
        ])->assertSessionHasNoErrors();
        $media = $page->media()->where('name', 'Dokumentasi outlet')->sole();
        $path = substr($media->url, strlen('/storage/'));
        $prefix = substr($path, 0, -strlen('-1440.webp'));
        $disk = Storage::disk('public');
        $disk->assertExists([$path, $prefix.'-480.webp', $prefix.'-share.jpg']);
        $this->assertSame([1440, 900, IMAGETYPE_WEBP], array_slice(getimagesizefromstring($disk->get($path)), 0, 3));
        $this->assertSame([480, 300, IMAGETYPE_WEBP], array_slice(getimagesizefromstring($disk->get($prefix.'-480.webp')), 0, 3));
        $this->assertSame([1200, 750, IMAGETYPE_JPEG], array_slice(getimagesizefromstring($disk->get($prefix.'-share.jpg')), 0, 3));
        $this->put(route('admin.landing.content.update'), $this->content([
            'hero_media_id' => $media->id, 'share_media_id' => $media->id,
            'faq' => [['title' => 'Apakah bisa datang langsung?', 'description' => 'Periksa jadwal bersama admin.'], ['title' => '', 'description' => '']],
        ]))->assertSessionHasNoErrors();
        $this->assertSame('Judul booking lama', $page->fresh()->page_settings['booking_page']['headline']);
        $this->assertSame([], $page->fresh()->page_settings['featured_catalog_codes']);
        $this->get('/')->assertNotFound();
        $this->get(route('admin.landing.preview', ['device' => 'mobile']))->assertOk()->assertSee('Pemeriksaan sebelum terbit')->assertSee('Pratinjau saat tautan dibagikan');
        $this->get('/?preview=1')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('Pembuka pilihan admin')->assertSee('Ruang tunggu outlet')->assertDontSee('rel="canonical"', false)->assertDontSee('application/ld+json', false);
        $this->post(route('admin.landing.publish'))->assertSessionHasNoErrors();

        $html = $this->get('/')->assertOk()->assertSee('Judul SEO outlet')
            ->assertSee('Apakah bisa datang langsung?')->assertSee('Buka pukul 09.00')
            ->assertSee('https://gayatri.example/storage/'.$prefix.'-share.jpg')
            ->assertSee('/storage/'.$prefix.'-480.webp 480w')
            ->assertDontSee('fonts.googleapis.com')->getContent();
        preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $matches);
        $business = json_decode($matches[1] ?? '', true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('https://gayatri.example/', $business['url']);
        $this->assertSame('Jl. Uji 1', $business['address']['streetAddress']);
        $this->assertSame('+628111223344', $business['telephone']);
        $this->get('/layanan?group=spa')->assertOk()->assertSee('Jl. Uji 1')->assertSee('https://wa.me/628111223344');
    }

    public function test_editor_rejects_bad_contact_incomplete_faq_and_media_outside_the_homepage(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        $page = $this->page();
        $initialMediaCount = $page->media()->count();
        $otherPage = LandingPageSetting::create(['slug' => 'other', 'site_name' => 'Other', 'is_published' => false]);
        $media = $otherPage->media()->create(['name' => 'Gambar lain', 'type' => 'image', 'url' => '/images/other.jpg', 'is_active' => true]);
        $this->from(route('admin.landing.index'))->put(route('admin.landing.content.update'), $this->content([
            'whatsapp' => '6281234567890', 'maps_url' => 'javascript:alert(1)', 'hero_media_id' => $media->id,
            'faq' => [['title' => 'Tanpa jawaban']], 'headline' => ['invalid'],
        ]))->assertSessionHasErrors(['whatsapp', 'maps_url', 'hero_media_id', 'faq.0.description', 'headline']);
        $this->assertSame($page->site_name, $page->fresh()->site_name);
        $this->get(route('admin.landing.index'))->assertOk();
        $this->post(route('admin.landing.media.store'), [
            'name' => 'URL tidak aman', 'type' => 'image', 'url' => 'data:text/html,hello', 'is_active' => '1',
        ])->assertSessionHasErrors('url');
        $this->assertSame($initialMediaCount, $page->media()->count());
    }

    public function test_sitemap_lists_canonical_public_catalogues_and_excludes_inactive_demo_and_private_pages(): void
    {
        config(['app.url' => 'https://gayatri.example']);
        $product = ['category' => 'Stroller', 'description' => 'Keterangan stroller uji', 'daily_rate' => 25000];
        $active = RentalProduct::create($product + ['sku' => 'SEO-1', 'name' => 'Stroller uji', 'is_active' => true]);
        $inactive = RentalProduct::create($product + ['sku' => 'SEO-2', 'name' => 'Stroller arsip', 'is_active' => false]);
        $demo = RentalProduct::create($product + ['sku' => 'SEO-3', 'name' => '[DEMO] Stroller contoh', 'is_active' => true]);
        $this->get('/robots.txt')->assertOk()->assertSee('Sitemap: https://gayatri.example/sitemap.xml', false);
        $response = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $urls = array_map(fn ($item) => (string) $item, simplexml_load_string($response->getContent())->xpath('//*[local-name()="loc"]'));
        $this->assertContains('https://gayatri.example/', $urls);
        $this->assertContains('https://gayatri.example/layanan?type=laundry', $urls);
        $this->assertContains('https://gayatri.example/rental/products/'.$active->id, $urls);
        foreach (['/rental/products/'.$inactive->id, '/rental/products/'.$demo->id, '/booking', '/rental/cart', '/laundry', '/login'] as $excluded) {
            $this->assertNotContains('https://gayatri.example'.$excluded, $urls);
        }
        $this->get('/layanan?utm_source=test&group=lactation&type=services')->assertOk()
            ->assertSee('rel="canonical" href="https://gayatri.example/layanan?group=lactation"', false)->assertSee('Laktasi & Konsultasi ASI');
        $this->get('/layanan?search=bayi')->assertOk()->assertSee('name="robots" content="noindex, follow"', false)->assertDontSee('rel="canonical"', false);
        $this->get('/rental/products/'.$active->id)->assertOk()->assertSee('Keterangan stroller uji')->assertSee('https://gayatri.example/rental/products/'.$active->id);
        $this->get('/laundry')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertDontSee('property="og:url"', false);
        $this->get('/booking')->assertOk()->assertDontSee('rel="canonical"', false);
        $this->get('/rental/cart')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->withSession(['_old_input' => ['name' => 'Penyewa uji']])->get('/rental/products/'.$active->id)->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertDontSee('property="og:url"', false);
        $this->page()->update(['is_published' => false]);
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('<loc>https://gayatri.example/</loc>', false);
    }

    public function test_business_schema_cannot_end_its_script_element(): void
    {
        $name = 'Gayatri </script><script>alert(1)</script>';
        $this->page()->update(['site_name' => $name]);
        $html = $this->get('/')->assertOk()->assertDontSee($name, false)->getContent();
        preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $matches);
        $this->assertSame($name, json_decode($matches[1] ?? '', true, flags: JSON_THROW_ON_ERROR)['name']);
    }

    private function page(): LandingPageSetting
    {
        return LandingPageSetting::where('slug', 'home')->sole();
    }

    private function content(array $overrides = []): array
    {
        return array_replace([
            'business_name' => 'Gayatri Outlet Uji', 'city' => 'Kediri', 'address' => 'Jl. Uji 1',
            'hours' => 'Buka pukul 09.00', 'whatsapp' => '08111223344', 'headline' => 'Pembuka pilihan admin',
            'intro' => 'Pengantar untuk keluarga Bunda.', 'meta_title' => 'Judul SEO outlet', 'meta_description' => 'Deskripsi SEO outlet.',
        ], $overrides);
    }
}
