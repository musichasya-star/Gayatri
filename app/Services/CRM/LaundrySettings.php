<?php

namespace App\Services\CRM;

use App\Models\User;
use App\Services\AppSettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LaundrySettings
{
    public function __construct(private readonly AppSettingService $settings, private readonly AuditLogService $audit) {}

    public function get(): array
    {
        return array_replace(['accepting_orders' => false, 'outlet_name' => 'Gayatri Laundry Ibu & Bayi', 'address' => '', 'whatsapp_number' => '', 'opening_hours' => '', 'terms' => '', 'allow_collection' => false], json_decode($this->settings->get('laundry.outlet', '{}'), true) ?: []);
    }

    public function save(array $data, User $actor, Request $request): void
    {
        DB::transaction(function () use ($data, $actor, $request) {
            $before = $this->get();
            $this->settings->set('laundry.outlet', json_encode($data, JSON_UNESCAPED_UNICODE));
            $this->audit->log($actor, 'laundry.outlet.update', null, $request, $before, $data, 'Pengaturan outlet laundry diperbarui.');
        });
    }

    public function requireOpen(): array
    {
        $settings = $this->get();
        if (! $settings['accepting_orders'] || ! trim($settings['address']) || ! trim($settings['whatsapp_number'])) {
            throw ValidationException::withMessages(['laundry' => 'Lengkapi alamat dan kontak outlet, lalu aktifkan penerimaan pesanan melalui Pengaturan Outlet.']);
        }

        return $settings;
    }
}
