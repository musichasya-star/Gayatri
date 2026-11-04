<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RentalOrder;
use App\Services\CRM\AuditLogService;
use App\Services\CRM\RentalReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RentalReportController extends Controller
{
    public function index(Request $request, RentalReportService $reports)
    {
        [$start, $end, $includeDemo] = $this->filters($request);
        $report = $reports->report($start, $end, $includeDemo);
        $orders = $reports->periodOrders($start, $end, $includeDemo)->with('customer:id,name')->orderByDesc('start_date')->orderByDesc('id')->paginate(20)->withQueryString();

        return view('admin.rentals.reports', compact('report', 'orders', 'start', 'end', 'includeDemo', 'reports'));
    }

    private function filters(Request $request): array
    {
        $data = Validator::make($request->query(), ['start_date' => ['nullable', 'date_format:Y-m-d'], 'end_date' => ['nullable', 'date_format:Y-m-d'], 'include_demo' => ['nullable', 'boolean']])->validate();
        $start = Carbon::parse($data['start_date'] ?? today()->startOfMonth()->toDateString())->startOfDay();
        $end = Carbon::parse($data['end_date'] ?? today()->toDateString())->endOfDay();
        if ($start->gt($end) || $end->gt(now()->endOfDay()) || $start->diffInDays($end->copy()->startOfDay()) > 365) {
            throw ValidationException::withMessages(['start_date' => 'Pilih rentang maksimal 366 hari, tanggal akhir tidak mendahului tanggal mulai dan tidak melewati hari ini.']);
        }

        return [$start, $end, (bool) ($data['include_demo'] ?? false)];
    }

    public function export(Request $request, RentalReportService $reports, AuditLogService $audit)
    {
        [$start, $end, $includeDemo] = $this->filters($request);
        $data = $request->validate(['type' => ['required', Rule::in(['orders', 'cash', 'care', 'products', 'monthly', 'balances'])]]);
        $type = $data['type'];
        $audit->log($request->user(), 'rental.report.export', null, $request, [], ['type' => $type, 'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(), 'include_demo' => $includeDemo], 'Laporan rental diekspor ke CSV');

        return response()->streamDownload(function () use ($reports, $start, $end, $includeDemo, $type) {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            $write = function (array $row) use ($file) {
                $safe = array_map(function ($value) {
                    if (is_int($value) || is_float($value)) {
                        return (string) $value;
                    }
                    $text = (string) ($value ?? '');

                    return preg_match('/^[\s\x00-\x1f]*[=+@\-]|^[\t\r\n]/u', $text) ? "'".$text : $text;
                }, $row);
                fputcsv($file, $safe, ',', '"', '');
            };
            $money = fn ($cents) => $cents / 100;
            if ($type === 'orders' || $type === 'balances') {
                $write(['Kode', 'Pelanggan', 'Mulai', 'Kembali', 'Status saat ekspor', 'Demo', 'Tagihan jasa', 'Deposit terutang', 'Diterima', 'Refund', 'Sisa tagihan', 'Deposit ditahan', 'Saldo refundable']);
                $query = ($type === 'orders' ? $reports->periodOrders($start, $end, $includeDemo) : $reports->orders($includeDemo))->with('customer:id,name');
                if ($type !== 'orders') {
                    $query->where('status', '!=', 'pending');
                }
                foreach ($query->lazyById(200) as $order) {
                    $f = $reports->figures($order);
                    if ($type === 'balances' && $f['balance'] <= 0 && $f['refundable'] <= 0 && $f['deposit_held'] <= 0) {
                        continue;
                    }
                    $write([$order->code, $order->customer_snapshot['name'] ?? $order->customer?->name, $order->start_date->toDateString(), $order->end_date->toDateString(), RentalOrder::STATUSES[$order->status], $order->is_demo ? 'Ya' : 'Tidak', ...array_map($money, [$f['charges'], $f['deposit_required'], $f['paid'], $f['refunds'], $f['balance'], $f['deposit_held'], $f['refundable']])]);
                }
            } elseif ($type === 'cash') {
                $write(['Tanggal pencatatan WIB', 'Kode', 'Pelanggan', 'Jenis', 'Metode', 'Nominal', 'Referensi']);
                foreach ($reports->cash($start, $end, $includeDemo)->with('order.customer:id,name')->lazyById(200) as $payment) {
                    $write([$payment->created_at->format('Y-m-d H:i:s'), $payment->order->code, $payment->order->customer_snapshot['name'] ?? $payment->order->customer?->name, $payment->kind === 'refund' ? 'Refund' : 'Penerimaan', $payment->method, $money($reports->cents($payment->amount)), $payment->reference]);
                }
            } elseif ($type === 'care') {
                $write(['Tanggal pekerjaan WIB', 'Unit', 'Produk', 'Jenis', 'Biaya tercatat', 'Penyedia']);
                foreach ($reports->care($start, $end, $includeDemo)->with('unit.product')->lazyById(200) as $record) {
                    $write([$record->performed_at->format('Y-m-d H:i:s'), $record->unit->code, $record->unit->product->name, $record->kind, $money($reports->cents($record->cost)), $record->vendor]);
                }
            } elseif ($type === 'products') {
                $write(['SKU', 'Produk', 'Unit tercatat', 'Unit dalam pesanan mulai periode', 'Nilai sewa', 'Denda', 'Kerusakan', 'Hari kalender kapasitas', 'Hari terpakai', 'Indikasi utilisasi persen']);
                foreach ($reports->products($start, $end, $includeDemo) as $p) {
                    $write([$p['sku'], $p['name'], $p['units'], $p['rented_units'], $money($p['rental']), $money($p['late_fee']), $money($p['damage_fee']), $p['capacity_days'], $p['used_days'], $p['utilization']]);
                }
            } else {
                $write(['Bulan', 'Penerimaan tercatat', 'Refund tercatat', 'Penerimaan bersih', 'Biaya perawatan', 'Tagihan jasa pesanan mulai periode']);
                foreach ($reports->report($start, $end, $includeDemo)['months'] as $month) {
                    $write([$month['month'], ...array_map($money, [$month['paid'], $month['refunds'], $month['net'], $month['care'], $month['charges']])]);
                }
            }
            fclose($file);
        }, $type === 'balances' ? 'rental-saldo-saat-ini-'.now()->format('Ymd-His').'.csv' : 'rental-'.$type.'-'.$start->format('Ymd').'-'.$end->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
