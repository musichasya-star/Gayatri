<?php

use App\Services\CRM\CampaignService;
use App\Services\CRM\ReminderService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('crm:send-due-reminders', function (ReminderService $reminderService) {
    $count = $reminderService->dispatchDue();
    $this->info("Dispatched {$count} due reminder(s).");
})->purpose('Dispatch due booking reminders');

Artisan::command('crm:send-due-campaigns', function (CampaignService $campaignService) {
    $count = $campaignService->dispatchDue();
    $this->info("Dispatched {$count} campaign recipient(s).");
})->purpose('Dispatch scheduled campaign recipients');

Schedule::command('crm:send-due-reminders')->everyMinute()->withoutOverlapping();
Schedule::command('crm:send-due-campaigns')->everyMinute()->withoutOverlapping();
