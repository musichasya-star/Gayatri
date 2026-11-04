<?php

namespace App\Services\CRM;

use App\Models\Booking;
use App\Models\Service;
use App\Models\Therapist;
use App\Services\AppSettingService;
use App\Support\BookingStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

class HomeService
{
    public function __construct(private readonly AppSettingService $settings) {}

    public function settings(): array
    {
        return array_replace([
            'enabled' => false, 'office_name' => 'Kantor Gayatri', 'office_address' => '',
            'latitude' => null, 'longitude' => null, 'rate_per_km' => null, 'max_distance_km' => null,
            'base_fee' => 0, 'free_km' => 0, 'buffer_minutes' => 15,
            'service_ids' => [], 'therapist_ids' => [], 'routing_provider' => 'osrm', 'routing_base_url' => '',
        ], json_decode($this->settings->get('home_service.settings', '{}'), true) ?: []);
    }

    public static function rules(): array
    {
        return [
            'service_mode' => ['nullable', 'in:clinic,home'],
            'visit_address' => ['nullable', 'required_if:service_mode,home', 'string', 'min:10', 'max:2000'],
            'visit_landmark' => ['nullable', 'string', 'max:500'],
            'visit_latitude' => ['nullable', 'required_if:service_mode,home', 'numeric', 'between:-90,90'],
            'visit_longitude' => ['nullable', 'required_if:service_mode,home', 'numeric', 'between:-180,180'],
            'home_quote_token' => ['nullable', 'string', 'max:12000'],
        ];
    }

    public function quote(float $latitude, float $longitude, int $serviceId): array
    {
        $config = $this->settings();
        $this->assertAvailable($config, $serviceId);
        if (! is_finite($latitude) || ! is_finite($longitude) || abs($latitude) > 90 || abs($longitude) > 180) {
            throw new InvalidArgumentException('Koordinat tujuan tidak valid.');
        }
        $destination = ['latitude' => round($latitude, 7), 'longitude' => round($longitude, 7)];
        $key = 'home-route:'.hash('sha256', json_encode([$config['latitude'], $config['longitude'], $destination, $config['routing_provider'], $config['routing_base_url']]));
        $route = Cache::remember($key, now()->addMinutes(10), fn () => $this->route($config, $destination));
        if ($route['distance_meters'] > (float) $config['max_distance_km'] * 1000) {
            throw new InvalidArgumentException('Alamat di luar jangkauan Home Service (maksimal '.$config['max_distance_km'].' km melalui jalan).');
        }
        $billableKm = (int) ceil(max(0, $route['distance_meters'] - (float) $config['free_km'] * 1000) / 1000);
        $snapshot = array_merge($route, $destination, [
            'treatment_price' => (float) Service::findOrFail($serviceId)->price,
            'addon_prices' => Service::findOrFail($serviceId)->activeAddOns->pluck('price_adjustment', 'id')->map(fn ($price) => (float) $price)->all(),
            'service_id' => $serviceId, 'office_name' => $config['office_name'], 'office_address' => $config['office_address'],
            'origin_latitude' => (float) $config['latitude'], 'origin_longitude' => (float) $config['longitude'],
            'rate_per_km' => (int) $config['rate_per_km'], 'base_fee' => (int) $config['base_fee'],
            'free_km' => (float) $config['free_km'], 'max_distance_km' => (float) $config['max_distance_km'],
            'billable_km' => $billableKm, 'travel_fee' => (int) $config['base_fee'] + $billableKm * (int) $config['rate_per_km'],
            'buffer_minutes' => (int) $config['buffer_minutes'], 'routing_provider' => $config['routing_provider'],
            'config_hash' => $this->fingerprint($config), 'expires_at' => now()->addMinutes(15)->timestamp,
        ]);

        return ['quote' => $snapshot, 'token' => Crypt::encryptString(json_encode($snapshot))];
    }

    private function assertAvailable(array $config, int $serviceId): void
    {
        if (! $config['enabled'] || $config['latitude'] === null || $config['longitude'] === null || $config['rate_per_km'] === null || empty($config['max_distance_km'])) {
            throw new InvalidArgumentException('Home Service belum diaktifkan atau belum dikonfigurasi admin.');
        }
        if (! in_array($serviceId, array_map('intval', $config['service_ids']), true) || ! Service::query()->bookable()->whereKey($serviceId)->where('is_active', true)->exists()) {
            throw new InvalidArgumentException('Layanan ini tidak tersedia untuk Home Service.');
        }
        if (! Therapist::whereIn('id', $config['therapist_ids'])->where('status', 'active')->exists()) {
            throw new InvalidArgumentException('Belum ada terapis aktif untuk Home Service.');
        }
    }

    public function route(array $config, array $destination): array
    {
        try {
            $baseUrl = $config['routing_provider'] === 'osrm_private' ? rtrim($config['routing_base_url'], '/') : 'https://router.project-osrm.org';
            if (blank($baseUrl)) {
                throw new InvalidArgumentException('Endpoint rute OSRM belum diisi admin.');
            }
            $coordinates = implode(',', [(float) $config['longitude'], (float) $config['latitude']]).';'.implode(',', [$destination['longitude'], $destination['latitude']]);
            $response = Http::acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(15)->withUserAgent('Gayatri-HomeService/1.0')
                ->get($baseUrl.'/route/v1/driving/'.$coordinates, ['overview' => 'false', 'radiuses' => '150;150'])->throw();
            if ($response->json('code') !== 'Ok') {
                throw new InvalidArgumentException('Rute jalan menuju lokasi tidak ditemukan. Geser pin ke akses jalan terdekat.');
            }
            $meters = $response->json('routes.0.distance');
            $seconds = $response->json('routes.0.duration');
            if (! is_numeric($meters) || ! is_numeric($seconds) || $meters < 0 || $seconds < 0 || ! is_finite((float) $meters) || ! is_finite((float) $seconds)) {
                throw new InvalidArgumentException('Penyedia peta tidak mengembalikan rute yang valid.');
            }

            return ['distance_meters' => (int) ceil($meters), 'duration_seconds' => (int) ceil($seconds)];
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new InvalidArgumentException('Perhitungan rute sedang tidak tersedia. Silakan coba lagi atau hubungi admin.');
        }
    }

    public function attributes(array $data, Service $service, ?Booking $booking = null): array
    {
        $mode = $data['service_mode'] ?? $booking?->service_mode ?? 'clinic';
        if ($mode !== 'home') {
            return ['service_mode' => 'clinic', 'visit_address' => null, 'visit_landmark' => null, 'visit_latitude' => null, 'visit_longitude' => null, 'travel_fee' => 0, 'home_service_quote' => null, 'service_price_snapshot' => null];
        }
        $address = trim($data['visit_address'] ?? $booking?->visit_address ?? '');
        $lat = $data['visit_latitude'] ?? $booking?->visit_latitude;
        $lng = $data['visit_longitude'] ?? $booking?->visit_longitude;
        if (mb_strlen($address) < 10 || $lat === null || $lng === null || ! is_numeric($lat) || ! is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            throw new InvalidArgumentException('Isi alamat lengkap dan tentukan pin tujuan Home Service.');
        }
        $preserve = $booking?->service_mode === 'home' && $booking->service_id === $service->id && abs((float) $booking->visit_latitude - $lat) < 0.00000005 && abs((float) $booking->visit_longitude - $lng) < 0.00000005 && blank($data['home_quote_token'] ?? null);
        if ($preserve) {
            $quote = $booking->home_service_quote;
        } else {
            $this->assertAvailable($this->settings(), $service->id);
            try {
                $quote = json_decode(Crypt::decryptString($data['home_quote_token'] ?? ''), true, flags: JSON_THROW_ON_ERROR);
                if (($quote['expires_at'] ?? 0) < now()->timestamp || ($quote['config_hash'] ?? '') !== $this->fingerprint($this->settings()) || ($quote['service_id'] ?? null) !== $service->id || abs(($quote['latitude'] ?? 999) - $lat) > 0.00000005 || abs(($quote['longitude'] ?? 999) - $lng) > 0.00000005) {
                    throw new InvalidArgumentException;
                }
            } catch (Throwable) {
                throw new InvalidArgumentException('Hitung ulang ongkos Home Service sebelum menyimpan booking (estimasi berlaku 15 menit).');
            }
        }
        $therapistId = $data['therapist_id'] ?? null;
        if ($therapistId && (! $preserve || (int) $booking?->therapist_id !== (int) $therapistId)) {
            if (! in_array((int) $therapistId, array_map('intval', $this->settings()['therapist_ids']), true) || ! Therapist::whereKey($therapistId)->where('status', 'active')->exists()) {
                throw new InvalidArgumentException('Terapis yang dipilih tidak melayani Home Service.');
            }
        }
        if (($data['status'] ?? '') === BookingStatus::CONFIRMED && ! $therapistId) {
            throw new InvalidArgumentException('Pilih terapis Home Service sebelum mengonfirmasi booking.');
        }

        return ['service_mode' => 'home', 'visit_address' => $address, 'visit_landmark' => $data['visit_landmark'] ?? $booking?->visit_landmark,
            'visit_latitude' => $lat, 'visit_longitude' => $lng, 'travel_fee' => $quote['travel_fee'], 'home_service_quote' => $quote,
            'service_price_snapshot' => $preserve ? $booking->service_price_snapshot : $quote['treatment_price']];
    }

    public function busyWindow(string $date, string $start, string $end, ?array $quote): array
    {
        $minutes = $quote ? (int) ceil($quote['duration_seconds'] / 60) + (int) $quote['buffer_minutes'] : 0;

        return ['busy_from' => Carbon::parse($date.' '.$start)->subMinutes($minutes), 'busy_until' => Carbon::parse($date.' '.$end)->addMinutes($minutes)];
    }

    private function fingerprint(array $config): string
    {
        return hash('sha256', json_encode($config));
    }
}
