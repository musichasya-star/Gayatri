<?php

namespace App\Console\Commands;

use App\Services\CRM\LaundryNotificationService;
use Illuminate\Console\Command;

class SendLaundryReminders extends Command
{
    protected $signature = 'crm:send-laundry-reminders';

    protected $description = 'Menyiapkan pengingat sisa tagihan laundry sesuai mode dan batas pengiriman';

    public function handle(LaundryNotificationService $service): int
    {
        $this->info('Notifikasi laundry disiapkan: '.$service->reminders());

        return self::SUCCESS;
    }
}
