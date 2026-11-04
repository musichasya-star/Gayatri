<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LaundryOrder;
use App\Models\LaundryPayment;
use App\Services\CRM\AuditLogService;
use App\Services\CRM\LaundryReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LaundryReportController extends Controller
{
    public const EXPORTS = ['orders' => 'Pesanan diterima', 'payments' => 'Pembayaran berlaku', 'services' => 'Rincian layanan', 'daily' => 'Ringkasan harian', 'balances' => 'Piutang saat ini', 'corrections' => 'Koreksi pembayaran'];

    private function filters(Request $request): array
    {
        $data = Validator::make($request->query(), ['start_date' => ['nullable', 'date_format:Y-m-d'], 'end_date' => ['nullable', 'date_format:Y-m-d'], 'include_demo' => ['nullable', 'boolean']])->validate();
        $start = Carbon::parse($data['start_date'] ?? today()->startOfMonth()->toDateString())->startOfDay();
        $end = Carbon::parse($data['end_date'] ?? today()->toDateString())->startOfDay();
        if ($start->gt($end) || $end->gt(today()) || $start->diffInDays($end) > 365) {
            throw ValidationException::withMessages(['start_date' => 'Pilih rentang maksimal 366 hari, sampai hari ini, dengan tanggal mulai tidak melewati tanggal akhir.']);
        }

        return [$start, $end, (bool) ($data['include_demo'] ?? false)];
    }

    public function index(Request $request, LaundryReportService $reports)
    {
        [$start, $end, $includeDemo] = $this->filters($request);
        $tab = Validator::make($request->query(), ['tab' => ['nullable', Rule::in(array_keys(self::EXPORTS))]])->validate()['tab'] ?? 'orders';
        $report = $reports->report($start, $end, $includeDemo);
        $rows = match ($tab) {
            'orders' => $reports->periodOrders($start, $end, $includeDemo)->withSum(['payments as report_paid' => fn ($q) => $q->whereNull('voided_at')], 'amount')->orderByDesc('received_at')->orderByDesc('id')->paginate(20)->withQueryString(),
            'payments' => $reports->payments($start, $end, $includeDemo)->with('order')->orderByDesc('paid_at')->orderByDesc('id')->paginate(20)->withQueryString(),
            'corrections' => $reports->corrections($start, $end, $includeDemo)->with('order')->orderByDesc('voided_at')->orderByDesc('id')->paginate(20)->withQueryString(),
            'balances' => $reports->receivables($includeDemo)->orderByRaw('(actual_total + COALESCE(pickup_fee, 0) + COALESCE(delivery_fee, 0) - COALESCE(laundry_report_payments.paid_total, 0)) DESC')->orderBy('laundry_orders.id')->paginate(20)->withQueryString(),
            default => null,
        };
        $filters = ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString(), 'include_demo' => $includeDemo ? 1 : 0];
        $isDashboard = $request->routeIs('admin.laundry.dashboard');
        $trend = collect($report['days'])->groupBy(fn ($day) => count($report['days']) > 31 ? substr($day['date'], 0, 7) : $day['date'])->map(fn ($days, $key) => ['label' => $key, 'paid' => $days->sum('paid'), 'charges' => $days->sum(fn ($day) => $day['service'] + $day['pickup'] + $day['delivery'])])->values()->all();

        return response()->view('admin.laundry.reports.index', compact('start', 'end', 'includeDemo', 'report', 'rows', 'filters', 'tab', 'reports', 'isDashboard', 'trend'))
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function export(Request $request, LaundryReportService $reports, AuditLogService $audit)
    {
        [$start, $end, $includeDemo] = $this->filters($request);
        $type = Validator::make($request->query(), ['type' => ['required', Rule::in(array_keys(self::EXPORTS))]])->validate()['type'];
        $audit->log($request->user(), 'laundry.report.export', null, $request, [], ['type' => $type, 'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(), 'include_demo' => $includeDemo], 'Laporan Laundry diekspor ke CSV.');
        $stamp = ($type === 'balances' ? 'saat-ini-'.now()->format('Ymd-His') : $start->format('Ymd').'-'.$end->format('Ymd')).($includeDemo ? '-termasuk-demo' : '');

        return response()->streamDownload(function () use ($reports, $start, $end, $includeDemo, $type) {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            $write = function (array $row) use ($file) {
                $safe = array_map(function ($value) {
                    if (is_int($value) || is_float($value)) {
                        return (string) $value;
                    }
                    $value = (string) ($value ?? '');

                    return preg_match('/^[\s\x00-\x1f]*[=+@\-]|^[\t\r\n]/u', $value) ? "'".$value : $value;
                }, $row);
                fputcsv($file, $safe, ',', '"', '');
            };
            if (in_array($type, ['orders', 'balances'], true)) {
                $write(['Kode', 'Pelanggan saat penerimaan', 'Diterima WIB', 'Estimasi selesai WIB', 'Status persetujuan saat ekspor', 'Tahap saat ekspor', 'Demo', 'Jasa disetujui Rp', 'Ongkir jemput Rp', 'Ongkir antar Rp', 'Total disetujui Rp', 'Pembayaran berlaku semua tanggal Rp', 'Sisa tagihan saat ini Rp']);
                $query = $type === 'balances' ? $reports->receivables($includeDemo) : $reports->periodOrders($start, $end, $includeDemo)->withSum(['payments as report_paid' => fn ($q) => $q->whereNull('voided_at')], 'amount');
                foreach ($query->lazyById(200, 'laundry_orders.id', 'id') as $order) {
                    $f = $reports->figures($order);
                    $write([$order->code, $order->customer_snapshot['name'] ?? '', $order->received_at->format('Y-m-d H:i:s'), $order->estimated_ready_at->format('Y-m-d H:i:s'), LaundryOrder::STATUSES[$order->status], LaundryOrder::WORKFLOW[$order->workflow_status], $order->is_demo ? 'Ya' : 'Tidak', $f['service'], $f['pickup'], $f['delivery'], $f['total'], $f['paid'], $f['balance']]);
                }
            } elseif (in_array($type, ['payments', 'corrections'], true)) {
                $write(['Kode', 'Pelanggan saat penerimaan', 'Waktu bayar WIB', 'Waktu dicatat WIB', 'Metode', 'Nominal Rp', 'Pembayar', 'Referensi', 'Status saat ekspor', 'Waktu koreksi WIB', 'Alasan koreksi', 'Demo']);
                $query = ($type === 'payments' ? $reports->payments($start, $end, $includeDemo) : $reports->corrections($start, $end, $includeDemo))->with('order');
                foreach ($query->lazyById(200) as $payment) {
                    $write([$payment->order->code, $payment->order->customer_snapshot['name'] ?? '', $payment->paid_at->format('Y-m-d H:i:s'), $payment->created_at->format('Y-m-d H:i:s'), LaundryPayment::METHODS[$payment->method], $payment->amount, $payment->payer_name, $payment->reference, $payment->voided_at ? 'Dikoreksi, tidak dihitung' : 'Berlaku', $payment->voided_at?->format('Y-m-d H:i:s'), $payment->void_reason, $payment->order->is_demo ? 'Ya' : 'Tidak']);
                }
            } elseif ($type === 'services') {
                $write(['Kode layanan saat penerimaan', 'Nama layanan saat penerimaan', 'Satuan', 'Jumlah pesanan', 'Jumlah kantong', 'Kuantitas aktual', 'Kuantitas dasar tagih', 'Jumlah potong fisik', 'Nilai jasa disetujui Rp']);
                foreach ($reports->services($start, $end, $includeDemo) as $line) {
                    $write([$line['code'], $line['name'], $line['unit'] === 'kg' ? 'kg' : 'potong', $line['orders'], $line['bags'], $line['quantity_milli'] / 1000, $line['billable_milli'] / 1000, $line['pieces'], $line['service_total']]);
                }
            } else {
                $write(['Tanggal WIB', 'Pesanan diterima', 'Pesanan diserahkan pada tanggal ini', 'Jasa pesanan diterima yang kini disetujui Rp', 'Ongkir jemput pesanan diterima Rp', 'Ongkir antar pesanan diterima Rp', 'Total tagihan pesanan diterima Rp', 'Pembayaran berlaku menurut tanggal bayar Rp']);
                foreach ($reports->report($start, $end, $includeDemo)['days'] as $day) {
                    $write([$day['date'], $day['orders'], $day['completed'], $day['service'], $day['pickup'], $day['delivery'], $day['service'] + $day['pickup'] + $day['delivery'], $day['paid']]);
                }
            }
            fclose($file);
        }, 'laundry-'.$type.'-'.$stamp.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow']);
    }
}
