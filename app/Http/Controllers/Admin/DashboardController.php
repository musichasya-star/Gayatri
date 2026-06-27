<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiLog;
use App\Models\Booking;
use App\Models\Campaign;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Feedback;
use App\Models\Followup;
use App\Models\Message;
use App\Models\WhatsAppSession;
use App\Support\BookingStatus;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        [$start, $end, $rangeLabel] = $this->dateRange($request);
        $bookingBase = Booking::query()->whereBetween('booking_date', [$start->toDateString(), $end->toDateString()]);
        $revenueBookings = (clone $bookingBase)
            ->with('service')
            ->whereNotIn('status', [BookingStatus::CANCELLED, BookingStatus::NO_SHOW])
            ->get();

        $estimatedRevenue = $revenueBookings->sum(fn (Booking $booking) => max(0, (float) ($booking->service?->price ?? 0) - (float) $booking->promo_discount));
        $trend = $this->trend($start, $end);

        return view('admin.dashboard', [
            'range' => $request->string('range', 'today')->toString(),
            'rangeLabel' => $rangeLabel,
            'startDate' => $start->toDateString(),
            'endDate' => $end->toDateString(),
            'totalCustomers' => Customer::count(),
            'newCustomers' => Customer::whereBetween('created_at', [$start, $end])->count(),
            'incomingChats' => Message::where('direction', 'incoming')->whereBetween('created_at', [$start, $end])->count(),
            'unansweredChats' => Conversation::where('unread_count', '>', 0)->count(),
            'bookingsToday' => Booking::whereDate('booking_date', today())->count(),
            'bookingsWeek' => Booking::whereBetween('booking_date', [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()])->count(),
            'bookingsMonth' => Booking::whereBetween('booking_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->count(),
            'bookingsInRange' => (clone $bookingBase)->count(),
            'estimatedRevenue' => $estimatedRevenue,
            'activeCampaigns' => Campaign::whereIn('status', ['scheduled', 'running'])->count(),
            'wahaDisconnected' => WhatsAppSession::whereNotIn('status', ['working', 'connected'])->count(),
            'failedOutgoingMessages' => Message::where('direction', 'outgoing')->whereNotNull('failed_at')->count(),
            'aiAnswered' => AiLog::where('status', 'success')->whereBetween('created_at', [$start, $end])->count(),
            'humanTakeover' => AiLog::where('status', 'escalated')->whereBetween('created_at', [$start, $end])->count(),
            'averageRating' => round((float) Feedback::whereNotNull('rating')->whereBetween('created_at', [$start, $end])->avg('rating'), 1),
            'feedbackCount' => Feedback::whereNotNull('rating')->whereBetween('created_at', [$start, $end])->count(),
            'complaintCount' => Feedback::where('status', 'escalated')->whereBetween('created_at', [$start, $end])->count(),
            'trend' => $trend,
            'todayBookings' => Booking::with(['customer', 'service'])->whereDate('booking_date', today())->orderBy('start_time')->limit(5)->get(),
            'pendingFollowups' => Followup::with('customer')->where('status', 'open')->orderBy('due_at')->limit(5)->get(),
        ]);
    }

    private function dateRange(Request $request): array
    {
        return match ($request->string('range', 'today')->toString()) {
            'week' => [now()->startOfWeek(), now()->endOfWeek(), 'Minggu Ini'],
            'month' => [now()->startOfMonth(), now()->endOfMonth(), 'Bulan Ini'],
            'custom' => [
                $request->date('start_date')?->startOfDay() ?? now()->startOfDay(),
                $request->date('end_date')?->endOfDay() ?? now()->endOfDay(),
                'Custom Range',
            ],
            default => [now()->startOfDay(), now()->endOfDay(), 'Hari Ini'],
        };
    }

    private function trend($start, $end): array
    {
        $days = collect(CarbonPeriod::create($start->copy()->startOfDay(), $end->copy()->startOfDay()))->take(31);
        $rows = $days->map(function ($date) {
            $bookingCount = Booking::whereDate('booking_date', $date)->count();
            $chatCount = Message::where('direction', 'incoming')->whereDate('created_at', $date)->count();

            return ['label' => $date->format('d M'), 'bookings' => $bookingCount, 'chats' => $chatCount, 'total' => $bookingCount + $chatCount];
        });
        $max = max(1, $rows->max('total'));

        return $rows->map(fn ($row) => $row + ['height' => max(8, (int) round(($row['total'] / $max) * 100))])->all();
    }
}
