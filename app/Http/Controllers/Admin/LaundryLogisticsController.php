<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\LaundryCourier;
use App\Models\LaundryOrder;
use App\Models\LaundryTransportTask;
use App\Services\CRM\AuditLogService;
use App\Services\CRM\LaundryTransportPricing;
use App\Services\CRM\LaundryTransportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LaundryLogisticsController extends Controller
{
    private function page(string $view, array $data = [])
    {
        return response()->view('admin.laundry.'.$view, $data)->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function index(Request $request)
    {
        $filters = $request->validate(['start_date' => ['nullable', 'date_format:Y-m-d'], 'end_date' => ['nullable', 'date_format:Y-m-d'], 'kind' => ['nullable', Rule::in(array_keys(LaundryTransportTask::KINDS))], 'status' => ['nullable', Rule::in(array_keys(LaundryTransportTask::STATUSES))], 'courier' => ['nullable', 'integer', 'exists:laundry_couriers,id'], 'include_demo' => ['nullable', 'boolean']]);
        $filters['start_date'] ??= today()->toDateString();
        $filters['end_date'] ??= today()->addDays(7)->toDateString();
        if ($filters['start_date'] > $filters['end_date'] || Carbon::parse($filters['start_date'])->diffInDays($filters['end_date']) > 365) {
            throw ValidationException::withMessages(['start_date' => 'Pilih rentang jadwal maksimal 366 hari.']);
        }
        $tasks = LaundryTransportTask::with(['order', 'courier'])->whereDate('scheduled_start', '>=', $filters['start_date'])->whereDate('scheduled_start', '<=', $filters['end_date'])
            ->when(! ($filters['include_demo'] ?? false), fn ($q) => $q->where('is_demo', false))
            ->when($filters['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->when($filters['courier'] ?? null, fn ($q, $v) => $q->where('laundry_courier_id', $v))
            ->orderBy('scheduled_start')->orderBy('id')->paginate(20)->withQueryString();

        return $this->page('transport.index', ['tasks' => $tasks, 'filters' => $filters, 'couriers' => LaundryCourier::orderBy('name')->get()]);
    }

    public function settings(LaundryTransportPricing $pricing)
    {
        return $this->page('transport.settings', ['settings' => $pricing->settings()]);
    }

    public function saveSettings(Request $request, LaundryTransportPricing $pricing)
    {
        $rules = ['enabled' => ['required', 'boolean'], 'mode' => ['required', Rule::in(['flat', 'per_km'])], 'origin_latitude' => ['nullable', 'required_with:origin_longitude', 'numeric', 'between:-90,90'], 'origin_longitude' => ['nullable', 'required_with:origin_latitude', 'numeric', 'between:-180,180'], 'max_distance_km' => ['required', 'numeric', 'gt:0', 'max:500', 'decimal:0,3']];
        foreach (['pickup_flat_fee', 'delivery_flat_fee', 'pickup_rate_per_km', 'delivery_rate_per_km', 'minimum_fee'] as $field) {
            $rules[$field] = ['required', 'integer', 'min:0', 'max:10000000'];
        }
        $data = $request->validate($rules);
        $data['enabled'] = $request->boolean('enabled');
        foreach (['pickup_flat_fee', 'delivery_flat_fee', 'pickup_rate_per_km', 'delivery_rate_per_km', 'minimum_fee'] as $field) {
            $data[$field] = (int) $data[$field];
        }
        $pricing->save($data, $request->user(), $request);

        return back()->with('status', 'Pengaturan ongkir laundry tersimpan. Tarif dan jadwal lama mempertahankan salinan harganya.');
    }

    public function courier(Request $request)
    {
        $request->merge(['name' => mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string) $request->input('name'))))]);
        $data = $request->validate(['name' => ['required', 'string', 'max:150', 'unique:laundry_couriers,name'], 'phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9][0-9 ()-]{5,29}$/D']]);
        $courier = LaundryCourier::create($data + ['is_active' => true]);
        app(AuditLogService::class)->log($request->user(), 'laundry.courier.create', $courier, $request, [], $courier->toArray(), 'Petugas laundry ditambahkan.');

        return back()->with('status', 'Petugas laundry ditambahkan.');
    }

    public function toggleCourier(Request $request, LaundryCourier $courier)
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $courier->update(['is_active' => (bool) $data['is_active']]);
        app(AuditLogService::class)->log($request->user(), 'laundry.courier.update', $courier, $request, [], $data, 'Status petugas laundry diperbarui.');

        return back()->with('status', 'Status petugas diperbarui. Jadwal lama tetap tersimpan.');
    }

    public function create(Request $request)
    {
        return $this->page('transport.form', ['couriers' => LaundryCourier::where('is_active', true)->orderBy('name')->get(), 'customers' => Customer::orderBy('name')->get(['id', 'name', 'whatsapp_number', 'status']), 'orders' => LaundryOrder::where('status', '!=', 'cancelled')->where('workflow_status', '!=', 'handed_over')->latest('id')->limit(200)->get(), 'selectedOrder' => $request->query('order')]);
    }

    public function preview(Request $request, LaundryTransportService $service)
    {
        $data = $request->validate(['public_request_id' => ['nullable', 'integer', 'exists:laundry_public_requests,id'], 'entry_key' => ['required', 'uuid'], 'kind' => ['required', Rule::in(array_keys(LaundryTransportTask::KINDS))], 'laundry_order_id' => ['nullable', 'integer', 'exists:laundry_orders,id'], 'customer_id' => ['nullable', 'required_without:laundry_order_id', 'integer', 'exists:customers,id'], 'laundry_courier_id' => ['required', 'integer', 'exists:laundry_couriers,id'], 'scheduled_start' => ['required', 'date_format:Y-m-d\TH:i'], 'scheduled_end' => ['required', 'date_format:Y-m-d\TH:i'], 'address' => ['required', 'string', 'min:10', 'max:1500'], 'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'], 'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'], 'distance_source' => ['required', Rule::in(['coordinates', 'manual'])], 'distance_km' => ['nullable', 'required_if:distance_source,manual', 'numeric', 'min:0', 'max:500', 'decimal:0,3'], 'distance_note' => ['nullable', 'required_if:distance_source,manual', 'string', 'min:5', 'max:500'], 'approved_name' => ['required', 'string', 'max:150'], 'approval_reference' => ['required', 'string', 'min:5', 'max:500'], 'notes' => ['nullable', 'string', 'max:1500'], 'is_demo' => ['nullable', 'boolean']]);
        $request->session()->put('laundry.transport_draft', $data);
        $preview = $service->preview($data, $request->user());

        return $this->page('transport.preview', ['preview' => $preview, 'courier' => LaundryCourier::findOrFail($data['laundry_courier_id'])]);
    }

    public function store(Request $request, LaundryTransportService $service)
    {
        try {
            $data = $request->validate(['token' => ['required', 'string', 'max:100000'], 'confirmed' => ['required', 'accepted']]);
            $task = $service->create($data['token'], $request->user(), $request);
        } catch (ValidationException $exception) {
            return redirect()->route('admin.laundry.transport.create')->withErrors($exception->errors());
        }
        $request->session()->forget('laundry.transport_draft');

        return redirect()->route('admin.laundry.transport.show', $task)->with('status', 'Jadwal tersimpan. Ongkir pesanan terkait sudah masuk ke tagihan.');
    }

    public function show(LaundryTransportTask $task)
    {
        return $this->page('transport.show', ['task' => $task->load(['order.items', 'courier']), 'couriers' => LaundryCourier::where('is_active', true)->orderBy('name')->get(), 'orders' => LaundryOrder::where('customer_id', $task->customer_id)->where('is_demo', $task->is_demo)->where('intake_mode', 'collection')->where('status', '!=', 'cancelled')->where('workflow_status', '!=', 'handed_over')->latest('id')->limit(100)->get()]);
    }

    public function reschedule(Request $request, LaundryTransportTask $task, LaundryTransportService $service)
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'scheduled_start' => ['required', 'date_format:Y-m-d\TH:i'], 'scheduled_end' => ['required', 'date_format:Y-m-d\TH:i'], 'laundry_courier_id' => ['required', 'integer', 'exists:laundry_couriers,id'], 'reason' => ['required', 'string', 'min:5', 'max:1500']]);
        $service->reschedule($task, $data, $request->user(), $request);

        return back()->with('status', 'Jadwal/petugas diperbarui. Ongkir tetap memakai kesepakatan sebelumnya.');
    }

    public function action(Request $request, LaundryTransportTask $task, LaundryTransportService $service)
    {
        $rules = ['revision' => ['required', 'integer', 'min:1'], 'action' => ['required', Rule::in(['start', 'complete', 'fail', 'cancel'])], 'notes' => ['nullable', 'required_unless:action,start', 'string', 'min:5', 'max:1500'], 'receiver_name' => ['nullable', 'required_if:action,complete', 'string', 'max:150'], 'receiver_phone' => ['nullable', 'string', 'max:30'], 'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'mimetypes:image/jpeg,image/png', 'max:5120']];
        if ($request->input('action') === 'complete') {
            $rules['confirmed'] = ['required', 'accepted'];
            if ($task->kind === 'pickup') {
                $rules['pieces'] = ['required', 'integer', 'min:1', 'max:200000'];
            } else {
                $rules += ['items' => ['required', 'array', 'min:1', 'max:20'], 'items.*.id' => ['required', 'integer', 'distinct'], 'items.*.returned_pieces' => ['required', 'integer', 'min:1', 'max:10000'], 'items.*.label_confirmed' => ['required', 'accepted']];
            }
        }
        $data = $request->validate($rules);
        $service->action($task, $data, $request->file('proof'), $request->user(), $request);

        return back()->with('status', 'Status kunjungan tersimpan. Kunjungan batal/gagal tidak otomatis menghapus ongkir yang telah disepakati.');
    }

    public function attach(Request $request, LaundryTransportTask $task, LaundryTransportService $service)
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'laundry_order_id' => ['required', 'integer', 'exists:laundry_orders,id'], 'order_revision' => ['required', 'integer', 'min:1'], 'confirmed' => ['required', 'accepted']]);
        $service->attach($task, LaundryOrder::findOrFail($data['laundry_order_id']), $data, $request->user(), $request);

        return back()->with('status', 'Penjemputan dan ongkirnya sudah terhubung ke pesanan penerimaan.');
    }

    public function waive(Request $request, LaundryOrder $order, LaundryTransportService $service)
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'kind' => ['required', Rule::in(array_keys(LaundryTransportTask::KINDS))], 'approved_name' => ['required', 'string', 'max:150'], 'reason' => ['required', 'string', 'min:5', 'max:1500'], 'confirmed' => ['required', 'accepted']]);
        $service->waive($order, $data, $request->user(), $request);

        return back()->with('status', 'Pembebasan ongkir tersimpan dengan alasan dan persetujuannya.');
    }

    public function proof(LaundryTransportTask $task)
    {
        abort_unless($task->proof_path && str_starts_with($task->proof_path, 'laundry/transport-proofs/') && Storage::disk('local')->exists($task->proof_path), 404);

        return Storage::disk('local')->download($task->proof_path, 'bukti-kunjungan-'.$task->id.($task->proof_mime === 'image/png' ? '.png' : '.jpg'), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
