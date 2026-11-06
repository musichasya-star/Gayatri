<?php

namespace App\Services\AI;

use App\Models\LaundryTariff;
use App\Models\RentalProduct;
use App\Models\RetailProduct;
use App\Models\Service;
use App\Services\CRM\HomeService;
use App\Services\CRM\LaundryPublicSettings;
use App\Services\CRM\LaundrySettings;
use App\Services\CRM\RentalSettings;
use App\Support\AiDomain;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AiServiceDirectory
{
    private ?array $domainOverrides = null;

    public function withDomains(?array $domains): self
    {
        $copy = clone $this;
        $copy->domainOverrides = $domains;

        return $copy;
    }

    public function facts(string $domain): array
    {
        $data = ['domain' => $domain, 'base_url' => config('app.url'), 'items' => [], 'enabled' => true];
        $aiDomains = $this->domainOverrides ?? data_get(app(AiChatCustomization::class)->settings(), 'service_domains', []);
        if (in_array($domain, ['spa', 'lactation'], true)) {
            $data['items'] = Service::where('is_active', true)->where('service_group', $domain)->orderBy('id')
                ->get(['id', 'name', 'description', 'duration_minutes', 'price', 'catalog_family', 'variant_label', 'booking_mode', 'catalog_metadata'])->toArray();
            $home = app(HomeService::class)->settings();
            $data['home'] = array_intersect_key($home, array_flip(['enabled', 'rate_per_km', 'max_distance_km', 'base_fee', 'free_km', 'service_ids']));
            $data['home']['ready'] = $home['enabled'] && $home['latitude'] !== null && $home['longitude'] !== null
                && $home['rate_per_km'] !== null && ! empty($home['max_distance_km']);
        } elseif ($domain === 'rental') {
            $settings = app(RentalSettings::class)->get();
            $data['enabled'] = (bool) $settings['enabled'];
            $data['delivery_enabled'] = (bool) $settings['delivery_enabled'];
            $data['items'] = RentalProduct::where('is_active', true)->orderBy('id')
                ->get(['id', 'name', 'daily_rate', 'weekly_rate', 'fortnightly_rate', 'monthly_rate', 'deposit'])->toArray();
        } elseif ($domain === 'laundry') {
            $outlet = app(LaundrySettings::class)->get();
            $data['enabled'] = (bool) (app(LaundryPublicSettings::class)->get()['enabled']
                && $outlet['accepting_orders'] && trim($outlet['address']) && trim($outlet['whatsapp_number']));
            $data['items'] = LaundryTariff::where('is_active', true)->orderBy('id')
                ->get(['id', 'name', 'unit', 'price', 'minimum_milli', 'duration_hours'])->toArray();
        } else {
            foreach (array_values(AiDomain::MENU) as $key) {
                $data['services'][$key] = $this->facts($key);
            }
            $data['retail'] = RetailProduct::where('is_active', true)->orderBy('id')->get(['id', 'name', 'price', 'compare_at_price'])->toArray();
        }
        $data['ai_enabled'] = ($aiDomains[$domain] ?? true) === true;

        return $data;
    }

    public function fingerprint(string $domain): string
    {
        return hash('sha256', json_encode($this->facts($domain), JSON_THROW_ON_ERROR));
    }

    public function url(string $domain, ?int $product = null): ?string
    {
        $facts = $this->facts($domain);
        if (! $facts['enabled'] || ! $facts['ai_enabled']) {
            return null;
        }
        $base = rtrim((string) config('app.url'), '/');
        $url = parse_url($base);
        $host = strtolower($url['host'] ?? '');
        if (! $host || ! in_array($url['scheme'] ?? '', ['http', 'https'], true)
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || (app()->isProduction() && (($url['scheme'] ?? '') !== 'https'
                || in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]', '0.0.0.0'], true)))) {
            return null;
        }
        $route = match ($domain) {
            'spa', 'lactation' => $product && ($selected = collect($facts['items'])->firstWhere('id', $product)) && (($selected['booking_mode'] ?? 'online') === 'admin' || $selected['duration_minutes'] === null || $selected['price'] === null)
                ? route('catalogue.index', ['group' => $domain, 'search' => $selected['catalog_family'] ?: $selected['name']], false)
                : route('landing.booking-page', array_filter(['service_group' => $domain === 'lactation' ? 'lactation' : null, 'service_id' => $product]), false),
            'rental' => $product && collect($facts['items'])->contains('id', $product)
                ? route('rental.show', $product, false) : route('rental.index', [], false),
            'laundry' => route('laundry.index', [], false),
            'general' => route('catalogue.index', ['type' => 'retail'], false),
            default => null,
        };

        return $route ? $base.$route : null;
    }

    public function direction(string $domain, ?int $product = null): string
    {
        $link = $this->url($domain, $product);

        return $link ? AiDomain::LABELS[$domain].': '.$link
            : (AiDomain::LABELS[$domain] ?? 'Layanan').': pemesanan web belum tersedia. Ketik admin untuk bantuan.';
    }

    public function selection(string $domain, string $message, array $state = []): ?int
    {
        $items = $this->facts($domain)['items'];
        $text = app(AiMessageUnderstanding::class)->normalize($message);
        if (preg_match('/\b(semua|seluruh|daftar|katalog|pilihan lain|alat lain|layanan lain)\b/u', $text)) {
            return null;
        }
        $matches = $this->matchingItems($items, $text);
        if ($matches->count() === 1) {
            return (int) $matches->first()['id'];
        }
        if ($matches->isEmpty() && ! preg_match('/\b(?:\d+\s*(?:sesi|kunjungan|menit)|baby|toddler|kid|private|reguler|premium|advance|shower|berendam|bidan|dokter|weekday|weekend)\b/u', $text)
            && ($state['domain'] ?? null) === $domain && preg_match('/\b(itu|tadi|tersebut|harganya|berapa|booking|reservasi|pesan)\b/u', $text)
            && collect($items)->contains('id', $state['catalog_item_id'] ?? null)) {
            return (int) $state['catalog_item_id'];
        }

        return null;
    }

    public function matchingItems(array $items, string $text): Collection
    {
        $normalize = fn ($value) => Str::squish(preg_replace('/[^\pL\pN]+/u', ' ', app(AiMessageUnderstanding::class)->normalize(preg_replace('/^\[DEMO\]\s*/iu', '', $value))));
        $text = $normalize($text);
        $normalized = collect($items)->map(fn ($item) => $item + ['match_name' => $normalize($item['name']), 'match_family' => $normalize($item['catalog_family'] ?? $item['name'])]);
        $families = $normalized->filter(fn ($item) => Str::contains(' '.$text.' ', ' '.$item['match_family'].' '));
        if ($families->isNotEmpty()) {
            // A named family such as Pijat Laktasi Premium takes precedence over its shorter prefix.
            $longest = $families->max(fn ($item) => mb_strlen($item['match_family']));
            $matches = $families->filter(fn ($item) => mb_strlen($item['match_family']) === $longest);
        } else {
            $scored = $normalized->map(function ($item) use ($text) {
                $words = array_unique(array_filter(explode(' ', $item['match_name']), fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, ['demo', 'bunda', 'laundry', 'konsultasi', 'treatment', 'menit', 'sesi', 'tahun', 'bulan', 'per'], true)));
                $item['match_score'] = count(array_filter($words, fn ($w) => Str::contains(' '.$text.' ', ' '.$w.' ')));

                return $item;
            });
            $best = $scored->max('match_score') ?? 0;
            $matches = $best > 0 ? $scored->where('match_score', $best) : collect();
        }
        if (preg_match_all('/\b(baby|toddler|kid|private|reguler|premium|advance|shower|berendam|bidan|dokter|weekday|weekend)\b/u', $text, $modifiers)) {
            foreach (array_unique($modifiers[1]) as $modifier) {
                $matches = $matches->filter(fn ($item) => Str::contains(' '.$item['match_name'].' ', ' '.$modifier.' '));
            }
        }
        if (preg_match('/\b(\d+)\s*(?:sesi|kunjungan)\b/u', $text, $quantity)) {
            $matches = $matches->filter(fn ($item) => (int) data_get($item, 'catalog_metadata.package_quantity', 1) === (int) $quantity[1]);
        }
        if (preg_match('/\b(\d+)\s*(?:menit|min)\b/u', $text, $duration)) {
            $matches = $matches->filter(fn ($item) => isset($item['duration_minutes']) && (int) $item['duration_minutes'] === (int) $duration[1]);
        }

        return $matches;
    }

    public function catalog(string $domain, ?int $selected = null, string $message = ''): string
    {
        $facts = $this->facts($domain);
        $clean = app(AiPublicContent::class);
        if (! $facts['enabled'] || ! $facts['ai_enabled']) {
            return $this->direction($domain);
        }
        if (! $facts['items']) {
            return 'Katalog aktif '.(AiDomain::LABELS[$domain] ?? 'layanan').' belum tersedia. Ketik admin untuk informasi yang tepat.';
        }
        $lines = [];
        $items = $selected ? array_values(array_filter($facts['items'], fn ($item) => $item['id'] === $selected)) : $facts['items'];
        $text = app(AiMessageUnderstanding::class)->normalize($message);
        if (! $selected && $message !== '' && ! preg_match('/\b(semua|seluruh|daftar|katalog|pilihan lain|alat lain|layanan lain)\b/u', $text)) {
            $matching = $this->matchingItems($items, $text);
            if ($matching->isNotEmpty()) {
                $items = $matching->values()->all();
            } elseif (preg_match('/\b(?:\d+\s*(?:sesi|kunjungan|menit)|baby|toddler|kid|private|reguler|premium|advance|shower|berendam|bidan|dokter|weekday|weekend)\b/u', $text)) {
                return 'Pilihan persis yang Bunda sebutkan belum ditemukan di katalog aktif. Admin dapat membantu memastikan variasinya sebelum pemesanan.'."\n".$this->direction($domain);
            }
        }
        foreach (array_slice($items, 0, 8) as $item) {
            $name = $clean->clean($item['name'], 140);
            $lines[] = match ($domain) {
                'spa', 'lactation' => '- '.$name.': '.($item['duration_minutes'] === null ? 'durasi dikonfirmasi admin' : $item['duration_minutes'].' menit').', '.($item['price'] === null ? 'harga dikonfirmasi admin' : $this->money($item['price']))
                    .(($item['booking_mode'] ?? 'online') === 'admin' ? '; pilihan/jadwal paket dikonfirmasi admin' : ''),
                'rental' => '- '.$name.': '.((float) $item['daily_rate'] > 0 ? $this->money($item['daily_rate']).'/hari' : 'tarif harian belum tersedia')
                    .((float) ($item['weekly_rate'] ?? 0) > 0 ? ', '.$this->money($item['weekly_rate']).'/minggu' : '')
                    .((float) ($item['fortnightly_rate'] ?? 0) > 0 ? ', '.$this->money($item['fortnightly_rate']).'/2 minggu' : '')
                    .((float) ($item['monthly_rate'] ?? 0) > 0 ? ', '.$this->money($item['monthly_rate']).'/bulan' : '')
                    .'; deposit '.($item['deposit'] === null ? 'dikonfirmasi admin' : $this->money($item['deposit'])),
                'laundry' => '- '.$name.': '.$this->money($item['price']).'/'.($item['unit'] === 'kg' ? 'kg' : 'potong')
                    .($item['minimum_milli'] === null ? ', minimum dikonfirmasi admin' : ', minimum '.rtrim(rtrim(number_format($item['minimum_milli'] / 1000, 3, '.', ''), '0'), '.').' '.($item['unit'] === 'kg' ? 'kg' : 'potong'))
                    .($item['duration_hours'] === null ? ', estimasi selesai dikonfirmasi admin' : ', estimasi '.$item['duration_hours'].' jam'),
                default => '',
            };
        }

        $more = count($items) > 8 && $this->url($domain) ? "\nMasih ada pilihan lain; seluruh variasi tersedia di katalog: ".rtrim((string) config('app.url'), '/').route('catalogue.index', ['type' => $domain === 'rental' ? 'rental' : ($domain === 'laundry' ? 'laundry' : 'services')], false) : '';
        if ($selected && in_array($domain, ['spa', 'lactation'], true) && ! empty($items[0]['description'])) {
            $lines[] = $clean->clean($items[0]['description'], 450);
        }

        return implode("\n", $lines).$more."\nTarif katalog saat ini. Rincian akhir, ongkir, jadwal dan ketersediaan divalidasi pada pemesanan.\n".$this->direction($domain, $selected);
    }

    public function money($amount): string
    {
        return 'Rp'.number_format((float) $amount, 0, ',', '.');
    }
}
