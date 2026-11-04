<?php

namespace App\Services\CRM;

use App\Models\Conversation;
use App\Models\Followup;
use App\Models\LaundryOrder;
use App\Models\LaundryPaymentProof;
use App\Models\RentalOrder;
use App\Models\RentalPaymentProof;
use App\Support\BookingStatus;

/** Current work queues, independent of the report period. No gateway requests or writes. */
final class OperationalOverview
{
    public function counts(): array
    {
        $bookings = app(BookingReports::class)->bookings();
        $laundry = LaundryOrder::query()->where('is_demo', false)->where('status', '!=', 'cancelled');
        $rentals = RentalOrder::query()->where('is_demo', false);

        return [
            'bookings_pending' => (clone $bookings)->whereIn('status', ['draft', 'pending', 'pending_confirmation'])->count(),
            'bookings_today' => (clone $bookings)->whereDate('booking_date', today())->whereIn('status', BookingStatus::active())->count(),
            'needs_admin' => Conversation::where('ai_needs_attention', true)->whereHas('customer', fn ($q) => $q->where('name', 'not like', '[DEMO]%'))->count(),
            'followups_due' => Followup::where('status', 'open')->where('due_at', '<', today()->addDay())->whereHas('customer', fn ($q) => $q->where('name', 'not like', '[DEMO]%'))->count(),
            'laundry_overdue' => (clone $laundry)->whereNotIn('workflow_status', ['ready', 'handed_over', 'cancelled'])->where('estimated_ready_at', '<', now())->count(),
            'laundry_qc' => (clone $laundry)->where('workflow_status', 'quality_check')->count(),
            'laundry_ready' => (clone $laundry)->where('workflow_status', 'ready')->count(),
            'laundry_proofs' => LaundryPaymentProof::where('status', 'pending')->whereHas('order', fn ($q) => $q->where('is_demo', false))->count(),
            'rental_overdue' => (clone $rentals)->where('status', 'rented')->whereDate('end_date', '<', today())->whereHas('items', fn ($q) => $q->whereNull('returned_at'))->count(),
            'rental_inspection' => (clone $rentals)->where('status', 'inspection')->count(),
            'rental_proofs' => RentalPaymentProof::where('status', 'pending')->whereHas('order', fn ($q) => $q->where('is_demo', false))->count(),
        ];
    }
}
