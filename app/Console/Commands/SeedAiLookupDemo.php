<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\LaundryOrder;
use App\Models\LaundryPublicRequest;
use App\Models\RentalOrder;
use App\Models\Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SeedAiLookupDemo extends Command
{
    protected $signature = 'ai:seed-lookup-demo';

    protected $description = 'Create isolated, inactive local customer profiles and read-only demo transaction examples';

    public function handle(): int
    {
        if (! app()->environment('local', 'baileys-local', 'testing')) {
            $this->error('Data contoh hanya boleh dibuat pada lingkungan lokal/pengujian.');

            return self::FAILURE;
        }
        DB::transaction(function () {
            foreach (['A' => '6280000000901', 'B' => '6280000000902'] as $label => $phone) {
                $name = '[DEMO] AI Status '.$label;
                $customer = Customer::firstOrCreate(['whatsapp_number' => $phone], ['name' => $name, 'status' => 'inactive']);
                if ($customer->name !== $name || $customer->status !== 'inactive') {
                    throw new \RuntimeException('Nomor contoh sudah dipakai data lain; tidak ada data yang diganti.');
                }
                $snapshot = ['name' => $name, 'whatsapp_number' => $phone];
                $codes = [];
                foreach (['spa' => 'SPA', 'lactation' => 'ASI'] as $domain => $suffix) {
                    $service = Service::firstOrCreate(['name' => '[DEMO] Uji status '.$suffix],
                        ['service_group' => $domain, 'price' => 100000, 'duration_minutes' => 60, 'is_active' => false]);
                    $code = 'BK-DEMO-C-'.$suffix.'-'.$label;
                    if (! Booking::where('booking_code', $code)->exists()) {
                        $booking = new Booking(['booking_code' => $code, 'customer_id' => $customer->id, 'service_id' => $service->id,
                            'booking_date' => '2026-11-02', 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'completed',
                            'payment_status' => $domain === 'spa' ? 'paid' : 'unpaid', 'source' => 'demo', 'service_mode' => 'outlet',
                            'service_price_snapshot' => 100000, 'finance_managed' => false]);
                        $booking->forceFill(['is_demo' => true])->save();
                    }
                    $codes[] = $code;
                }
                $code = 'RENT-DEMO-C-'.$label;
                RentalOrder::firstOrCreate(['code' => $code], ['quote_id' => (string) Str::uuid(), 'request_session_hash' => hash('sha256', $code),
                    'customer_id' => $customer->id, 'source' => 'admin', 'status' => 'completed', 'start_date' => '2026-10-01', 'end_date' => '2026-10-07',
                    'period' => 'weekly', 'period_count' => 1, 'fulfilment' => 'self', 'rental_subtotal' => 70000, 'deposit_total' => 0,
                    'pricing_snapshot' => ['demo' => true], 'customer_snapshot' => $snapshot, 'is_demo' => true]);
                $codes[] = $code;
                $code = 'LND-DEMO-C-'.$label;
                LaundryOrder::firstOrCreate(['code' => $code], ['entry_key' => (string) Str::uuid(), 'input_hash' => hash('sha256', $code),
                    'customer_id' => $customer->id, 'customer_snapshot' => $snapshot, 'outlet_snapshot' => ['name' => 'Outlet DEMO'],
                    'received_at' => now()->subDay(), 'estimated_ready_at' => now()->addDay(), 'intake_mode' => 'walkin',
                    'status' => 'awaiting_approval', 'workflow_status' => 'pending', 'estimated_total' => 24000, 'actual_total' => 28000, 'is_demo' => true]);
                $codes[] = $code;
                $uuid = $label === 'A' ? 'c0000001-0000-4000-8000-000000000001' : 'c0000002-0000-4000-8000-000000000002';
                LaundryPublicRequest::firstOrCreate(['public_id' => $uuid], ['entry_key' => (string) Str::uuid(), 'input_hash' => hash('sha256', $uuid),
                    'customer_id' => $customer->id, 'name' => $name, 'whatsapp_number' => $phone, 'fulfilment' => 'dropoff', 'items' => [],
                    'estimate' => ['demo' => true], 'outlet_snapshot' => ['name' => 'Outlet DEMO'], 'status' => 'pending',
                    'portal_expires_at' => now()->addDays(7), 'is_demo' => true]);
                $codes[] = $uuid;
                DB::table('ai_demo_profiles')->insertOrIgnore(['label' => $name, 'customer_id' => $customer->id, 'phone' => $phone,
                    'examples' => json_encode($codes), 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $this->info('Dua profil DEMO dan masing-masing lima contoh status tersedia di simulator. Tidak ada pesan atau pembayaran dikirim/dibuat.');

        return self::SUCCESS;
    }
}
