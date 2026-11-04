<?php

namespace App\Services\CRM;

use App\Models\User;
use App\Services\AppSettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LaundryTransportPricing
{
    public function __construct(private readonly AppSettingService $settings, private readonly AuditLogService $audit) {}

    public function settings(): array
    {
        return array_replace(['enabled' => false, 'origin_latitude' => null, 'origin_longitude' => null, 'mode' => 'flat', 'max_distance_km' => '15', 'pickup_flat_fee' => 10000, 'delivery_flat_fee' => 10000, 'pickup_rate_per_km' => 2500, 'delivery_rate_per_km' => 2500, 'minimum_fee' => 5000], json_decode($this->settings->get('laundry.transport', '{}'), true) ?: []);
    }

    public function save(array $data, User $actor, Request $request): void
    {
        LaundryActivity::authorize($actor);
        DB::transaction(function () use ($data, $actor, $request) {
            $before = $this->settings();
            $this->settings->set('laundry.transport', json_encode($data));
            $this->audit->log($actor, 'laundry.transport.settings', null, $request, $before, $data, 'Pengaturan ongkir laundry tersimpan.');
        });
    }

    public function quote(array $data, ?array $settings = null): array
    {
        $settings ??= $this->settings();
        if (! $settings['enabled']) {
            throw ValidationException::withMessages(['transport' => 'Aktifkan layanan antar-jemput melalui Pengaturan Ongkir Laundry.']);
        }
        if ($data['distance_source'] === 'manual') {
            if (mb_strlen(trim($data['distance_note'] ?? '')) < 5 || ! isset($data['distance_km'])) {
                throw ValidationException::withMessages(['distance_km' => 'Isi jarak jalan dan catatan sumber pengukurannya minimal 5 karakter.']);
            }
            $distance = LaundryPricing::milli($data['distance_km']);
        } else {
            foreach (['origin_latitude', 'origin_longitude'] as $key) {
                if ($settings[$key] === null || $settings[$key] === '') {
                    throw ValidationException::withMessages(['transport' => 'Lengkapi koordinat outlet sebelum menghitung jarak dari koordinat.']);
                }
            }
            if (! isset($data['latitude'], $data['longitude'])) {
                throw ValidationException::withMessages(['latitude' => 'Isi titik lokasi tujuan atau pilih jarak jalan manual.']);
            }
            $lat1 = deg2rad((float) $settings['origin_latitude']);
            $lat2 = deg2rad((float) $data['latitude']);
            $a = sin(($lat2 - $lat1) / 2) ** 2 + cos($lat1) * cos($lat2) * sin(deg2rad((float) $data['longitude'] - (float) $settings['origin_longitude']) / 2) ** 2;
            $distance = (int) ceil(6371 * 2 * atan2(sqrt(min(1, $a)), sqrt(max(0, 1 - $a))) * 1000);
        }
        $distance = (int) (ceil($distance / 100) * 100);
        if ($distance > LaundryPricing::milli($settings['max_distance_km'])) {
            throw ValidationException::withMessages(['distance_km' => 'Tujuan berada di luar batas jarak layanan laundry.']);
        }
        $kind = $data['kind'];
        $fee = $settings['mode'] === 'flat' ? (int) $settings[$kind.'_flat_fee'] : max((int) $settings['minimum_fee'], intdiv($distance * (int) $settings[$kind.'_rate_per_km'] + 999, 1000));

        return ['kind' => $kind, 'fee' => $fee, 'distance_milli' => $distance, 'distance_source' => $data['distance_source'], 'distance_note' => $data['distance_note'] ?? null, 'address' => $data['address'], 'latitude' => $data['latitude'] ?? null, 'longitude' => $data['longitude'] ?? null, 'policy' => $settings, 'quoted_at' => now()->toIso8601String()];
    }
}
