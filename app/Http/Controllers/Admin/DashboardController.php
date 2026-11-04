<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiAutomationApproval;
use App\Models\AiLog;
use App\Models\Booking;
use App\Models\Campaign;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Feedback;
use App\Models\Followup;
use App\Models\Message;
use App\Models\WhatsAppSession;
use App\Services\CRM\BookingReports;
use App\Services\CRM\InboxAccess;
use App\Services\CRM\OperationalOverview;
use App\Support\BookingStatus;
use App\Support\ReportPeriod;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        if ($request->user()->hasRole(['therapist', 'sales'])) {
            return view('admin.workspace', ['count' => InboxAccess::query($request->user())->count()]);
        }
        [$start, $end, $rangeLabel] = $this->dateRange($request);
        $reports = app(BookingReports::class);
        $bookingBase = $reports->period($reports->bookings(), 'booking_date', $start, $end);
        $revenueBookings = (clone $bookingBase)
            ->with(['service', 'addOns'])
            ->whereNotIn('status', [BookingStatus::CANCELLED, BookingStatus::CANCELLED_BY_USER, BookingStatus::NO_SHOW])
            ->lazyById(200);

        $estimatedRevenue = $revenueBookings->sum(fn (Booking $booking) => $booking->totalPrice());
        $trend = $this->trend($start, $end);
        $incomingBookingStatuses = [BookingStatus::DRAFT, BookingStatus::PENDING, BookingStatus::PENDING_CONFIRMATION];
        $incomingBookings = Booking::with(['customer', 'service'])
            ->whereIn('status', $incomingBookingStatuses)
            ->latest()
            ->limit(5)
            ->get();
        $incomingApprovals = AiAutomationApproval::with('customer')
            ->where('status', 'pending')
            ->whereIn('action', ['reschedule_booking', 'cancel_booking'])
            ->latest()
            ->limit(5)
            ->get();

        return view('admin.dashboard', [
            'work' => app(OperationalOverview::class)->counts(),
            'openFollowupCount' => Followup::where('status', 'open')->count(),
            'historicalEstimateCount' => (clone $bookingBase)->whereNull('service_price_snapshot')->whereNotIn('status', [BookingStatus::CANCELLED, BookingStatus::CANCELLED_BY_USER, BookingStatus::NO_SHOW])->count(),
            'range' => $request->string('range', 'today')->toString(),
            'rangeLabel' => $rangeLabel,
            'startDate' => $start->toDateString(),
            'endDate' => $end->toDateString(),
            'totalCustomers' => Customer::count(),
            'newCustomers' => Customer::whereBetween('created_at', [$start, $end])->count(),
            'incomingChats' => Message::where('direction', 'incoming')->whereBetween('created_at', [$start, $end])->count(),
            'unansweredChats' => Conversation::where('unread_count', '>', 0)->count(),
            'bookingsToday' => $reports->period($reports->bookings(), 'booking_date', today(), today())->count(),
            'bookingsWeek' => $reports->period($reports->bookings(), 'booking_date', now()->startOfWeek(), now()->endOfWeek())->count(),
            'bookingsMonth' => $reports->period($reports->bookings(), 'booking_date', now()->startOfMonth(), now()->endOfMonth())->count(),
            'bookingsInRange' => (clone $bookingBase)->count(),
            'estimatedRevenue' => $estimatedRevenue,
            'bookingCash' => $reports->summary($start, $end, ['payments'])['payments'],
            'activeCampaigns' => Campaign::whereIn('status', ['scheduled', 'running'])->count(),
            'wahaDisconnected' => WhatsAppSession::whereNotIn('status', ['working', 'connected'])->count(),
            'failedOutgoingMessages' => Message::where('direction', 'outgoing')->whereNotNull('failed_at')->whereNull('sent_at')->whereNull('wa_message_id')->whereNull('delivered_at')->whereNull('read_at')->where(fn ($q) => $q->whereDoesntHave('customer')->orWhereHas('customer', fn ($c) => $c->where('name', 'not like', '[DEMO]%')))->count(),
            'aiAnswered' => AiLog::where('status', 'success')->whereBetween('created_at', [$start, $end])->count(),
            'humanTakeover' => AiLog::where('status', 'escalated')->whereBetween('created_at', [$start, $end])->count(),
            'averageRating' => round((float) Feedback::whereNotNull('rating')->whereBetween('created_at', [$start, $end])->avg('rating'), 1),
            'feedbackCount' => Feedback::whereNotNull('rating')->whereBetween('created_at', [$start, $end])->count(),
            'complaintCount' => Feedback::where('status', 'escalated')->whereBetween('created_at', [$start, $end])->count(),
            'trend' => $trend,
            'incomingBookingCount' => Booking::whereIn('status', $incomingBookingStatuses)->count(),
            'incomingApprovalCount' => AiAutomationApproval::where('status', 'pending')->whereIn('action', ['reschedule_booking', 'cancel_booking'])->count(),
            'incomingBookings' => $incomingBookings,
            'incomingApprovals' => $incomingApprovals,
            'todayBookings' => $reports->bookings()->with(['customer', 'service'])->whereDate('booking_date', today())->orderBy('start_time')->limit(5)->get(),
            'pendingFollowups' => Followup::with('customer')->where('status', 'open')->orderBy('due_at')->limit(5)->get(),
        ]);
    }

    private function dateRange(Request $request): array
    {
        $request->validate(['range' => ['nullable', Rule::in(['today', 'week', 'month', 'custom'])]]);
        $range = $request->input('range', 'today');
        [$defaultStart,$defaultEnd,$label] = match ($range) {
            'week' => [now()->startOfWeek(), now()->endOfWeek(), 'Minggu Ini'],'month' => [now()->startOfMonth(), now()->endOfMonth(), 'Bulan Ini'],'custom' => [today(), today(), 'Custom Range'],default => [today(), today(), 'Hari Ini']
        };
        if ($range === 'custom') {
            [$start,$end] = ReportPeriod::dates($request, $defaultStart, $defaultEnd);
        } else {
            $start = $defaultStart;
            $end = $defaultEnd;
        }

        return [$start->copy()->startOfDay(), $end->copy()->endOfDay(), $label];
    }

    private function trend($start, $end): array
    {
        $reports = app(BookingReports::class);
        $bookings = $reports->period($reports->bookings(), 'booking_date', $start, $end)->selectRaw('DATE(booking_date) AS day, COUNT(*) AS total')->groupBy('day')->pluck('total', 'day');
        $chats = $reports->period(Message::where('direction', 'incoming'), 'created_at', $start, $end)->selectRaw('DATE(created_at) AS day, COUNT(*) AS total')->groupBy('day')->pluck('total', 'day');
        $rows = [];
        $monthly = $start->diffInDays($end) > 31;
        foreach (CarbonPeriod::create($start->copy()->startOfDay(), $end->copy()->startOfDay()) as $date) {
            $key = $date->format($monthly ? 'Y-m' : 'Y-m-d');
            $rows[$key] ??= ['label' => $date->format($monthly ? 'M y' : 'd M'), 'bookings' => 0, 'chats' => 0, 'total' => 0];
            $b = (int) ($bookings[$date->toDateString()] ?? 0);
            $c = (int) ($chats[$date->toDateString()] ?? 0);
            $rows[$key]['bookings'] += $b;
            $rows[$key]['chats'] += $c;
            $rows[$key]['total'] += $b + $c;
        }
        $max = max(1, ...array_column($rows, 'total'));

        return array_values(array_map(fn ($row) => $row + ['height' => max(8, (int) round($row['total'] / $max * 100))], $rows));
    }
}
