<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LandingMedia;
use App\Models\LandingPageSetting;
use App\Models\LandingSection;
use App\Services\Web\LandingContent;
use App\Services\Web\LandingImage;
use App\Services\Web\LandingPresentation;
use App\Services\Web\PublicSeo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LandingPageCmsController extends Controller
{
    public function index(): View
    {
        $page = $this->page()->load(['sections', 'media' => fn ($query) => $query->latest()]);

        $featuredCandidates = LandingContent::candidates();
        $editor = $this->editor($page);

        return view('admin.landing.index', compact('page', 'featuredCandidates', 'editor'));
    }

    public function updateContent(Request $request): RedirectResponse
    {
        $page = $this->page();
        $image = Rule::exists('landing_media', 'id')->where('landing_page_setting_id', $page->id)->where('type', 'image')->where('is_active', true);
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:150'],
            'city' => ['required', 'string', 'max:100'],
            'address' => ['required', 'string', 'max:250'],
            'hours' => ['nullable', 'string', 'max:180'],
            'whatsapp' => ['required', 'string', 'regex:/^[+()\d .-]+$/D', 'max:30', function ($attribute, $value, $fail) {
                if (! LandingPresentation::phone($value)) {
                    $fail('Masukkan nomor WhatsApp usaha yang aktif dengan awalan 08 atau +62.');
                }
            }],
            'maps_url' => ['nullable', 'string', 'url:https,http', 'max:1000', self::safeUrl(...)],
            'headline' => ['required', 'string', 'max:150'],
            'intro' => ['required', 'string', 'max:500'],
            'hero_media_id' => ['nullable', 'integer', $image],
            'hero_background' => ['sometimes', Rule::in(['video', 'image'])],
            'meta_title' => ['nullable', 'string', 'max:70'],
            'meta_description' => ['nullable', 'string', 'max:170'],
            'share_media_id' => ['nullable', 'integer', $image],
            'faq' => ['nullable', 'array', 'max:50'],
            'faq.*' => ['array:title,description'],
            'faq.*.title' => ['nullable', 'required_with:faq.*.description', 'string', 'max:200'],
            'faq.*.description' => ['nullable', 'required_with:faq.*.title', 'string', 'max:1200'],
        ]);

        DB::transaction(function () use ($page, $data): void {
            $page = LandingPageSetting::lockForUpdate()->findOrFail($page->id);
            $page->update([
                'site_name' => $data['business_name'],
                'page_settings' => array_replace($page->page_settings ?? [], [
                    'business_city' => $data['city'], 'whatsapp' => LandingPresentation::phone($data['whatsapp']),
                ]),
                'seo_settings' => array_replace($page->seo_settings ?? [], [
                    'meta_title' => $data['meta_title'] ?? null, 'meta_description' => $data['meta_description'] ?? null,
                    'share_media_id' => $data['share_media_id'] ?? null,
                ]),
            ]);
            $this->updatePublicSection($page, 'hero', 'Pembuka', 10, [
                'headline' => $data['headline'], 'description' => $data['intro'], 'media_id' => $data['hero_media_id'] ?? null,
            ] + (isset($data['hero_background']) ? ['background_mode' => $data['hero_background']] : []));
            $this->updatePublicSection($page, 'location_contact', 'Lokasi & kontak', 90, [
                'address' => $data['address'], 'opening_hours' => $data['hours'] ?? null, 'maps_url' => $data['maps_url'] ?? null,
            ]);
            $this->updatePublicSection($page, 'faq', 'FAQ', 80, ['editorial' => true],
                array_values(array_filter($data['faq'] ?? [], fn ($row) => filled($row['title'] ?? null))));
        });

        return back()->with('cms_tab', 'content')->with('status', 'Konten beranda dan SEO berhasil disimpan.');
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'site_name' => ['required', 'string', 'max:150'],
            'page_settings' => ['nullable', 'array'],
            'page_settings.booking_page' => ['sometimes', 'array'],
            'page_settings.business_city' => ['nullable', 'string', 'max:100'],
            'page_settings.whatsapp' => ['nullable', 'string', 'max:30'],
            'page_settings.featured_catalog_codes' => ['sometimes', 'array', 'max:6'],
            'page_settings.featured_catalog_codes.*' => ['nullable', 'string', 'max:80', Rule::in(LandingContent::candidates()->pluck('catalog_code')->all())],
            'seo_settings' => ['nullable', 'array'],
            'seo_settings.meta_title' => ['nullable', 'string', 'max:150'],
            'seo_settings.meta_description' => ['nullable', 'string', 'max:500'],
            'seo_settings.share_media_id' => ['prohibited'],
            'theme_settings' => ['nullable', 'array'],
        ]);

        $page = $this->page();
        $pageSettings = array_replace_recursive($page->page_settings ?? [], $data['page_settings'] ?? []);
        if (array_key_exists('featured_catalog_codes', $data['page_settings'] ?? [])) {
            // Replace the ordered list as a whole; recursive merging would retain removed entries.
            $pageSettings['featured_catalog_codes'] = array_values(array_unique(array_filter($data['page_settings']['featured_catalog_codes'], fn ($code) => is_string($code) && $code !== '')));
        }
        $page->update([
            'site_name' => $data['site_name'],
            'page_settings' => $pageSettings,
            'seo_settings' => array_replace_recursive($page->seo_settings ?? [], $data['seo_settings'] ?? []),
            'theme_settings' => array_replace_recursive($page->theme_settings ?? [], $data['theme_settings'] ?? []),
        ]);

        return back()->with('cms_tab', in_array($request->input('_cms_tab'), ['settings', 'booking-page'], true) ? $request->input('_cms_tab') : 'settings')->with('status', 'Page, SEO, dan theme settings berhasil disimpan.');
    }

    public function storeSection(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:150'],
            'subtitle' => ['nullable', 'string', 'max:200'],
            'icon' => ['nullable', 'string', 'max:80'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ]);

        $this->page()->sections()->create($data + ['is_active' => true, 'content' => [], 'items' => []]);

        return back()->with('cms_tab', 'sections')->with('status', 'Section baru berhasil ditambahkan.');
    }

    public function updateSection(Request $request, LandingSection $section): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'subtitle' => ['nullable', 'string', 'max:200'],
            'icon' => ['nullable', 'string', 'max:80'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'content' => ['nullable', 'array'],
            'items' => ['nullable', 'array'],
        ]);

        $section->update($data + ['is_active' => false]);

        return back()->with('cms_tab', 'sections')->with('status', 'Section berhasil diperbarui.');
    }

    public function destroySection(LandingSection $section): RedirectResponse
    {
        $section->delete();

        return back()->with('cms_tab', 'sections')->with('status', 'Section berhasil dihapus.');
    }

    public function storeMedia(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', 'string', 'max:40'],
            'url' => ['nullable', 'required_without:file', 'prohibits:file', 'string', 'max:1000', self::safeUrl(...)],
            'file' => ['nullable', 'required_without:url', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=480,min_height=300,max_width=4200,max_height=4200'],
            'alt_text' => ['nullable', 'required_with:file', 'string', 'max:200'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        if ($request->hasFile('file')) {
            $data['url'] = LandingImage::store($request->file('file'));
            $data['type'] = 'image';
        }
        unset($data['file']);

        $this->page()->media()->create($data + ['is_active' => false]);

        return back()->with('cms_tab', 'media')->with('status', 'Media berhasil ditambahkan.');
    }

    public function destroyMedia(LandingMedia $media): RedirectResponse
    {
        $media->delete();

        return back()->with('cms_tab', 'media')->with('status', 'Media berhasil dihapus.');
    }

    public function publish(): RedirectResponse
    {
        $page = $this->page();
        $editor = $this->editor($page);
        if (in_array(false, $editor['checks'], true)) {
            return back()->with('cms_tab', 'preview')->withErrors(['publish' => 'Lengkapi butir yang belum siap sebelum menerbitkan beranda.']);
        }
        if (! $page->is_published) {
            $page->update(['is_published' => true, 'published_at' => now()]);
        }

        return back()->with('cms_tab', 'preview')->with('status', 'Beranda sudah terbit dan bisa dibuka pengunjung.');
    }

    public function preview(Request $request): View
    {
        $device = $request->string('device', 'desktop')->toString();

        return view('admin.landing.preview', [
            'device' => in_array($device, ['desktop', 'tablet', 'mobile'], true) ? $device : 'desktop',
            'editor' => $this->editor($this->page()),
        ]);
    }

    private function editor(LandingPageSetting $page): array
    {
        $page->loadMissing(['sections', 'media']);
        $page = clone $page;
        // The preview and readiness check reflect exactly the sections visitors will see.
        $page->setRelation('sections', $page->sections->where('is_active', true)->values());
        $landing = app(LandingPresentation::class)->forPage($page);
        $seo = PublicSeo::home($page, $landing['contact']);

        return $landing + [
            'seo' => $seo,
            'sections' => $page->sections->keyBy('type'),
            'images' => $page->media->filter(fn ($media) => $media->is_active && $media->type === 'image' && LandingPresentation::url($media->url)),
            'checks' => [
                'Judul dan pengantar beranda' => filled($landing['hero']['headline']) && filled($landing['hero']['description']),
                'Latar pembuka dan gambar berbagi' => ($landing['hero']['video']
                    ? collect($landing['hero']['video'])->every(fn ($asset) => $this->imageAvailable($asset))
                    : $this->imageAvailable($landing['hero']['image'])) && $this->imageAvailable($seo['image']),
                'Nama usaha, kota, alamat, dan nomor WhatsApp' => filled($landing['contact']['name']) && filled($landing['contact']['city']) && filled($landing['contact']['address']) && filled($landing['contact']['phone']),
                'Judul dan deskripsi untuk pencarian' => filled($seo['title']) && filled($seo['description']),
            ],
        ];
    }

    private function imageAvailable(string $url): bool
    {
        $path = rawurldecode(parse_url($url, PHP_URL_PATH) ?: '');
        if (preg_match('/[\x00-\x1f\\\\]/', $path) || in_array('..', explode('/', $path), true)) {
            return false;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if ($host && ! in_array($host, [parse_url(config('app.url'), PHP_URL_HOST), parse_url(asset('/'), PHP_URL_HOST)], true)) {
            // External images are checked visually by the editor; the server never fetches them.
            return true;
        }
        if (str_starts_with($path, '/storage/')) {
            return Storage::disk('public')->exists(substr($path, 9));
        }
        if (str_starts_with($path, '/images/') || str_starts_with($path, '/videos/')) {
            return is_file(public_path(ltrim($path, '/')));
        }

        return filled($url);
    }

    private function updatePublicSection(LandingPageSetting $page, string $type, string $title, int $order, array $content, ?array $items = null): void
    {
        $section = $page->sections()->where('type', $type)->where('is_active', true)->get()->last()
            ?? $page->sections()->where('type', $type)->get()->last()
            ?? $page->sections()->make(['type' => $type, 'title' => $title, 'sort_order' => $order]);
        $section->fill(['content' => array_replace($section->content ?? [], $content), 'is_active' => true]);
        if ($items !== null) {
            $section->items = $items;
        }
        $section->save();
    }

    private static function safeUrl(string $attribute, mixed $value, \Closure $fail): void
    {
        if (! LandingPresentation::url($value)) {
            $fail('Gunakan tautan HTTP(S) yang valid atau path gambar lokal.');
        }
    }

    private function page(): LandingPageSetting
    {
        return LandingPageSetting::query()->firstOrCreate(
            ['slug' => 'home'],
            ['site_name' => 'Gayatri Mom & Baby SPA', 'is_published' => false]
        );
    }
}
