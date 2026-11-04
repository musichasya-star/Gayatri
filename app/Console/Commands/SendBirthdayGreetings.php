<?php

namespace App\Console\Commands;

use App\Services\CRM\BirthdayGreetingService;
use Illuminate\Console\Command;

class SendBirthdayGreetings extends Command
{
    protected $signature = 'crm:send-birthday-greetings';

    protected $description = 'Queue due WhatsApp birthday greetings once per customer/type/year';

    public function handle(BirthdayGreetingService $service): int
    {
        $count = $service->dispatchDue();
        $this->info("{$count} ucapan ulang tahun ditambahkan ke antrean.");

        return self::SUCCESS;
    }
}
