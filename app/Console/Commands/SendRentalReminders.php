<?php

namespace App\Console\Commands;

use App\Models\RentalOrder;
use App\Services\CRM\RentalNotificationService;
use Illuminate\Console\Command;

class SendRentalReminders extends Command
{
    protected $signature = 'crm:send-rental-reminders {--dry-run : Inspect scheduling without queuing or sending messages}';

    protected $description = 'Queue due rental return reminders and overdue notices once per order per date';

    public function handle(RentalNotificationService $service): int
    {
        if ($this->option('dry-run')) {
            $settings = $service->settings();
            $count = RentalOrder::where('status', 'rented')->whereHas('items', fn ($q) => $q->whereNull('returned_at'))->whereDate('end_date', '<=', today()->addDays((int) $settings['reminder_days']))->count();
            $this->info('Mode simulasi: '.$count.' transaksi berpotensi diingatkan. Notifikasi '.($settings['enabled'] ? 'aktif' : 'nonaktif').'. Tidak ada pesan dimasukkan ke antrean.');

            return self::SUCCESS;
        }
        $this->info($service->dispatchDue().' notifikasi rental ditambahkan ke antrean.');

        return self::SUCCESS;
    }
}
