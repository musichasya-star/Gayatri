<?php

namespace App\Services\CRM;

use App\Models\User;
use App\Services\AppSettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LaundryPublicSettings
{
    public function __construct(private readonly AppSettingService $settings, private readonly AuditLogService $audit) {}

    public function get(): array
    {
        return array_replace(['enabled' => false, 'intro' => 'Laundry pakaian ibu dan bayi, dengan tarif transparan serta pilihan antar-jemput.', 'bank_name' => '', 'account_number' => '', 'account_holder' => '', 'payment_instructions' => 'Transfer setelah hasil timbang dan total tagihan disetujui. Unggah bukti untuk diperiksa admin.', 'portal_days' => 30, 'portal_base_url' => ''], json_decode($this->settings->get('laundry.public', '{}'), true) ?: []);
    }

    public function save(array $data, User $actor, Request $request): void
    {
        LaundryActivity::authorize($actor);
        DB::transaction(function () use ($data, $actor, $request) {
            $before = $this->get();
            $this->settings->set('laundry.public', json_encode($data));
            $this->audit->log($actor, 'laundry.public.settings', null, $request, $before, $data, 'Pengaturan pemesanan dan pembayaran manual laundry tersimpan.');
        });
    }
}
