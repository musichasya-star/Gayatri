<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\RentalItem;
use App\Models\RentalOrder;
use App\Models\RentalProduct;
use App\Models\RentalUnit;
use App\Services\CRM\RentalExtensionService;
use App\Services\CRM\RentalInventory;
use App\Services\CRM\RentalOperations;
use App\Services\CRM\RentalQuote;
use App\Services\CRM\RentalService;
use App\Services\CRM\RentalSettings;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

class RentalOrderController extends Controller
{
    public function index(Request $request, RentalOperations $operations)
    {
        $request->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'], 'status' => ['nullable', 'in:pending,reserved,rented,inspection,completed,cancelled'], 'customer_id' => ['nullable', 'integer', 'exists:customers,id']]);
        $orders = RentalOrder::with(['customer', 'items', 'payments'])->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q->where('code', 'like', '%'.$request->input('search').'%')->orWhereHas('customer', fn ($c) => $c->where('name', 'like', '%'.$request->input('search').'%'))))->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))->latest()->paginate(15)->withQueryString();

        $alerts = $operations->alerts();

        return view('admin.rentals.orders.index', compact('orders', 'alerts'));
    }

    public function create(RentalSettings $settings)
    {
        return view('admin.rentals.orders.create', ['products' => RentalProduct::where('is_active', true)->orderBy('name')->get(), 'customers' => Customer::where('status', '!=', 'blacklisted')->orderBy('name')->get(), 'settings' => $settings->get()]);
    }

    public function store(Request $request, RentalService $service)
    {
        $data = $request->validate([...RentalQuote::rules(), 'customer_id' => ['required', 'exists:customers,id'], 'quote_token' => ['required', 'string', 'max:1000000'], 'notes' => ['nullable', 'string', 'max:3000'], 'is_demo' => ['sometimes', 'boolean']]);
        try {
            $order = $service->create($data, $request->user(), $request);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['rental' => $e->getMessage()])->withInput();
        }

        return redirect()->route('admin.rentals.orders.show', $order)->with('status', 'Transaksi dibuat. Konfirmasi pesanan untuk mengunci reservasi.');
    }

    public function show(RentalOrder $order, RentalInventory $inventory, RentalOperations $operations)
    {
        $order->load(['customer', 'items.unit.product', 'payments', 'extensions']);
        $replacementUnits = [];
        if (in_array($order->status, ['pending', 'reserved'], true) && ! $order->isExpired()) {
            foreach ($order->items as $item) {
                $replacementUnits[$item->id] = $inventory->available($item->unit->product, $order->start_date->toDateString(), $order->end_date->toDateString(), $order->id)->whereNotIn('id', $order->items->pluck('rental_unit_id'))->get();
            }
        }

        $alerts = $operations->alerts($order);

        return view('admin.rentals.orders.show', compact('order', 'replacementUnits', 'alerts'));
    }

    public function extensionPreview(Request $request, RentalOrder $order, RentalExtensionService $service)
    {
        $data = $request->validate(['new_end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today']]);
        try {
            return response()->json($service->preview($order, $data['new_end_date']));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function extend(Request $request, RentalOrder $order, RentalExtensionService $service)
    {
        $data = $request->validate(['new_end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'], 'extension_token' => ['required', 'string', 'max:30000'], 'notes' => ['required', 'string', 'min:5', 'max:3000']]);
        try {
            $service->apply($order, $data, $request->user(), $request);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['rental' => $e->getMessage()]);
        }

        return back()->with('status', 'Perpanjangan tersimpan. Jadwal dan tagihan sudah diperbarui.');
    }

    public function operations(Request $request, RentalOperations $operations)
    {
        $request->validate(['severity' => ['nullable', 'in:critical,warning'], 'type' => ['nullable', 'string', 'max:150']]);
        $allAlerts = $operations->alerts();
        $alerts = $allAlerts->filter(fn ($alert) => (! $request->filled('severity') || $alert['severity'] === $request->input('severity')) && (! $request->filled('type') || $alert['type'] === $request->input('type')))->values();

        return view('admin.rentals.operations', compact('alerts', 'allAlerts'));
    }

    public function action(Request $request, RentalOrder $order, RentalService $service)
    {
        $data = $request->validate(['action' => ['required', 'in:confirm,dispatch,cancel'], 'notes' => ['nullable', 'string', 'max:3000']]);
        try {
            $service->action($order, $data['action'], $data, $request->user(), $request);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['rental' => $e->getMessage()]);
        }

        return back()->with('status', 'Status rental diperbarui.');
    }

    public function returnItem(Request $request, RentalOrder $order, RentalItem $item, RentalService $service)
    {
        abort_unless($item->rental_order_id === $order->id, 404);
        $data = $request->validate(['notes' => ['required', 'string', 'min:3', 'max:3000'], 'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);
        $path = $request->file('photo')?->store('rentals/returns', 'local');
        try {
            $service->returnItem($order, $item, ['notes' => $data['notes'], 'photo_path' => $path], $request->user(), $request);
        } catch (Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            if (! $e instanceof InvalidArgumentException) {
                throw $e;
            }

            return back()->withErrors(['rental' => $e->getMessage()]);
        }

        return back()->with('status', 'Unit diterima. Selesaikan pemeriksaan sebelum unit tersedia kembali.');
    }

    public function inspect(Request $request, RentalOrder $order, RentalItem $item, RentalService $service)
    {
        abort_unless($item->rental_order_id === $order->id, 404);
        $data = $request->validate(['notes' => ['required', 'string', 'min:3', 'max:3000'], 'checklist' => ['nullable', 'array', 'max:30'], 'checklist.*' => ['required', 'in:ok,issue'], 'unit_status' => ['required', 'in:ready,cleaning,maintenance,retired'], 'late_fee' => ['required', 'integer', 'min:0', 'max:100000000'], 'damage_fee' => ['required', 'integer', 'min:0', 'max:100000000'], 'late_fee_reason' => ['nullable', 'string', 'max:1000']]);
        try {
            $service->inspect($order, $item, $data, $request->user(), $request);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['rental' => $e->getMessage()]);
        }

        return back()->with('status', 'Pemeriksaan tersimpan. Biaya tambahan dan saldo deposit sudah dihitung ulang.');
    }

    public function payment(Request $request, RentalOrder $order, RentalService $service)
    {
        $data = $request->validate(['entry_key' => ['required', 'uuid'], 'kind' => ['required', 'in:payment,refund'], 'amount' => ['required', 'integer', 'min:1', 'max:100000000000'], 'method' => ['required', 'in:cash,transfer,other'], 'party_name' => ['nullable', 'string', 'max:150'], 'reference' => ['nullable', 'string', 'max:150'], 'notes' => ['nullable', 'string', 'max:2000']]);
        try {
            $service->payment($order, $data, $request->user(), $request);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['rental' => $e->getMessage()]);
        }

        return back()->with('status', 'Pencatatan pembayaran tersimpan.');
    }

    public function replace(Request $request, RentalOrder $order, RentalItem $item, RentalService $service)
    {
        abort_unless($item->rental_order_id === $order->id, 404);
        $data = $request->validate(['unit_id' => ['required', 'integer', 'exists:rental_units,id']]);
        try {
            $service->replaceUnit($order, $item, $data['unit_id'], $request->user(), $request);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['rental' => $e->getMessage()]);
        }

        return back()->with('status', 'Unit diganti; tarif transaksi tetap.');
    }

    public function photo(RentalItem $item)
    {
        abort_unless($item->return_photo_path && Storage::disk('local')->exists($item->return_photo_path), 404);

        return Storage::disk('local')->response($item->return_photo_path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function calendar(Request $request)
    {
        $request->validate(['month' => ['nullable', 'date_format:Y-m'], 'product_id' => ['nullable', 'integer', 'exists:rental_products,id']]);
        $month = Carbon::createFromFormat('!Y-m', $request->input('month', now()->format('Y-m')));
        $units = RentalUnit::with(['product', 'items' => fn ($q) => $q->with('order')->whereHas('order', fn ($orders) => $orders->whereNotIn('status', ['cancelled', 'completed']))])
            ->when($request->filled('product_id'), fn ($q) => $q->where('rental_product_id', $request->integer('product_id')))->orderBy('code')->paginate(20)->withQueryString();

        return view('admin.rentals.calendar', ['units' => $units, 'month' => $month, 'products' => RentalProduct::orderBy('name')->get()]);
    }
}
