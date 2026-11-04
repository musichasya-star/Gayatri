<?php

namespace App\Providers;

use App\Models\AiAutomationApproval;
use App\Models\Booking;
use App\Services\AI\AiChatCustomization;
use App\Services\AppSettingService;
use App\Services\WhatsApp\BaileysService;
use App\Services\WhatsApp\GatewayConfig;
use App\Services\WhatsApp\QueueMonitor;
use App\Services\WhatsApp\WahaService;
use App\Services\WhatsApp\WhatsAppGateway;
use App\Support\BookingStatus;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(WhatsAppGateway::class, function ($app) {
            // Queue workers are long-lived: resolve freshly saved gateway settings per job.
            $app->make(AppSettingService::class)->applyToConfig();

            return $app->make(GatewayConfig::provider() === 'baileys' ? BaileysService::class : WahaService::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Queue::looping(fn ($event) => QueueMonitor::heartbeat($event->connectionName, $event->queue));
        app(AppSettingService::class)->applyToConfig();
        Paginator::defaultView('vendor.pagination.gayatri');

        View::composer('partials.topbar', function ($view) {
            $incomingBookingCount = Booking::query()
                ->whereIn('status', [BookingStatus::DRAFT, BookingStatus::PENDING, BookingStatus::PENDING_CONFIRMATION])
                ->where('created_at', '>=', now()->subDay())
                ->count();
            $incomingApprovalCount = AiAutomationApproval::query()
                ->where('status', 'pending')
                ->whereIn('action', ['reschedule_booking', 'cancel_booking'])
                ->count();

            $view->with('topbarIncomingBookingCount', $incomingBookingCount + $incomingApprovalCount);
            $view->with('topbarAiEnabled', app(AiChatCustomization::class)->settings()['enabled']);
        });
    }
}
