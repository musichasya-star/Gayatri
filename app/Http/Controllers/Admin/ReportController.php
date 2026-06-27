<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiLog;
use App\Models\Booking;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Followup;
use App\Services\CRM\AuditLogService;
use App\Support\BookingStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class ReportController extends Controller
{
    private const TYPES = ['customers', 'bookings', 'revenue', 'campaigns', 'followups', 'ai'];

    public function __construct(private readonly AuditLogService $auditLogService) {}

    public function index(Request $request): View
    {
        [$start, $end] = $this->dateRange($request);
        $types = $this->allowedTypes($request);

        return view('admin.reports.index', [
            'types' => $types,
            'startDate' => $start->toDateString(),
            'endDate' => $end->toDateString(),
            'summary' => [
                'customers' => Customer::whereBetween('created_at', [$start, $end])->count(),
                'bookings' => Booking::whereBetween('booking_date', [$start->toDateString(), $end->toDateString()])->count(),
                'revenue' => $this->revenueRows($start, $end)->sum('net_revenue'),
                'campaigns' => Campaign::whereBetween('created_at', [$start, $end])->count(),
                'followups' => Followup::whereBetween('created_at', [$start, $end])->count(),
                'ai' => AiLog::whereBetween('created_at', [$start, $end])->count(),
            ],
        ]);
    }

    public function show(Request $request, string $type): View
    {
        abort_unless(in_array($type, self::TYPES, true), 404);
        abort_unless(in_array($type, $this->allowedTypes($request), true), 403);
        [$start, $end] = $this->dateRange($request);

        return view('admin.reports.show', [
            'type' => $type,
            'title' => $this->title($type),
            'startDate' => $start->toDateString(),
            'endDate' => $end->toDateString(),
            'headers' => $this->headers($type),
            'rows' => $this->rows($type, $start, $end),
        ]);
    }

    public function export(Request $request, string $type): Response
    {
        abort_unless(in_array($type, self::TYPES, true), 404);
        abort_unless(in_array($type, $this->allowedTypes($request), true), 403);
        [$start, $end] = $this->dateRange($request);
        $headers = $this->headers($type);
        $rows = $this->rows($type, $start, $end)->map(fn ($row) => array_map(fn ($key) => $row[$key] ?? '', array_keys($headers)));
        $csv = $this->csv(array_values($headers), $rows->all());
        $this->auditLogService->log($request->user(), 'report.export', null, $request, [], ['type' => $type, 'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(), 'rows' => $rows->count()], 'Report exported');

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="gayatri-'.$type.'-'.$start->toDateString().'-'.$end->toDateString().'.csv"',
        ]);
    }

    private function rows(string $type, $start, $end)
    {
        return match ($type) {
            'customers' => Customer::with('branch')->whereBetween('created_at', [$start, $end])->latest()->limit(500)->get()->map(fn (Customer $customer) => [
                'name' => $customer->name,
                'whatsapp' => $customer->whatsapp_number,
                'status' => $customer->status,
                'branch' => $customer->branch?->name,
                'created_at' => $customer->created_at?->format('Y-m-d H:i'),
            ]),
            'bookings' => Booking::with(['customer', 'service', 'therapist'])->whereBetween('booking_date', [$start->toDateString(), $end->toDateString()])->latest('booking_date')->limit(500)->get()->map(fn (Booking $booking) => [
                'code' => $booking->booking_code,
                'customer' => $booking->customer?->name,
                'service' => $booking->service?->name,
                'therapist' => $booking->therapist?->name,
                'date' => $booking->booking_date?->format('Y-m-d'),
                'time' => substr((string) $booking->start_time, 0, 5),
                'status' => $booking->status,
            ]),
            'revenue' => $this->revenueRows($start, $end),
            'campaigns' => Campaign::with('promo')->whereBetween('created_at', [$start, $end])->latest()->limit(500)->get()->map(fn (Campaign $campaign) => [
                'name' => $campaign->name,
                'promo' => $campaign->promo?->title,
                'status' => $campaign->status,
                'recipients' => $campaign->recipient_count,
                'sent' => $campaign->sent_count,
                'failed' => $campaign->failed_count,
                'scheduled_at' => $campaign->scheduled_at?->format('Y-m-d H:i'),
            ]),
            'followups' => Followup::with('customer')->whereBetween('created_at', [$start, $end])->latest()->limit(500)->get()->map(fn (Followup $followup) => [
                'customer' => $followup->customer?->name,
                'title' => $followup->title,
                'priority' => $followup->priority,
                'status' => $followup->status,
                'due_at' => $followup->due_at?->format('Y-m-d H:i'),
                'completed_at' => $followup->completed_at?->format('Y-m-d H:i'),
            ]),
            'ai' => AiLog::with('customer')->whereBetween('created_at', [$start, $end])->latest()->limit(500)->get()->map(fn (AiLog $log) => [
                'customer' => $log->customer?->name,
                'status' => $log->status,
                'confidence' => $log->confidence,
                'fallback_reason' => $log->fallback_reason,
                'created_at' => $log->created_at?->format('Y-m-d H:i'),
            ]),
        };
    }

    private function allowedTypes(Request $request): array
    {
        if ($request->user()?->role === 'sales') {
            return ['campaigns', 'followups'];
        }

        return self::TYPES;
    }

    private function revenueRows($start, $end)
    {
        return Booking::with(['customer', 'service'])
            ->whereBetween('booking_date', [$start->toDateString(), $end->toDateString()])
            ->whereNotIn('status', [BookingStatus::CANCELLED, BookingStatus::NO_SHOW])
            ->latest('booking_date')
            ->limit(500)
            ->get()
            ->map(function (Booking $booking) {
                $gross = (float) ($booking->service?->price ?? 0);
                $discount = (float) $booking->promo_discount;

                return [
                    'code' => $booking->booking_code,
                    'customer' => $booking->customer?->name,
                    'service' => $booking->service?->name,
                    'date' => $booking->booking_date?->format('Y-m-d'),
                    'status' => $booking->status,
                    'gross_revenue' => $gross,
                    'discount' => $discount,
                    'net_revenue' => max(0, $gross - $discount),
                ];
            });
    }

    private function headers(string $type): array
    {
        return match ($type) {
            'customers' => ['name' => 'Nama', 'whatsapp' => 'WhatsApp', 'status' => 'Status', 'branch' => 'Cabang', 'created_at' => 'Tanggal Dibuat'],
            'bookings' => ['code' => 'Kode', 'customer' => 'Customer', 'service' => 'Layanan', 'therapist' => 'Terapis', 'date' => 'Tanggal', 'time' => 'Jam', 'status' => 'Status'],
            'revenue' => ['code' => 'Kode', 'customer' => 'Customer', 'service' => 'Layanan', 'date' => 'Tanggal', 'status' => 'Status', 'gross_revenue' => 'Gross', 'discount' => 'Diskon', 'net_revenue' => 'Net'],
            'campaigns' => ['name' => 'Campaign', 'promo' => 'Promo', 'status' => 'Status', 'recipients' => 'Recipients', 'sent' => 'Sent', 'failed' => 'Failed', 'scheduled_at' => 'Scheduled At'],
            'followups' => ['customer' => 'Customer', 'title' => 'Judul', 'priority' => 'Prioritas', 'status' => 'Status', 'due_at' => 'Due At', 'completed_at' => 'Completed At'],
            'ai' => ['customer' => 'Customer', 'status' => 'Status', 'confidence' => 'Confidence', 'fallback_reason' => 'Fallback Reason', 'created_at' => 'Created At'],
        };
    }

    private function title(string $type): string
    {
        return match ($type) {
            'customers' => 'Customer Report',
            'bookings' => 'Booking Report',
            'revenue' => 'Revenue Report',
            'campaigns' => 'Campaign Report',
            'followups' => 'Follow-Up Report',
            'ai' => 'AI Performance Report',
        };
    }

    private function dateRange(Request $request): array
    {
        $start = $request->date('start_date')?->startOfDay() ?? now()->startOfMonth();
        $end = $request->date('end_date')?->endOfDay() ?? now()->endOfMonth();

        return [$start, $end];
    }

    private function csv(array $headers, array $rows): string
    {
        $lines = [$this->csvLine($headers)];
        foreach ($rows as $row) {
            $lines[] = $this->csvLine($row);
        }

        return implode("\n", $lines)."\n";
    }

    private function csvLine(array $row): string
    {
        return collect($row)->map(fn ($value) => '"'.str_replace('"', '""', (string) $value).'"')->implode(',');
    }
}
