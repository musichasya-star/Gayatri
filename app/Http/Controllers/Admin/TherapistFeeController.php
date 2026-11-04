<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Therapist;
use App\Models\TherapistFee;
use App\Services\CRM\AuditLogService;
use App\Services\CRM\TherapistFeeService;
use App\Support\ServiceGroup;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TherapistFeeController extends Controller
{
    private function filters(Request $request): array
    {
        $data = $request->validate(['start_date' => ['nullable', 'date_format:Y-m-d'], 'end_date' => ['nullable', 'date_format:Y-m-d'],
            'therapist_id' => ['nullable', 'integer', 'exists:therapists,id'], 'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'service_group' => ['nullable', Rule::in(array_keys(ServiceGroup::LABELS))], 'status' => ['nullable', Rule::in(['accrued', 'void'])], 'include_demo' => ['nullable', 'boolean']]);
        $data['start_date'] ??= today()->startOfMonth()->toDateString();
        $data['end_date'] ??= today()->toDateString();
        if ($data['start_date'] > $data['end_date'] || $data['end_date'] > today()->toDateString() || Carbon::parse($data['start_date'])->diffInDays($data['end_date']) > 365) {
            throw ValidationException::withMessages(['start_date' => 'Pilih periode maksimal 366 hari, sampai hari ini, dengan tanggal akhir setelah/sama dengan tanggal awal.']);
        }

        return $data;
    }

    private function query(array $filters)
    {
        return TherapistFee::query()->whereDate('service_date', '>=', $filters['start_date'])->whereDate('service_date', '<=', $filters['end_date'])
            ->when($filters['therapist_id'] ?? null, fn ($q, $id) => $q->where('therapist_id', $id))
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($filters['service_group'] ?? null, fn ($q, $group) => $q->where('service_group', $group))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when(! ($filters['include_demo'] ?? false), fn ($q) => $q->where('is_demo', false));
    }

    public function index(Request $request, TherapistFeeService $fees)
    {
        $filters = $this->filters($request);
        $query = $this->query($filters);
        $earned = (clone $query)->where('status', 'accrued');
        $summary = ['base' => (clone $earned)->sum('base_cents'), 'fee' => (clone $earned)->sum('fee_cents'), 'count' => (clone $earned)->count(), 'void' => (clone $query)->where('status', 'void')->count()];
        $byTherapist = (clone $earned)->select('therapist_id')->selectRaw('COUNT(*) as booking_count, SUM(base_cents) as basis, SUM(fee_cents) as fee_total')->groupBy('therapist_id')->with('therapist')->get();
        $rows = $query->with('branch')->orderByDesc('service_date')->orderByDesc('id')->paginate(20)->withQueryString();

        return response()->view('admin.therapist-fees.index', ['filters' => $filters, 'rows' => $rows, 'summary' => $summary, 'byTherapist' => $byTherapist,
            'therapists' => Therapist::orderBy('name')->get(), 'branches' => Branch::orderBy('name')->get(), 'settings' => $fees->settings(), 'pending' => $fees->candidates($filters)->count()])->header('Cache-Control', 'private, no-store');
    }

    public function settings(Request $request, TherapistFeeService $fees)
    {
        $request->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);

        return response()->view('admin.therapist-fees.settings', ['settings' => $fees->settings(), 'therapists' => Therapist::when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->input('search').'%'))->orderBy('name')->paginate(20)->withQueryString()])->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, TherapistFeeService $fees)
    {
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'start_date' => ['required', 'date_format:Y-m-d'],
            'spa_percent' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'], 'lactation_percent' => ['required', 'numeric', 'between:0,100', 'decimal:0,2']]);
        $data['enabled'] = $request->boolean('enabled');
        $fees->saveSettings($data, $request->user(), $request);

        return back()->with('status', 'Pengaturan fee tersimpan. Fee yang sudah tercatat mempertahankan persentasenya.');
    }

    public function therapist(Request $request, Therapist $therapist, AuditLogService $audit)
    {
        $data = $request->validate(['fee_spa_percent' => ['present', 'nullable', 'numeric', 'between:0,100', 'decimal:0,2'], 'fee_lactation_percent' => ['present', 'nullable', 'numeric', 'between:0,100', 'decimal:0,2']]);
        DB::transaction(function () use ($therapist, $data, $request, $audit) {
            $therapist = Therapist::whereKey($therapist->id)->lockForUpdate()->firstOrFail();
            $before = $therapist->only(array_keys($data));
            $therapist->forceFill($data)->save();
            $audit->log($request->user(), 'therapist_fee.override', $therapist, $request, $before, $data, 'Mengubah persentase khusus terapis; kosong berarti mengikuti default.');
        });

        return back()->with('status', 'Persentase khusus terapis tersimpan.');
    }

    public function preview(Request $request, TherapistFeeService $fees)
    {
        $filters = $this->filters($request);
        $preview = $fees->preview($filters);

        return response()->view('admin.therapist-fees.preview', compact('filters', 'preview'))->header('Cache-Control', 'private, no-store');
    }

    public function process(Request $request, TherapistFeeService $fees)
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:100000'], 'acknowledged' => ['accepted']]);
        $count = $fees->process($data['token'], $request->user(), $request);

        return redirect()->route('admin.therapist-fees.index')->with('status', $count.' fee berhasil dicatat. Tidak ada transfer uang yang dilakukan aplikasi.');
    }

    public function export(Request $request, AuditLogService $audit)
    {
        $filters = $this->filters($request);
        $audit->log($request->user(), 'therapist_fee.export', null, $request, [], $filters, 'Ekspor laporan fee terapis.');

        return response()->streamDownload(function () use ($filters) {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['Tanggal pelayanan', 'Kode booking', 'Terapis', 'Kelompok', 'Layanan', 'Cabang', 'Dasar fee (Rp)', 'Persentase', 'Fee (Rp)', 'Status', 'Demo', 'Harga histori dari katalog', 'Alasan void'], ',', '"', '');
            foreach ($this->query($filters)->with('branch')->lazyById(200) as $row) {
                $safe = fn ($value) => preg_match('/^[\s\x00-\x1f]*[=+@\-]|^[\t\r\n]/u', (string) $value) ? "'".$value : $value;
                $values = [$row->service_date->toDateString(), $row->booking_code, $row->therapist_name, ServiceGroup::LABELS[$row->service_group], $row->service_name, $row->branch?->name,
                    number_format($row->base_cents / 100, 2, '.', ''), number_format($row->rate_bps / 100, 2, '.', ''), number_format($row->fee_cents / 100, 2, '.', ''), $row->status, $row->is_demo ? 'Ya' : 'Tidak', $row->snapshot['price_source'] === 'legacy_catalog_confirmed' ? 'Ya' : 'Tidak', $row->void_reason];
                fputcsv($file, array_map($safe, $values), ',', '"', '');
            }
            fclose($file);
        }, 'fee-terapis-'.$filters['start_date'].'-'.$filters['end_date'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
