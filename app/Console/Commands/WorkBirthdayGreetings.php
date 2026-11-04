<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class WorkBirthdayGreetings extends Command
{
    protected $signature = 'crm:work-birthdays {--once : Run one local scheduling pass}';

    protected $description = 'Run the birthday-only local scheduler without starting demo campaigns or reminders';

    public function handle(): int
    {
        do {
            try {
                $this->call('crm:send-birthday-greetings');
            } catch (Throwable $exception) {
                Log::error('Birthday scheduler failed', ['error' => $exception->getMessage()]);
                $this->error('Scheduler ulang tahun gagal; periksa log aplikasi.');
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
