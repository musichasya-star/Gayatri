<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\LaundryOrder;
use App\Models\LaundryTariff;
use App\Services\CRM\AuditLogService;
use App\Services\CRM\LaundryOperations;
use App\Services\CRM\LaundryOrderService;
use App\Services\CRM\LaundryPricing;
use App\Services\CRM\LaundrySettings;
use App\Support\AdminReviewContext;
use App\Support\CatalogueSearch;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LaundryController extends Controller
{
    private function page(string $view, array $data = [])
    {
        return response()->view('admin.laundry.'.$view, $data)->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function index(Request $request, LaundrySettings $settings)
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(array_keys(LaundryOrder::STATUSES))], 'start_date' => ['nullable', 'date_format:Y-m-d'], 'end_date' => ['nullable', 'date_format:Y-m-d'], 'include_demo' => ['nullable', 'boolean']]);
        $filters['start_date'] ??= today()->startOfMonth()->toDateString();
        $filters['end_date'] ??= today()->toDateString();
        if ($filters['start_date'] > $filters['end_date'] || $filters['end_date'] > today()->toDateString() || Carbon::parse($filters['start_date'])->diffInDays($filters['end_date']) > 365) {
            throw ValidationException::withMessages(['start_date' => 'Pilih periode penerimaan maksimal 366 hari, sampai hari ini.']);
        }
        $query = LaundryOrder::query()->whereDate('received_at', '>=', $filters['start_date'])->whereDate('received_at', '<=', $filters['end_date'])
            ->when(! ($filters['include_demo'] ?? false), fn ($q) => $q->where('is_demo', false))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q->where('code', 'like', '%'.$search.'%')->orWhere('customer_snapshot->name', 'like', '%'.$search.'%')));
        $counts = (clone $query)->select('status')->selectRaw('COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $orders = $query->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))->orderByDesc('received_at')->orderByDesc('id')->paginate(15)->withQueryString();

        return $this->page('orders.index', ['orders' => $orders, 'counts' => $counts, 'filters' => $filters, 'settings' => $settings->get()]);
    }

    public function settings(LaundrySettings $settings)
    {
        return $this->page('settings', ['settings' => $settings->get()]);
    }

    public function saveSettings(Request $request, LaundrySettings $settings)
    {
        $data = $request->validate(['accepting_orders' => ['required', 'boolean'], 'outlet_name' => ['required', 'string', 'max:150'], 'address' => ['nullable', 'required_if:accepting_orders,1', 'string', 'max:1500'],
            'whatsapp_number' => ['nullable', 'required_if:accepting_orders,1', 'string', 'max:20', 'regex:/^(?:\+?62|0)[1-9][0-9]{7,12}$/D'], 'opening_hours' => ['nullable', 'string', 'max:500'], 'terms' => ['nullable', 'string', 'max:5000'], 'allow_collection' => ['required', 'boolean']]);
        $data['accepting_orders'] = $request->boolean('accepting_orders');
        $data['allow_collection'] = $request->boolean('allow_collection');
        $settings->save($data, $request->user(), $request);

        return back()->with('status', 'Pengaturan outlet laundry tersimpan. Pesanan lama tetap memakai salinan saat penerimaan.');
    }

    public function tariffs(Request $request)
    {
        $filters = array_replace(['search' => '', 'audience' => null, 'active' => '1', 'unit' => null, 'readiness' => null], $request->validate(['search' => ['nullable', 'string', 'max:100'], 'audience' => ['nullable', Rule::in(array_keys(LaundryTariff::AUDIENCES))], 'active' => ['nullable', 'boolean'], 'unit' => ['nullable', Rule::in(array_keys(LaundryTariff::UNITS))], 'readiness' => ['nullable', 'in:ready,admin,incomplete'], 'page' => ['nullable', 'integer', 'min:1']]));
        $query = LaundryTariff::query()->when($filters['audience'], fn ($q, $value) => $q->where('audience', $value))->when($filters['unit'], fn ($q, $value) => $q->where('unit', $value))->when($filters['active'] !== null, fn ($q) => $q->where('is_active', (bool) $filters['active']));
        CatalogueSearch::apply($query, $filters['search'], ['name', 'code']);
        $rows = $query->orderBy('name')->get()->when($filters['readiness'], fn ($rows, $value) => $rows->filter(fn ($tariff) => $tariff->catalogueReadiness() === $value))->values();
        $page = $request->integer('page', 1);
        $tariffs = new LengthAwarePaginator($rows->forPage($page, 15)->values(), $rows->count(), 15, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return $this->page('tariffs.index', compact('tariffs', 'filters'));
    }

    public function tariffForm(?LaundryTariff $tariff = null)
    {
        return $this->page('tariffs.form', ['tariff' => $tariff ?? new LaundryTariff(['unit' => 'kg', 'audience' => 'both', 'minimum_milli' => 1000, 'increment_milli' => 100, 'duration_hours' => 24, 'is_active' => false])]);
    }

    public function saveTariff(Request $request, AuditLogService $audit, ?LaundryTariff $tariff = null)
    {
        $tariff ??= new LaundryTariff;
        if (is_string($request->input('code'))) {
            $request->merge(['code' => strtoupper(trim($request->input('code')))]);
        }
        $data = $request->validate(['code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/D', Rule::unique('laundry_tariffs')->ignore($tariff)], 'name' => ['required', 'string', 'max:150'], 'audience' => ['required', Rule::in(array_keys(LaundryTariff::AUDIENCES))],
            'unit' => ['required', Rule::in(array_keys(LaundryTariff::UNITS))], 'price' => ['required', 'integer', 'min:1', 'max:10000000'], 'minimum_quantity' => ['nullable', Rule::requiredIf(! $request->boolean('requires_confirmation')), 'numeric', 'min:0.001', 'max:1000', 'decimal:0,3'], 'rounding_increment' => ['nullable', Rule::requiredIf(! $request->boolean('requires_confirmation')), 'numeric', 'min:0.001', 'max:1000', 'decimal:0,3'],
            'duration_hours' => ['nullable', Rule::requiredIf(! $request->boolean('requires_confirmation')), 'integer', 'min:1', 'max:720'], 'description' => ['nullable', 'string', 'max:3000'], 'is_active' => ['required', 'boolean'], 'requires_confirmation' => ['nullable', 'boolean']]);
        $data['requires_confirmation'] = $request->boolean('requires_confirmation');
        $data['minimum_milli'] = isset($data['minimum_quantity']) ? LaundryPricing::milli($data['minimum_quantity']) : null;
        $data['increment_milli'] = isset($data['rounding_increment']) ? LaundryPricing::milli($data['rounding_increment']) : null;
        if (! $data['requires_confirmation'] && $data['unit'] === 'piece' && ($data['minimum_milli'] % 1000 !== 0 || $data['increment_milli'] !== 1000)) {
            throw ValidationException::withMessages(['minimum_quantity' => 'Tarif potong menggunakan minimum bilangan bulat dan pembulatan 1 potong.']);
        }
        unset($data['minimum_quantity'], $data['rounding_increment']);
        DB::transaction(function () use ($tariff, $data, $audit, $request) {
            $before = $tariff->getAttributes();
            $tariff->fill($data)->save();
            $audit->log($request->user(), 'laundry.tariff.save', $tariff, $request, $before, $tariff->fresh()->toArray(), 'Katalog tarif laundry tersimpan.');
        });

        return redirect(AdminReviewContext::back($request, 'laundry-tariffs'))->with('status', 'Tarif laundry tersimpan. Perubahan berlaku untuk penerimaan baru.');
    }

    public function archiveTariff(Request $request, LaundryTariff $tariff, AuditLogService $audit)
    {
        $before = $tariff->toArray();
        $tariff->update(['is_active' => false]);
        $audit->log($request->user(), 'laundry.tariff.archive', $tariff, $request, $before, $tariff->fresh()->toArray(), 'Tarif laundry dinonaktifkan; riwayat tetap tersimpan.');

        return back()->with('status', 'Tarif dinonaktifkan. Pesanan lama tetap dapat ditimbang dengan tarif tersimpan.');
    }

    public function create(Request $request, LaundrySettings $settings)
    {
        return $this->page('orders.form', ['settings' => $settings->get(), 'tariffs' => LaundryTariff::where('is_active', true)->orderBy('name')->get(), 'customers' => Customer::orderBy('name')->get(['id', 'name', 'whatsapp_number', 'status']), 'draft' => $request->session()->get('laundry.intake_draft', [])]);
    }

    public function preview(Request $request, LaundryOrderService $service)
    {
        $data = $request->validate(['public_request_id' => ['nullable', 'integer', 'exists:laundry_public_requests,id'], 'entry_key' => ['required', 'uuid'], 'customer_id' => ['nullable', 'integer', 'exists:customers,id'], 'new_name' => ['nullable', 'required_without:customer_id', 'string', 'max:150'], 'new_whatsapp' => ['nullable', 'required_without:customer_id', 'string', 'max:30'],
            'received_at' => ['required', 'date', 'before_or_equal:now'], 'intake_mode' => ['required', Rule::in(array_keys(LaundryOrder::MODES))], 'collection_address' => ['nullable', 'required_if:intake_mode,collection', 'string', 'min:10', 'max:1500'], 'instructions' => ['nullable', 'string', 'max:3000'], 'is_demo' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1', 'max:20'], 'items.*.laundry_tariff_id' => ['required', 'integer', 'exists:laundry_tariffs,id'], 'items.*.bag_label' => ['nullable', 'string', 'max:100'], 'items.*.received_pieces' => ['required', 'integer', 'min:1', 'max:10000'], 'items.*.estimated_quantity' => ['nullable', 'numeric', 'gt:0', 'max:1000', 'decimal:0,3'], 'items.*.condition_notes' => ['required', 'string', 'min:3', 'max:1500']]);
        $request->session()->put('laundry.intake_draft', $data);
        $preview = $service->preview($data, $request->user());

        return $this->page('orders.preview', compact('preview'));
    }

    public function store(Request $request, LaundryOrderService $service)
    {
        try {
            $data = $request->validate(['token' => ['required', 'string', 'max:300000'], 'confirmed' => ['required', 'accepted']]);
            $order = $service->create($data['token'], $request->user(), $request);
        } catch (ValidationException $exception) {
            return redirect()->route('admin.laundry.orders.create')->withErrors($exception->errors());
        }
        $request->session()->forget('laundry.intake_draft');

        return redirect()->route('admin.laundry.orders.show', $order)->with('status', 'Penerimaan tersimpan. Timbang seluruh kantong untuk menyiapkan nilai jasa aktual.');
    }

    public function show(LaundryOrder $order)
    {
        return $this->page('orders.show', ['order' => $order->load(['items', 'events.actor', 'qualityChecks.actor', 'payments.creator', 'payments.document', 'documents', 'transportTasks.courier', 'publicRequest', 'paymentProofs']), 'canReweigh' => $order->canReweigh(), 'nextStage' => LaundryOperations::nextStage($order)]);
    }

    public function receipt(LaundryOrder $order)
    {
        return $this->page('orders.receipt', ['order' => $order->load('items')]);
    }

    public function weigh(Request $request, LaundryOrder $order, LaundryOrderService $service)
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'reason' => ['nullable', 'string', 'max:1500'], 'items' => ['required', 'array', 'min:1', 'max:20'], 'items.*.id' => ['required', 'integer', 'distinct'],
            'items.*.actual_pieces' => ['required', 'integer', 'min:1', 'max:10000'], 'items.*.quantity' => ['nullable', 'numeric', 'gt:0', 'max:1000', 'decimal:0,3'], 'items.*.notes' => ['nullable', 'string', 'max:1500']]);
        $service->weigh($order, $data, $request->user(), $request);

        return back()->with('status', 'Penimbangan tersimpan. Periksa selisih estimasi dan catat persetujuan pelanggan sebelum pengerjaan.');
    }

    public function approve(Request $request, LaundryOrder $order, LaundryOrderService $service)
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'approved_name' => ['required', 'string', 'max:150'], 'approval_channel' => ['required', Rule::in(['outlet', 'whatsapp', 'phone', 'written'])], 'approval_reference' => ['required', 'string', 'min:5', 'max:500'], 'confirmed' => ['required', 'accepted']]);
        $service->approve($order, $data, $request->user(), $request);

        return back()->with('status', 'Persetujuan nilai jasa dicatat. Ini bukan pencatatan pembayaran.');
    }

    public function cancel(Request $request, LaundryOrder $order, LaundryOrderService $service)
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:5', 'max:1500']]);
        $service->cancel($order, $data, $request->user(), $request);

        return back()->with('status', 'Penerimaan dibatalkan. Riwayat penerimaan, penimbangan, dan persetujuan tetap tersimpan.');
    }
}
