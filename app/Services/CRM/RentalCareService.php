<?php

namespace App\Services\CRM;

use App\Models\RentalCareRecord;
use App\Models\RentalItem;
use App\Models\RentalOrder;
use App\Models\RentalProduct;
use App\Models\RentalUnit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RentalCareService
{
    public function __construct(private readonly RentalInventory $inventory, private readonly AuditLogService $audit) {}

    public function checklist(array $labels, array $results, string $status): array
    {
        $rows = [];
        if (count($results) !== count($labels)) {
            throw new InvalidArgumentException('Lengkapi semua butir pemeriksaan sesuai checklist alat.');
        }
        foreach ($labels as $index => $label) {
            $result = $results[$index] ?? null;
            if (! in_array($result, ['ok', 'issue'], true)) {
                throw new InvalidArgumentException('Isi hasil setiap butir pemeriksaan: baik atau bermasalah.');
            }
            if ($status === 'ready' && $result !== 'ok') {
                throw new InvalidArgumentException('Unit dengan hasil pemeriksaan bermasalah belum dapat dinyatakan siap disewa.');
            }
            $rows[] = ['label' => $label, 'result' => $result];
        }

        return $rows;
    }

    public function checkoutHash(RentalItem $item, RentalUnit $unit): string
    {
        return hash('sha256', json_encode([$item->id, $unit->id, $unit->care_version, $unit->status, $unit->condition_notes, $unit->next_maintenance_at?->toDateString(), $item->checklistLabels()]));
    }

    public function checkoutCurrent(RentalItem $item, ?RentalUnit $unit = null): bool
    {
        $unit ??= $item->unit;

        return $item->checkout_inspected_at && hash_equals($item->checkout_state_hash ?? '', $this->checkoutHash($item, $unit));
    }

    public function assertCheckout(RentalItem $item, RentalUnit $unit): void
    {
        if ($unit->maintenanceDue()) {
            throw new InvalidArgumentException($unit->code.' sudah jatuh tempo perawatan. Selesaikan perawatan sebelum serah-terima.');
        }
        if ($unit->next_maintenance_at && $unit->next_maintenance_at->lte($item->rental_end_date ?? $item->order->end_date)) {
            throw new InvalidArgumentException($unit->code.' memiliki jadwal perawatan sebelum masa sewa selesai. Selesaikan perawatan atau ganti unit terlebih dahulu.');
        }
        if ($item->checklistLabels() !== [] && ! $this->checkoutCurrent($item, $unit)) {
            throw new InvalidArgumentException('Selesaikan pemeriksaan sebelum keluar untuk '.$item->unit_code.'. Jika kondisi unit berubah, periksa ulang.');
        }
    }

    public function checkout(RentalOrder $order, RentalItem $item, array $data, User $actor, Request $request): RentalCareRecord
    {
        return DB::transaction(function () use ($order, $item, $data, $actor, $request) {
            $order = RentalOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $item = $order->items()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $existing = RentalCareRecord::where('entry_key', $data['entry_key'])->first();
            if ($existing) {
                if ($existing->rental_item_id !== $item->id || $existing->rental_unit_id !== $item->rental_unit_id || $existing->kind !== 'checkout') {
                    throw new InvalidArgumentException('Form pemeriksaan sudah dipakai untuk unit lain. Muat ulang halaman.');
                }

                return $existing;
            }
            if ($order->status !== 'reserved') {
                throw new InvalidArgumentException('Pemeriksaan sebelum keluar hanya untuk pesanan terkonfirmasi yang belum diserahkan.');
            }
            RentalProduct::whereKey($item->unit->rental_product_id)->lockForUpdate()->firstOrFail();
            $unit = RentalUnit::whereKey($item->rental_unit_id)->lockForUpdate()->firstOrFail();
            if ($unit->status !== 'ready' || $this->inventory->hasPhysicalHold($unit) || $unit->maintenanceDue()) {
                throw new InvalidArgumentException('Unit belum siap atau jatuh tempo perawatan. Gunakan menu Perawatan Unit terlebih dahulu.');
            }
            if ($unit->next_maintenance_at && $unit->next_maintenance_at->lte($item->rental_end_date ?? $order->end_date)) {
                throw new InvalidArgumentException('Jadwal perawatan berada dalam masa sewa. Selesaikan perawatan atau ganti unit sebelum pemeriksaan keluar.');
            }
            if ((int) $data['care_version'] !== (int) $unit->care_version) {
                throw new InvalidArgumentException('Kondisi unit telah berubah. Muat ulang lalu periksa ulang unit.');
            }
            $checklist = $this->checklist($item->checklistLabels(), $data['checklist'] ?? [], 'ready');
            $record = $this->record($unit, 'checkout', $data['notes'], $actor, $request, [
                'rental_item_id' => $item->id, 'entry_key' => $data['entry_key'], 'checklist' => $checklist, 'photo_path' => $data['photo_path'] ?? null,
            ]);
            $item->update(['checkout_checklist' => $checklist, 'checkout_inspected_at' => now(), 'checkout_inspected_by' => $actor->id, 'checkout_photo_path' => $data['photo_path'] ?? null, 'checkout_state_hash' => $this->checkoutHash($item, $unit), 'checkout_condition' => $data['notes']]);

            return $record;
        });
    }

    public function care(RentalUnit $unit, array $data, User $actor, Request $request): RentalCareRecord
    {
        return DB::transaction(function () use ($unit, $data, $actor, $request) {
            // Same product -> unit locking order used by booking allocation.
            RentalProduct::whereKey($unit->rental_product_id)->lockForUpdate()->firstOrFail();
            $unit = RentalUnit::whereKey($unit->id)->lockForUpdate()->firstOrFail();
            $existing = RentalCareRecord::where('entry_key', $data['entry_key'])->first();
            if ($existing) {
                if ($existing->rental_unit_id !== $unit->id || ! in_array($existing->kind, ['inspection', 'cleaning', 'maintenance'], true)) {
                    throw new InvalidArgumentException('Form perawatan sudah dipakai. Muat ulang halaman.');
                }

                return $existing;
            }
            if ($this->inventory->hasPhysicalHold($unit)) {
                throw new InvalidArgumentException('Unit masih disewa atau menunggu pemeriksaan transaksi. Selesaikan pengembalian dan pemeriksaan melalui transaksi asal.');
            }
            if ((int) $data['care_version'] !== (int) $unit->care_version) {
                throw new InvalidArgumentException('Data unit sudah berubah. Muat ulang halaman sebelum mencatat perawatan.');
            }
            if ($unit->status === 'retired') {
                throw new InvalidArgumentException('Unit yang diarsipkan tidak dapat diaktifkan lewat perawatan.');
            }
            $checklist = $this->checklist($unit->product->inspection_checklist ?? [], $data['checklist'] ?? [], $data['unit_status']);
            $before = $unit->status;
            $unit->update(['status' => $data['unit_status'], 'condition_notes' => $data['notes'], 'last_inspected_at' => now(), 'next_maintenance_at' => $data['next_maintenance_at'] ?? null, 'care_version' => $unit->care_version + 1]);

            return $this->record($unit, $data['kind'], $data['notes'], $actor, $request, [
                'entry_key' => $data['entry_key'], 'status_before' => $before, 'checklist' => $checklist, 'photo_path' => $data['photo_path'] ?? null,
                'cost' => (int) ($data['cost'] ?? 0), 'vendor' => $data['vendor'] ?? null,
            ]);
        });
    }

    public function record(RentalUnit $unit, string $kind, string $notes, User $actor, Request $request, array $extra = []): RentalCareRecord
    {
        $record = RentalCareRecord::create(array_replace([
            'rental_unit_id' => $unit->id, 'performed_by' => $actor->id, 'entry_key' => (string) Str::uuid(), 'kind' => $kind,
            'status_before' => $unit->status, 'status_after' => $unit->status, 'notes' => $notes, 'performed_at' => now(), 'next_maintenance_at' => $unit->next_maintenance_at,
        ], $extra));
        $this->audit->log($actor, 'rental.care.'.$kind, $record, $request, [], ['unit' => $unit->code, 'status' => $unit->status, 'cost' => $record->cost], 'Riwayat pemeriksaan/perawatan unit dicatat');

        return $record;
    }
}
