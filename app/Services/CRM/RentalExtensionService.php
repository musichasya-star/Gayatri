<?php

namespace App\Services\CRM;

use App\Models\RentalExtension;
use App\Models\RentalOrder;
use App\Models\RentalProduct;
use App\Models\RentalUnit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class RentalExtensionService
{
    public function __construct(private readonly RentalInventory $inventory, private readonly RentalSettings $settings, private readonly AuditLogService $audit) {}

    public function preview(RentalOrder $order, string $newEnd): array
    {
        $quote = $this->calculate($order->fresh(), $newEnd);
        $quote['entry_key'] = (string) Str::uuid();
        $quote['expires_at'] = now()->addMinutes(10)->timestamp;

        return ['quote' => $quote, 'token' => Crypt::encryptString(json_encode($quote))];
    }

    public function apply(RentalOrder $order, array $data, User $actor, Request $request): RentalExtension
    {
        try {
            $quote = json_decode(Crypt::decryptString($data['extension_token']), true, flags: JSON_THROW_ON_ERROR);
            if (($quote['order_id'] ?? null) !== $order->id || ($quote['new_end_date'] ?? '') !== $data['new_end_date']) {
                throw new InvalidArgumentException;
            }
        } catch (Throwable) {
            throw new InvalidArgumentException('Hitung ulang biaya perpanjangan terlebih dahulu.');
        }

        return DB::transaction(function () use ($order, $data, $quote, $actor, $request) {
            $order = RentalOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $existing = $order->extensions()->where('entry_key', $quote['entry_key'])->first();
            if ($existing) {
                return $existing;
            }
            if ($quote['expires_at'] < now()->timestamp) {
                throw new InvalidArgumentException('Estimasi perpanjangan kedaluwarsa. Hitung ulang biaya.');
            }
            $order->load('items.unit.product');
            RentalProduct::whereIn('id', $order->items->pluck('unit.rental_product_id'))->orderBy('id')->lockForUpdate()->get();
            RentalUnit::whereIn('id', $order->items->whereNull('returned_at')->pluck('rental_unit_id'))->orderBy('id')->lockForUpdate()->get();
            $current = $this->calculate($order, $data['new_end_date']);
            if ($current['state_hash'] !== $quote['state_hash'] || $current['additional_charge'] !== $quote['additional_charge']) {
                throw new InvalidArgumentException('Transaksi telah berubah. Cek ulang perpanjangan dan biaya terbaru.');
            }
            foreach ($current['rows'] as $row) {
                $item = $order->items->firstWhere('id', $row['item_id']);
                $item->update(['rental_end_date' => $data['new_end_date'], 'reserved_until' => $row['reserved_until'], 'rental_price' => (float) $item->rental_price + $row['additional_charge']]);
            }
            $order->update(['end_date' => $data['new_end_date'], 'period_count' => $current['new_period_count'], 'schedule_version' => (int) $order->schedule_version + 1, 'rental_subtotal' => (float) $order->rental_subtotal + $current['additional_charge']]);
            $extension = $order->extensions()->create(['entry_key' => $quote['entry_key'], 'created_by' => $actor->id, 'old_end_date' => $current['old_end_date'], 'new_end_date' => $data['new_end_date'], 'schedule_version' => $order->schedule_version, 'additional_charge' => $current['additional_charge'], 'pricing_snapshot' => $current, 'notes' => $data['notes']]);
            $this->audit->log($actor, 'rental.extend', $extension, $request, ['end_date' => $current['old_end_date']], ['end_date' => $data['new_end_date'], 'additional_charge' => $current['additional_charge']], 'Perpanjangan sewa disimpan');
            app(RentalNotificationService::class)->enqueue($order, 'extended', 'extension:'.$extension->id, ['extension_id' => $extension->id, 'schedule_version' => $order->schedule_version]);

            return $extension;
        });
    }

    private function calculate(RentalOrder $order, string $newEnd): array
    {
        if ($order->customerRequests()->where('kind', 'cancellation')->where('status', 'pending')->exists()) {
            throw new InvalidArgumentException('Tinjau pengajuan pembatalan sebelum memperpanjang pesanan.');
        }
        $order->loadMissing('items.unit.product');
        $end = Carbon::createFromFormat('!Y-m-d', $newEnd);
        if (! in_array($order->status, ['reserved', 'rented'], true) || ! $end || $end->lte($order->end_date) || $end->lt(today())) {
            throw new InvalidArgumentException('Perpanjangan hanya untuk pesanan terkonfirmasi/sedang disewa, dengan tanggal kembali lebih lama dan tidak di masa lalu.');
        }
        $days = (int) $order->start_date->diffInDays($end) + 1;
        if ($days > $this->settings->get()['max_rental_days']) {
            throw new InvalidArgumentException('Total masa sewa melebihi batas maksimal rental.');
        }
        $newCount = (int) ceil($days / match ($order->period) {
            'week' => 7, 'fortnight' => 14, 'month' => 30, default => 1
        });
        $extraCount = max(0, $newCount - $order->period_count);
        $open = $order->items->whereNull('returned_at');
        if ($open->isEmpty()) {
            throw new InvalidArgumentException('Semua unit sudah kembali; transaksi tidak dapat diperpanjang.');
        }
        $rows = [];
        foreach ($open as $item) {
            $row = collect($order->pricing_snapshot['rows'] ?? [])->firstWhere('product_id', $item->unit->rental_product_id);
            if (! $row || ! isset($row['rate'], $row['turnaround_days'])) {
                throw new InvalidArgumentException('Tarif awal tidak lengkap; perpanjangan tidak dapat dihitung.');
            }
            $available = $this->inventory->available($item->unit->product, $order->start_date->toDateString(), $newEnd, $order->id, (int) $row['turnaround_days'])->whereKey($item->rental_unit_id)->exists();
            if (! $available) {
                $until = $end->copy()->addDays(1 + (int) $row['turnaround_days']);
                $other = $item->unit->items()->where('rental_order_id', '!=', $order->id)->whereNull('inspected_at')->whereDate('reserved_until', '>', $order->start_date->toDateString())->whereHas('order', function ($q) use ($until) {
                    $this->inventory->blockingOrders($q);
                    $q->whereDate('start_date', '<', $until->toDateString());
                })->with('order')->first();
                throw new InvalidArgumentException($item->unit_code.' tidak dapat diperpanjang'.($other ? ': bentrok dengan '.$other->order->code.' ('.$other->order->start_date->format('d M Y').'—'.$other->order->end_date->format('d M Y').'), termasuk jeda pembersihan.' : ': unit belum siap atau masih terkait transaksi lain.'));
            }
            $rows[] = ['item_id' => $item->id, 'unit_id' => $item->rental_unit_id, 'unit_code' => $item->unit_code, 'rate' => (int) $row['rate'], 'extra_periods' => $extraCount, 'additional_charge' => (int) $row['rate'] * $extraCount, 'reserved_until' => $end->copy()->addDays(1 + (int) $row['turnaround_days'])->toDateString()];
        }

        return ['order_id' => $order->id, 'old_end_date' => $order->end_date->toDateString(), 'new_end_date' => $newEnd, 'new_period_count' => $newCount, 'extra_periods' => $extraCount, 'additional_charge' => array_sum(array_column($rows, 'additional_charge')), 'rows' => $rows,
            'state_hash' => hash('sha256', json_encode([$order->id, $order->status, $order->schedule_version, $order->end_date->toDateString(), (float) $order->rental_subtotal, $open->map(fn ($item) => [$item->id, $item->rental_unit_id, (float) $item->rental_price])->values()->all(), $this->settings->get()['max_rental_days']]))];
    }
}
