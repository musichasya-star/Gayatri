<?php

namespace App\Services\CRM;

use App\Models\RentalItem;
use App\Models\RentalProduct;
use App\Models\RentalUnit;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class RentalInventory
{
    public function blockingOrders(Builder $query): void
    {
        $query->whereIn('status', ['pending', 'reserved', 'rented', 'inspection'])
            ->where(fn ($q) => $q->where('status', '!=', 'pending')->orWhere('hold_expires_at', '>', now()));
    }

    public function available(RentalProduct $product, string $start, string $end, ?int $ignoreOrder = null, ?int $turnaroundDays = null): Builder
    {
        $until = Carbon::parse($end)->addDays(1 + ($turnaroundDays ?? $product->turnaround_days))->toDateString();

        return RentalUnit::where('rental_product_id', $product->id)->whereIn('status', ['ready', 'rented'])
            ->where(fn ($q) => $q->whereNull('next_maintenance_at')->orWhereDate('next_maintenance_at', '>', $end))
            ->whereDoesntHave('items', function ($items) use ($start, $until, $ignoreOrder) {
                $items->whereNull('inspected_at')->when($ignoreOrder, fn ($q) => $q->where('rental_order_id', '!=', $ignoreOrder))
                    ->whereHas('order', function ($orders) use ($until) {
                        $this->blockingOrders($orders);
                        $orders->whereDate('start_date', '<', $until);
                    })->whereDate('reserved_until', '>', $start);
            })
            ->whereDoesntHave('items', fn ($items) => $items->when($ignoreOrder, fn ($q) => $q->where('rental_order_id', '!=', $ignoreOrder))->whereNull('returned_at')->whereHas('order', fn ($orders) => $orders->where('status', 'rented')->whereDate('end_date', '<', today())))
            ->orderBy('code');
    }

    public function hasPhysicalHold(RentalUnit $unit): bool
    {
        return RentalItem::where('rental_unit_id', $unit->id)->whereNull('inspected_at')->whereHas('order', fn ($q) => $q->whereIn('status', ['rented', 'inspection']))->exists();
    }
}
