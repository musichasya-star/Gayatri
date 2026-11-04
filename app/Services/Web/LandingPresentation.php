<?php

namespace App\Services\Web;

use App\Models\LandingPageSetting;
use App\Services\CRM\CatalogueImport;
use App\Services\CRM\LaundryPublicSettings;
use App\Services\CRM\LaundrySettings;

/** Read-only presentation defaults for the public homepage. */
final class LandingPresentation
{
    public function forPage(LandingPageSetting $page): array
    {
        $sections = $page->sections->keyBy('type');
        $hero = $sections->get('hero');
        $content = $hero?->content ?: [];
        $headline = trim($content['headline'] ?? $hero?->title ?? '');
        if (in_array($headline, ['', 'Hero', 'Hero Section', 'Perawatan Lembut untuk Bunda dan Buah Hati'], true)) {
            $headline = 'Sentuhan hangat untuk Bunda dan si kecil.';
        }
        $description = trim($content['description'] ?? '');
        if (! $description || str_contains(mb_strtolower($description), 'soft gold')) {
            $description = 'Dari perawatan tubuh dan pendampingan menyusui, hingga perlengkapan dan pakaian si kecil. Temukan layanan Gayatri yang sesuai untuk keluarga Bunda.';
        }
        $media = isset($content['media_id']) ? $page->media->first(fn ($asset) => $asset->is_active && $asset->type === 'image' && $asset->id === (int) $content['media_id']) : null;
        $mediaUrl = self::url($media?->url);
        $image = $mediaUrl ?: self::url($content['image_url'] ?? '');
        if (! $image || str_contains($image, 'images.unsplash.com/photo-1519823551278-64ac92734fb1') || str_contains($image, '/hero-family-v1.webp')) {
            $image = LandingContent::image('hero')['image_url'];
        }

        $outlet = app(LaundrySettings::class)->get();
        $public = app(LaundryPublicSettings::class)->get();
        $natural = str_contains($image, '/hero-natural-v2.webp');
        $useVideo = ($content['background_mode'] ?? ($natural && ! $mediaUrl ? 'video' : 'image')) === 'video';

        return [
            'hero' => ['headline' => $headline, 'description' => $description, 'image' => $image,
                'video' => $useVideo ? config('gayatri_frontend.hero_video') : null,
                'alt' => ($mediaUrl ? $media->alt_text : null) ?: ($content['image_alt'] ?? ($natural ? LandingContent::image('hero')['alt'] : 'Gambar layanan Gayatri')),
                'srcset' => $natural ? LandingContent::image('hero')['srcset'] : LandingImage::srcset($image),
                'illustration' => $natural || str_contains($image, 'hero-family-v1.webp'),
                'caption' => $natural ? 'Ilustrasi foto · AI' : 'Ilustrasi suasana perawatan'],
            'laundryOpen' => (bool) ($public['enabled'] && $outlet['accepting_orders'] && trim($outlet['address']) && trim($outlet['whatsapp_number'])),
            'contact' => $this->contact($page),
        ];
    }

    public function contact(?LandingPageSetting $page): array
    {
        $catalogue = app(CatalogueImport::class)->manifest()['source'];
        $sections = $page?->sections->keyBy('type') ?? collect();
        $contact = $sections->get('location_contact')?->content ?: [];
        $settings = $page?->page_settings ?: [];
        $phone = self::phone($settings['whatsapp'] ?? '') ?: self::phone($contact['whatsapp'] ?? '');
        $phone ??= self::phone($catalogue['catalog_contact'] ?? '');
        $address = trim($contact['address'] ?? '');
        if (in_array(mb_strtolower($address), ['', 'jl. mawar no. 10, jakarta selatan'], true)) {
            $address = trim($catalogue['address'] ?? '');
        }
        $maps = self::url($contact['maps_url'] ?? '');
        if ($maps && in_array(strtolower(parse_url($maps, PHP_URL_HOST) ?? ''), ['maps.google.com', 'www.google.com', 'google.com'], true)
            && in_array(parse_url($maps, PHP_URL_PATH), [null, '', '/', '/maps', '/maps/'], true) && ! parse_url($maps, PHP_URL_QUERY)) {
            $maps = null;
        }
        if (! $maps && $address) {
            $maps = 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($address);
        }
        $socials = collect($sections->get('footer')?->items ?: [])->filter(fn ($item) => str_contains(mb_strtolower($item['label'] ?? ''), 'instagram'));
        $instagram = self::url($socials->first()['url'] ?? '') ?: self::url(config('gayatri_frontend.instagram'));
        if ($instagram && (! in_array(strtolower(parse_url($instagram, PHP_URL_HOST) ?? ''), ['instagram.com', 'www.instagram.com'], true) || in_array(parse_url($instagram, PHP_URL_PATH), ['', '/'], true))) {
            $instagram = null;
        }

        return ['name' => $page?->site_name ?: 'Gayatri Mom & Baby SPA',
            'city' => trim($settings['business_city'] ?? '') ?: 'Kediri',
            'phone' => $phone, 'whatsapp' => $phone ? 'https://wa.me/'.$phone : null,
            'address' => $address, 'maps' => $maps, 'instagram' => $instagram,
            'hours' => trim($contact['opening_hours'] ?? '') ?: trim($catalogue['opening_hours'] ?? '')];
    }

    public static function url(mixed $value): ?string
    {
        if (! is_string($value) || ! trim($value) || preg_match('/[\x00-\x20\\\\]/', $value)) {
            return null;
        }
        if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_URL) && in_array(strtolower(parse_url($value, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true)
            && parse_url($value, PHP_URL_USER) === null && parse_url($value, PHP_URL_PASS) === null ? $value : null;
    }

    public static function phone(mixed $value): ?string
    {
        $phone = preg_replace('/\D+/', '', (string) $value);
        if (str_starts_with($phone, '0')) {
            $phone = '62'.substr($phone, 1);
        }

        return preg_match('/^62[1-9]\d{7,12}$/D', $phone) && ! in_array($phone, ['6281234567890', '628123456789'], true) ? $phone : null;
    }
}
