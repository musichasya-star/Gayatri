<?php

namespace App\Services\Web;

use App\Models\LandingMedia;
use App\Models\LandingPageSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class PublicSeo
{
    public const CATALOGUES = [
        '' => ['Katalog Layanan Gayatri', 'Jelajahi pilihan pijat dan spa, konsultasi ASI, sewa perlengkapan bayi, dan laundry.'],
        'spa' => ['Pijat & Spa Ibu dan Anak', 'Lihat pilihan pijat dan spa Gayatri, variasi layanan, harga, dan cara memesan.'],
        'lactation' => ['Laktasi & Konsultasi ASI', 'Kenali layanan pendampingan menyusui Gayatri dan pilihan konsultasi ASI.'],
        'laundry' => ['Laundry Ibu & Bayi', 'Lihat tarif dan pilihan laundry pakaian ibu dan bayi serta cara mengajukan pesanan.'],
        'retail' => ['Produk Pendukung Ibu & Bayi', 'Kenali produk pendukung untuk kebutuhan ibu dan bayi serta cara menanyakan ketersediaannya.'],
    ];

    public static function home(LandingPageSetting $page, array $contact, bool $preview = false): array
    {
        $settings = $page->seo_settings ?? [];
        $title = self::plain($settings['meta_title'] ?? null);
        $description = self::plain($settings['meta_description'] ?? null);
        $title = $title === 'Gayatri Mom & Baby SPA - Baby Spa & Mom Care Premium' ? '' : $title;
        $description = str_contains(mb_strtolower($description), 'soft gold brown') ? '' : $description;
        $image = $page->media->first(fn (LandingMedia $media) => $media->is_active && $media->type === 'image'
            && $media->id === (int) ($settings['share_media_id'] ?? 0));
        $sharePath = $image ? LandingImage::share($image->url) ?? LandingPresentation::url($image->url) : null;
        $meta = self::metadata(
            $title ?: $page->site_name.' | '.$contact['city'],
            $description ?: 'Pijat dan spa ibu dan anak, konsultasi ASI, sewa perlengkapan bayi, dan laundry Gayatri di '.$contact['city'].'.',
            $contact, $preview ? null : self::absolute(route('landing.show', [], false)),
            $sharePath, $image?->alt_text,
        );
        if (! $preview && $contact['address'] && $contact['phone']) {
            $business = [
                '@context' => 'https://schema.org', '@type' => 'LocalBusiness',
                '@id' => $meta['canonical'].'#gayatri',
                'name' => $contact['name'], 'url' => $meta['canonical'],
                'image' => $meta['image'], 'telephone' => '+'.$contact['phone'],
                'address' => ['@type' => 'PostalAddress', 'streetAddress' => $contact['address'], 'addressLocality' => $contact['city'], 'addressCountry' => 'ID'],
            ];
            if ($contact['instagram']) {
                $business['sameAs'] = [$contact['instagram']];
            }
            $meta['schema'] = json_encode($business, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        return $meta + ['customTitle' => $title, 'customDescription' => $description];
    }

    public static function page(Request $request, string $title, string $description, array $contact): array
    {
        $canonical = null;
        $query = array_filter(
            array_diff_key($request->query(), array_flip(['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid'])),
            fn ($value) => $value !== null && $value !== '',
        );
        if ($request->routeIs('catalogue.index')) {
            $catalogue = match (true) {
                $query === [], $query === ['type' => 'services'] => '',
                $query === ['group' => 'spa'], count($query) === 2 && ($query['type'] ?? null) === 'services' && ($query['group'] ?? null) === 'spa' => 'spa',
                $query === ['group' => 'lactation'], count($query) === 2 && ($query['type'] ?? null) === 'services' && ($query['group'] ?? null) === 'lactation' => 'lactation',
                $query === ['type' => 'laundry'] => 'laundry',
                $query === ['type' => 'retail'] => 'retail',
                default => null,
            };
            if ($catalogue !== null) {
                [$title, $description] = self::CATALOGUES[$catalogue];
                $canonical = self::catalogueUrl($catalogue);
            }
        } elseif (($request->routeIs('rental.index') && $query === [])
            || ($request->routeIs('rental.show') && array_diff_key($query, ['return_to' => true]) === [] && ! $request->session()->hasOldInput())) {
            $canonical = self::absolute(route($request->route()->getName(), $request->route()->parameters(), false));
        }
        if ($canonical) {
            $title = $title.' | '.$contact['city'];
        }

        return self::metadata($title, $description, $contact, $canonical);
    }

    public static function catalogueUrl(string $catalogue): string
    {
        $query = match ($catalogue) {
            'spa', 'lactation' => ['group' => $catalogue],
            'laundry', 'retail' => ['type' => $catalogue],
            default => [],
        };

        return self::absolute(route('catalogue.index', $query, false));
    }

    public static function absolute(string $url): string
    {
        return str_starts_with($url, '/') ? rtrim(config('app.url'), '/').$url : $url;
    }

    private static function metadata(string $title, string $description, array $contact, ?string $canonical, ?string $image = null, ?string $alt = null): array
    {
        return [
            'title' => Str::limit(self::plain($title), 100),
            'description' => Str::limit(self::plain($description), 180),
            'canonical' => $canonical,
            'name' => $contact['name'],
            'image' => self::absolute($image ?: '/images/landing/gayatri-share.jpg'),
            'imageAlt' => $alt ?: 'Ilustrasi suasana perawatan Gayatri',
        ];
    }

    private static function plain(mixed $value): string
    {
        return is_string($value) ? Str::squish(strip_tags($value)) : '';
    }
}
