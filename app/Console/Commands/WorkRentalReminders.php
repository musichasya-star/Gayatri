<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class WorkRentalReminders extends Command
{
    protected $signature = 'crm:work-rentals {--once : Run one local rental scheduling pass}';

    protected $description = 'Run the rental-only local reminder scheduler';

    public function handle(): int
    {
        do {
            try {
                $this->call('crm:send-rental-reminders');
            } catch (Throwable $e) {
                Log::error('Rental scheduler failed', ['error' => $e->getMessage()]);
                $this->error('Scheduler rental gagal; periksa log aplikasi.');
                if ($this->option('once')) {
                    return self::FAILURE;
                }
            }
            if (! $this->option('once')) {
                sleep(60);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}
