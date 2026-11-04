<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RentalProduct;
use App\Models\RentalUnit;
use App\Services\CRM\AuditLogService;
use App\Services\CRM\RentalCareService;
use App\Services\CRM\RentalInventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class RentalUnitController extends Controller
{
    public function index(Request $request)
    {
        $units = RentalUnit::with('product')->when($request->filled('product_id'), fn ($q) => $q->where('rental_product_id', $request->integer('product_id')))->orderBy('code')->paginate(20)->withQueryString();

        return view('admin.rentals.units', ['units' => $units, 'products' => RentalProduct::orderBy('name')->get()]);
    }

    public function store(Request $request, RentalInventory $inventory, AuditLogService $audit)
    {
        return $this->save($request, null, $inventory, $audit);
    }

    public function update(Request $request, RentalUnit $unit, RentalInventory $inventory, AuditLogService $audit)
    {
        return $this->save($request, $unit, $inventory, $audit);
    }

    private function save(Request $request, ?RentalUnit $unit, RentalInventory $inventory, AuditLogService $audit)
    {
        $data = $request->validate([
            'rental_product_id' => ['required', 'integer', 'exists:rental_products,id'],
            'code' => ['required', 'string', 'max:60', Rule::unique('rental_units')->ignore($unit)],
            'serial_number' => ['nullable', 'string', 'max:100'], 'condition_notes' => ['required', 'string', 'max:3000'],
            'status' => ['required', 'in:ready,cleaning,maintenance,retired'],
            'is_demo' => ['sometimes', 'boolean'],
        ]);
        $data['is_demo'] = $request->has('is_demo') ? $request->boolean('is_demo') : ($unit?->is_demo ?? false);
        try {
            DB::transaction(function () use ($request, $unit, $data, $inventory, $audit) {
                RentalProduct::whereKey($data['rental_product_id'])->lockForUpdate()->firstOrFail();
                $unit = $unit ? RentalUnit::whereKey($unit->id)->lockForUpdate()->firstOrFail() : new RentalUnit;
                $old = $unit->getAttributes();
                if ($unit->exists) {
                    if ($inventory->hasPhysicalHold($unit)) {
                        throw new InvalidArgumentException('Gunakan alur pengembalian dan pemeriksaan untuk unit yang sedang disewa/menunggu pemeriksaan.');
                    }
                    if ($data['status'] === 'ready' && ($unit->maintenanceDue() || ($unit->status !== 'ready' && ! empty($unit->product->inspection_checklist)))) {
                        throw new InvalidArgumentException('Selesaikan checklist dan perawatan melalui menu Perawatan Unit sebelum menyatakan unit siap.');
                    }
                    if ($unit->items()->exists() && ((int) $data['rental_product_id'] !== (int) $unit->rental_product_id || $data['code'] !== $unit->code)) {
                        throw new InvalidArgumentException('Produk dan kode unit yang sudah memiliki riwayat sewa tidak dapat diganti.');
                    }
                    if ($data['status'] !== 'ready' && $unit->items()->whereNull('inspected_at')->whereHas('order', function ($q) use ($inventory) {
                        $inventory->blockingOrders($q);
                    })->exists()) {
                        throw new InvalidArgumentException('Unit memiliki pesanan aktif. Ganti unit atau batalkan pesanan terlebih dahulu.');
                    }
                }
                $unit->fill($data);
                if (! $unit->exists && $data['status'] === 'ready') {
                    $unit->last_inspected_at = now();
                }
                $isNew = ! $unit->exists;
                $unit->care_version = (int) $unit->care_version + 1;
                $unit->save();
                app(RentalCareService::class)->record($unit, $isNew ? 'registered' : 'status', $data['condition_notes'], $request->user(), $request, ['status_before' => $old['status'] ?? null]);
                $audit->log($request->user(), 'rental.unit.save', $unit, $request, $old, $data, 'Stok unit diperbarui');
            });
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['rental' => $e->getMessage()])->withInput();
        }

        return back()->with('status', 'Unit rental tersimpan.');
    }
}
