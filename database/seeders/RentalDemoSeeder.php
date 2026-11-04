<?php

namespace Database\Seeders;

use App\Models\RentalProduct;
use App\Models\RentalUnit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RentalDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing', 'baileys-local']) || DB::connection()->getDriverName() !== 'sqlite') {
            throw new RuntimeException('Demo rental hanya untuk database SQLite lokal/pengujian.');
        }
        foreach ([['DEMO-STROLLER', '[DEMO] Stroller Bayi', 'Stroller', 25000, 140000, 500000, 100000, 2], ['DEMO-CHAIR', '[DEMO] Kursi Makan Balita', 'Kursi Makan', 20000, 100000, 350000, 75000, 1]] as [$sku, $name, $category, $daily, $weekly, $monthly, $deposit, $count]) {
            $product = RentalProduct::firstOrCreate(['sku' => $sku], ['name' => $name, 'category' => $category, 'description' => 'DATA DEMO LOKAL. Harga dan perlengkapan contoh untuk mencoba alur rental; ganti dengan data operasional Anda.', 'components' => 'Perlengkapan sesuai daftar pemeriksaan unit.', 'terms' => 'Contoh: periksa kondisi dan kelengkapan bersama petugas sebelum dan setelah masa sewa.', 'daily_rate' => $daily, 'weekly_rate' => $weekly, 'monthly_rate' => $monthly, 'deposit' => $deposit, 'turnaround_days' => 1, 'is_active' => true]);
            for ($index = 1; $index <= $count; $index++) {
                RentalUnit::firstOrCreate(['code' => $sku.'-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT)], ['rental_product_id' => $product->id, 'status' => 'ready', 'condition_notes' => 'Unit demo lokal, kondisi dan kelengkapan contoh.', 'last_inspected_at' => now(), 'is_demo' => true]);
            }
        }
    }
}
