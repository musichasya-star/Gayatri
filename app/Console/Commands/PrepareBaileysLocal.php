<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Console\Command;

class PrepareBaileysLocal extends Command
{
    protected $signature = 'whatsapp:prepare-local';

    protected $description = 'Prepare isolated SQLite demo data for local Baileys testing';

    public function handle(): int
    {
        if (! app()->environment(['local', 'baileys-local']) || config('database.default') !== 'sqlite'
            || realpath(config('database.connections.sqlite.database')) !== realpath(storage_path('app/baileys-local.sqlite'))) {
            $this->error('Perintah ini hanya untuk database SQLite Baileys local. Gunakan --env=baileys-local.');

            return self::FAILURE;
        }
        if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }
        if (! User::exists() && $this->call('db:seed', ['--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }
        $demoDay = now('Asia/Jakarta');
        Customer::firstOrCreate(['name' => 'Demo Ulang Tahun Lokal'], [
            'birth_date' => '1992-'.$demoDay->format('m-d'),
            'baby_name' => 'Ananda Demo', 'baby_birth_date' => '2020-'.$demoDay->copy()->addDays(3)->format('m-d'),
            'status' => 'active', 'tags' => ['demo-ulang-tahun'],
            'notes' => 'Data contoh lokal tanpa nomor WhatsApp; tidak bisa menerima pengiriman otomatis.',
        ]);
        $this->info('Database local siap; gateway Baileys dan AI local aktif.');

        return self::SUCCESS;
    }
}
