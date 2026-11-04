<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RentalCareRecord;
use App\Models\RentalItem;
use App\Models\RentalOrder;
use App\Models\RentalUnit;
use App\Services\CRM\RentalCareService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

class RentalCareController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['status' => ['nullable', 'in:ready,rented,inspection,cleaning,maintenance,retired'], 'due' => ['nullable', 'in:1'], 'search' => ['nullable', 'string', 'max:150']]);
        $units = RentalUnit::with('product')->withCount('careRecords')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->boolean('due'), fn ($q) => $q->whereDate('next_maintenance_at', '<=', today()->addDays(7)))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q->where('code', 'like', '%'.$request->input('search').'%')->orWhereHas('product', fn ($p) => $p->where('name', 'like', '%'.$request->input('search').'%'))))
            ->orderBy('code')->paginate(20)->withQueryString();

        return view('admin.rentals.care.index', ['units' => $units, 'dueCount' => RentalUnit::where('status', '!=', 'retired')->whereDate('next_maintenance_at', '<=', today()->addDays(7))->count()]);
    }

    public function show(RentalUnit $unit)
    {
        $unit->load('product');
        $records = $unit->careRecords()->with(['performer', 'item.order'])->latest('id')->paginate(12);

        return response()->view('admin.rentals.care.show', compact('unit', 'records'))->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, RentalUnit $unit, RentalCareService $service)
    {
        $data = $request->validate($this->rules() + [
            'kind' => ['required', 'in:inspection,cleaning,maintenance'], 'unit_status' => ['required', 'in:ready,cleaning,maintenance,retired'],
            'cost' => ['required', 'integer', 'min:0', 'max:1000000000'], 'vendor' => ['nullable', 'string', 'max:150'], 'next_maintenance_at' => ['nullable', 'date_format:Y-m-d', 'after:today'],
        ]);

        return $this->savePhoto($request, $data, fn ($data) => $service->care($unit, $data, $request->user(), $request));
    }

    public function checkout(Request $request, RentalOrder $order, RentalItem $item, RentalCareService $service)
    {
        abort_unless($item->rental_order_id === $order->id, 404);
        $data = $request->validate($this->rules());

        return $this->savePhoto($request, $data, fn ($data) => $service->checkout($order, $item, $data, $request->user(), $request));
    }

    private function rules(): array
    {
        return ['entry_key' => ['required', 'uuid'], 'care_version' => ['required', 'integer', 'min:0'], 'notes' => ['required', 'string', 'min:3', 'max:3000'], 'checklist' => ['nullable', 'array', 'max:30'], 'checklist.*' => ['required', 'in:ok,issue'], 'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']];
    }

    private function savePhoto(Request $request, array $data, callable $operation)
    {
        $path = $request->file('photo')?->store('rentals/care', 'local');
        unset($data['photo']);
        try {
            $record = $operation($data + ['photo_path' => $path]);
            if ($path && $record->photo_path !== $path) {
                Storage::disk('local')->delete($path);
            }
        } catch (Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            if (! $e instanceof InvalidArgumentException) {
                throw $e;
            }

            return back()->withErrors(['rental' => $e->getMessage()])->withInput();
        }

        return back()->with('status', 'Pemeriksaan/perawatan tersimpan dalam riwayat unit.');
    }

    public function photo(RentalCareRecord $record)
    {
        abort_unless($record->photo_path && Storage::disk('local')->exists($record->photo_path), 404);

        return Storage::disk('local')->response($record->photo_path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
