<?php

namespace App\Services\CRM;

use App\Services\AppSettingService;

class RentalSettings
{
    public function __construct(private readonly AppSettingService $settings) {}

    public function get(): array
    {
        return array_replace([
            'enabled' => true, 'hold_hours' => 24, 'max_rental_days' => 180, 'delivery_enabled' => false, 'ops_lookahead_days' => 7,
            'dp_type' => 'none', 'dp_value' => 0, 'dp_due_hours' => 24, 'settlement_days_before' => 0, 'require_full_payment' => false, 'payment_instructions' => '',
            'rental_terms' => 'Gunakan alat sesuai petunjuk dan batas penggunaan produk. Periksa kondisi serta kelengkapan bersama petugas saat serah-terima. Kembalikan alat pada tanggal yang disepakati. Perpanjangan berlaku setelah disetujui admin. Biaya kerusakan atau kehilangan ditinjau berdasarkan hasil pemeriksaan. Pengembalian deposit diproses setelah pemeriksaan dan penyelesaian tagihan.',
            'late_fee_daily' => 0, 'late_fee_grace_days' => 0, 'late_fee_cap' => 0,
            'cancellation_fee_type' => 'none', 'cancellation_fee_value' => 0, 'cancellation_free_days' => 0,
            'office_name' => 'Gayatri', 'office_address' => '', 'latitude' => null, 'longitude' => null,
            'delivery_rate_per_km' => 0, 'delivery_base_fee' => 0, 'collection_rate_per_km' => 0, 'collection_base_fee' => 0,
            'free_km' => 0, 'max_distance_km' => 20, 'routing_provider' => 'osrm', 'routing_base_url' => '',
        ], json_decode($this->settings->get('rental.settings', '{}'), true) ?: []);
    }

    public function save(array $data): void
    {
        $this->settings->set('rental.settings', json_encode($data));
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->get()));
    }
}
