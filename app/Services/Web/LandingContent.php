<?php

namespace App\Services\Web;

use App\Models\LandingPageSetting;
use App\Models\LandingSection;
use App\Models\Service;
use Illuminate\Support\Collection;

/** Public content selection without changing prices, availability or publication state. */
final class LandingContent
{
    public static function candidates(): Collection
    {
        return Service::where('is_active', true)->whereNotNull('catalog_code')->orderBy('service_group')->orderBy('catalog_family')->orderBy('name')->get()->filter(self::eligible(...));
    }

    public static function eligible(Service $service): bool
    {
        return $service->is_active && filled($service->catalog_code)
            && ! str_starts_with(mb_strtoupper($service->name), '[DEMO]')
            && in_array($service->service_group, ['spa', 'lactation'], true)
            && in_array($service->catalogueKind(), ['treatment', 'class'], true);
    }

    public function featured(LandingPageSetting $page, Collection $services): Collection
    {
        $settings = $page->page_settings ?: [];
        $codes = array_key_exists('featured_catalog_codes', $settings) ? $settings['featured_catalog_codes'] : config('gayatri_frontend.featured_catalog_codes', []);
        if (! is_array($codes)) {
            return collect();
        }
        $available = $services->filter(self::eligible(...))->keyBy('catalog_code');

        return collect($codes)->filter(fn ($code) => is_string($code) && $code !== '')->unique()->take(6)
            ->map(fn ($code) => $available->get($code))->filter()->values();
    }

    public static function image(string $role): array
    {
        $spec = config('gayatri_frontend.images.'.$role);

        return ['image_url' => asset('images/landing/'.$spec['file'].'.webp'),
            'srcset' => asset('images/landing/'.$spec['file'].'-480w.webp').' 480w, '
                .($role === 'hero' ? asset('images/landing/'.$spec['file'].'-768w.webp').' 768w, ' : '')
                .asset('images/landing/'.$spec['file'].'.webp').' '.($role === 'hero' ? 1536 : 960).'w',
            'alt' => $spec['alt'], 'title' => ['spa' => 'Pijat & Spa', 'lactation' => 'Laktasi & ASI', 'rental' => 'Perlengkapan bayi', 'laundry' => 'Laundry ibu & bayi', 'hero' => 'Bunda dan si kecil'][$role],
            'caption' => 'Ilustrasi foto · AI', 'illustration' => true];
    }

    public function faq(?LandingSection $section): Collection
    {
        $items = collect($section?->items ?: []);
        $legacyTitles = ['Apakah harus booking dulu?', 'Apakah aman untuk newborn?', 'Bagaimana pembayaran?'];

        return empty($section?->content['editorial']) && $items->pluck('title')->all() === $legacyTitles ? collect(config('gayatri_frontend.faq')) : $items;
    }

    public function gallery(?LandingSection $section): Collection
    {
        if (! $section || empty($section->items)) {
            return collect();
        }
        $custom = collect($section->items ?: [])->filter(fn ($item) => LandingPresentation::url($item['image_url'] ?? '') && ! str_contains($item['image_url'], 'images.unsplash.com'));
        if ($custom->isNotEmpty()) {
            return $custom->take(4)->map(fn ($item) => ['image_url' => $item['image_url'], 'srcset' => '', 'alt' => $item['title'] ?? 'Gambar layanan Gayatri', 'title' => $item['title'] ?? 'Layanan Gayatri',
                'illustration' => (bool) ($item['is_illustration'] ?? false), 'caption' => ! empty($item['is_illustration']) ? 'Ilustrasi layanan' : null]);
        }

        return collect(['spa', 'lactation', 'rental', 'laundry'])->map(self::image(...));
    }
}
