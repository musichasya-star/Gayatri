<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CRM\AuditLogService;
use App\Services\CRM\BookingReports;
use App\Support\ReportPeriod;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    private const TYPES = BookingReports::TYPES;

    public function __construct(private readonly AuditLogService $auditLogService, private readonly BookingReports $reports) {}

    public function index(Request $request): View
    {
        [$start, $end] = $this->dateRange($request);
        $types = $this->allowedTypes($request);

        return view('admin.reports.index', [
            'types' => $types,
            'startDate' => $start->toDateString(),
            'endDate' => $end->toDateString(),
            'summary' => $this->reports->summary($start, $end, $types),
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
            'rows' => $this->reports->query($type, $start, $end)->orderByDesc($type === 'balances' ? 'bookings.id' : 'id')->paginate(25)->through(fn ($row) => $this->reports->row($type, $row))->withQueryString(),
        ]);
    }

    public function export(Request $request, string $type): StreamedResponse
    {
        abort_unless(in_array($type, self::TYPES, true), 404);
        abort_unless(in_array($type, $this->allowedTypes($request), true), 403);
        [$start,$end] = $this->dateRange($request);
        $headers = $this->headers($type);
        $this->auditLogService->log($request->user(), 'report.export', null, $request, [], ['type' => $type, 'start_date' => $start->toDateString(), 'end_date' => $end->toDateString()], 'Report exported without row limit');

        return response()->streamDownload(function () use ($type, $start, $end, $headers) {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            $write = function (array $row) use ($file) {
                $cells = array_map(function ($value) {
                    $text = (string) ($value ?? '');
                    if (! is_numeric($value) && preg_match('/^[\s\x00-\x1f]*[=+@\-]|^[\t\r\n]/u', $text)) {
                        $text = "'".$text;
                    }

                    return '"'.str_replace('"', '""', $text).'"';
                }, $row);
                fwrite($file, implode(',', $cells)."\n");
            };
            $write(array_values($headers));
            foreach ($this->reports->query($type, $start, $end)->lazyById(200, $type === 'balances' ? 'bookings.id' : 'id', 'id') as $model) {
                $row = $this->reports->row($type, $model);
                $write(array_map(fn ($key) => $row[$key] ?? '', array_keys($headers)));
            }
            fclose($file);
        }, 'gayatri-'.$type.'-'.$start->toDateString().'-'.$end->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function allowedTypes(Request $request): array
    {
        if ($request->user()?->role === 'sales') {
            return ['campaigns', 'followups'];
        }

        return self::TYPES;
    }

    private function headers(string $type): array
    {
        return match ($type) {
            'payments' => ['code' => 'Kode', 'customer' => 'Customer', 'date' => 'Waktu bayar WIB', 'kind' => 'Jenis transaksi', 'method' => 'Metode', 'amount' => 'Nominal Rp', 'reference' => 'Referensi', 'receipt' => 'Kuitansi'],
            'balances' => ['code' => 'Kode', 'customer' => 'Customer', 'date' => 'Tanggal layanan', 'status' => 'Status', 'balance' => 'Piutang saat ini Rp'],
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
            'payments' => 'Pembayaran Booking Tercatat',
            'balances' => 'Piutang Booking Saat Ini',
            'customers' => 'Customer Report',
            'bookings' => 'Booking Report',
            'revenue' => 'Tagihan Booking (Estimasi)',
            'campaigns' => 'Campaign Report',
            'followups' => 'Follow-Up Report',
            'ai' => 'AI Performance Report',
        };
    }

    private function dateRange(Request $request): array
    {
        return ReportPeriod::dates($request);
    }
}
