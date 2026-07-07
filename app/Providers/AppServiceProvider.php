<?php

namespace App\Providers;

use App\Models\AiAutomationApproval;
use App\Models\Booking;
use App\Services\AppSettingService;
use App\Support\BookingStatus;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
        });
    }
}
