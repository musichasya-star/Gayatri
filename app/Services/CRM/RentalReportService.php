<?php

namespace App\Services\CRM;

use App\Models\RentalCareRecord;
use App\Models\RentalCustomerRequest;
use App\Models\RentalItem;
use App\Models\RentalOrder;
use App\Models\RentalPayment;
use App\Models\RentalPaymentProof;
use App\Models\RentalProduct;
use App\Models\RentalUnit;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class RentalReportService
{
    public function orders(bool $includeDemo = false): Builder
    {
        return RentalOrder::forReport($includeDemo)
            ->withSum(['payments as paid_total' => fn ($q) => $q->where('kind', 'payment')], 'amount')
            ->withSum(['payments as refunded_total' => fn ($q) => $q->where('kind', 'refund')], 'amount')
            ->withSum('items as late_total', 'late_fee')->withSum('items as damage_total', 'damage_fee');
    }

    public function cash(Carbon $start, Carbon $end, bool $includeDemo = false): Builder
    {
        return RentalPayment::whereBetween('created_at', [$start, $end])->whereHas('order', fn ($q) => $q->forReport($includeDemo));
    }

    public function periodOrders(Carbon $start, Carbon $end, bool $includeDemo = false): Builder
    {
        return $this->orders($includeDemo)->whereDate('start_date', '>=', $start->toDateString())->whereDate('start_date', '<=', $end->toDateString());
    }

    public function care(Carbon $start, Carbon $end, bool $includeDemo = false): Builder
    {
        return RentalCareRecord::whereBetween('performed_at', [$start, $end])->where('cost', '>', 0)
            ->when(! $includeDemo, fn ($q) => $q->whereHas('unit', fn ($q) => $q->forReport())
                ->where(fn ($q) => $q->whereNull('rental_item_id')->orWhereHas('item.order', fn ($q) => $q->forReport())));
    }

    public function cents(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }

    /** Current ledger figures. Deposits are liabilities, never part of service charges. */
    public function figures(RentalOrder $order): array
    {
        $paid = $this->cents($order->paid_total ?? 0);
        $refunds = $this->cents($order->refunded_total ?? 0);
        $net = $paid - $refunds;
        $active = in_array($order->status, ['reserved', 'rented', 'inspection'], true);
        $confirmed = $order->status !== 'pending';
        $charges = ! $confirmed ? 0 : ($order->status === 'cancelled' ? $this->cents($order->cancellation_fee)
            : $this->cents($order->rental_subtotal) + $this->cents($order->delivery_fee) + $this->cents($order->collection_fee) + $this->cents($order->late_total ?? 0) + $this->cents($order->damage_total ?? 0));
        $deposit = $active ? $this->cents($order->deposit_total) : 0;

        return ['charges' => $charges, 'deposit_required' => $deposit, 'paid' => $paid, 'refunds' => $refunds, 'net_received' => $net,
            'balance' => $confirmed ? max(0, $charges + $deposit - $net) : 0,
            'deposit_held' => $active ? min($deposit, max(0, $net - $charges)) : 0,
            'refundable' => in_array($order->status, ['completed', 'cancelled'], true) ? max(0, $net - $charges) : 0];
    }

    public function report(Carbon $start, Carbon $end, bool $includeDemo = false): array
    {
        $months = [];
        for ($month = $start->copy()->startOfMonth(); $month->lte($end); $month->addMonth()) {
            $months[$month->format('Y-m')] = ['month' => $month->format('Y-m'), 'paid' => 0, 'refunds' => 0, 'net' => 0, 'care' => 0, 'charges' => 0];
        }
        $cash = $this->cash($start, $end, $includeDemo)->selectRaw('DATE(created_at) as report_day, kind, SUM(amount) as report_amount')->groupBy(DB::raw('DATE(created_at)'), 'kind')->get();
        foreach ($cash as $row) {
            $key = substr($row->report_day, 0, 7);
            $months[$key][$row->kind === 'refund' ? 'refunds' : 'paid'] += $this->cents($row->report_amount);
        }
        $costs = $this->care($start, $end, $includeDemo)->selectRaw('DATE(performed_at) as report_day, SUM(cost) as report_cost')->groupBy(DB::raw('DATE(performed_at)'))->get();
        foreach ($costs as $row) {
            $months[substr($row->report_day, 0, 7)]['care'] += $this->cents($row->report_cost);
        }
        $orderCounts = array_fill_keys(array_keys(RentalOrder::STATUSES), 0);
        $expired = 0;
        foreach ($this->periodOrders($start, $end, $includeDemo)->lazyById(200) as $order) {
            $orderCounts[$order->status]++;
            $expired += $order->isExpired() ? 1 : 0;
            $months[$order->start_date->format('Y-m')]['charges'] += $this->figures($order)['charges'];
        }
        $current = ['balance' => 0, 'deposit_held' => 0, 'refundable' => 0, 'approved_refunds' => 0];
        $attention = [];
        foreach ($this->orders($includeDemo)->where('status', '!=', 'pending')->with('customer:id,name')->lazyById(200) as $order) {
            $figures = $this->figures($order);
            foreach (['balance', 'deposit_held', 'refundable'] as $key) {
                $current[$key] += $figures[$key];
            }
            if ($figures['balance'] > 0 || $figures['refundable'] > 0) {
                $attention[] = ['id' => $order->id, 'code' => $order->code, 'name' => $order->customer_snapshot['name'] ?? $order->customer?->name, 'balance' => $figures['balance'], 'refundable' => $figures['refundable']];
            }
        }
        $current['approved_refunds'] = $this->cents(RentalCustomerRequest::where('status', 'approved')->whereNull('rental_payment_id')->whereHas('order', fn ($q) => $q->forReport($includeDemo))->sum('approved_amount'));
        $current['pending_proofs'] = RentalPaymentProof::where('status', 'pending')->whereHas('order', fn ($q) => $q->forReport($includeDemo))->count();
        $current['pending_requests'] = RentalCustomerRequest::where('status', 'pending')->whereHas('order', fn ($q) => $q->forReport($includeDemo))->count();
        $current['overdue_orders'] = RentalOrder::forReport($includeDemo)->where('status', 'rented')->whereDate('end_date', '<', today())->whereHas('items', fn ($q) => $q->whereNull('returned_at'))->count();
        $units = RentalUnit::forReport($includeDemo)->select('status')->selectRaw('COUNT(*) as unit_count')->groupBy('status')->pluck('unit_count', 'status')->all();
        foreach ($months as &$month) {
            $month['net'] = $month['paid'] - $month['refunds'];
        }
        unset($month);
        usort($attention, fn ($a, $b) => ($b['balance'] + $b['refundable']) <=> ($a['balance'] + $a['refundable']));

        return ['months' => array_values($months), 'period' => ['paid' => array_sum(array_column($months, 'paid')), 'refunds' => array_sum(array_column($months, 'refunds')), 'net' => array_sum(array_column($months, 'net')),
            'care' => array_sum(array_column($months, 'care')), 'charges' => array_sum(array_column($months, 'charges')), 'orders' => array_sum($orderCounts), 'expired' => $expired],
            'order_counts' => $orderCounts, 'current' => $current, 'unit_counts' => $units, 'attention' => $attention,
            'products' => $this->products($start, $end, $includeDemo)];
    }

    public function products(Carbon $start, Carbon $end, bool $includeDemo = false): array
    {
        $products = RentalProduct::orderBy('name')->get(['id', 'name', 'sku'])->mapWithKeys(fn ($p) => [$p->id => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'units' => 0, 'rented_units' => 0, 'rental' => 0, 'late_fee' => 0, 'damage_fee' => 0, 'capacity_days' => 0, 'used_days' => 0, 'utilization' => null]])->all();
        $units = RentalUnit::forReport($includeDemo)->where('created_at', '<=', $end)->get(['id', 'rental_product_id', 'created_at'])->keyBy('id');
        foreach ($units as $unit) {
            $products[$unit->rental_product_id]['units']++;
            $from = $start->copy()->startOfDay()->max(($unit->created_at ?? $start)->copy()->startOfDay());
            $until = $end->copy()->startOfDay()->min(today());
            $products[$unit->rental_product_id]['capacity_days'] += $from->lte($until) ? (int) $from->diffInDays($until) + 1 : 0;
        }
        RentalItem::whereHas('order', fn ($q) => $q->forReport($includeDemo)->whereIn('status', ['reserved', 'rented', 'inspection', 'completed'])->whereDate('start_date', '>=', $start->toDateString())->whereDate('start_date', '<=', $end->toDateString()))
            ->with('unit:id,rental_product_id')->lazyById(200)->each(function ($item) use (&$products) {
                $key = $item->unit->rental_product_id;
                $products[$key]['rented_units']++;
                foreach (['rental' => 'rental_price', 'late_fee' => 'late_fee', 'damage_fee' => 'damage_fee'] as $name => $attribute) {
                    $products[$key][$name] += $this->cents($item->$attribute);
                }
            });
        $intervals = [];
        RentalItem::whereHas('unit', fn ($q) => $q->forReport($includeDemo)->where('created_at', '<=', $end))->whereHas('order', fn ($q) => $q->forReport($includeDemo)->whereIn('status', ['rented', 'inspection', 'completed'])->whereNotNull('dispatched_at')->where('dispatched_at', '<=', $end))
            ->where(fn ($q) => $q->whereNull('returned_at')->orWhere('returned_at', '>=', $start))->with('order:id,dispatched_at')->lazyById(200)->each(function ($item) use (&$intervals, $units, $start, $end) {
                $unit = $units[$item->rental_unit_id];
                $from = $start->copy()->startOfDay()->max(($unit->created_at ?? $start)->copy()->startOfDay())->max($item->order->dispatched_at->copy()->startOfDay());
                $until = $end->copy()->startOfDay()->min(today())->min(($item->returned_at ?? now())->copy()->startOfDay());
                if ($from->lte($until)) {
                    $intervals[$unit->id][] = [$from, $until];
                }
            });
        foreach ($intervals as $unitId => $ranges) {
            usort($ranges, fn ($a, $b) => $a[0] <=> $b[0]);
            $merged = [];
            foreach ($ranges as [$from, $until]) {
                $last = array_key_last($merged);
                if ($last !== null && $from->lte($merged[$last][1]->copy()->addDay())) {
                    $merged[$last][1] = $merged[$last][1]->max($until);
                } else {
                    $merged[] = [$from, $until];
                }
            }
            foreach ($merged as [$from, $until]) {
                $products[$units[$unitId]->rental_product_id]['used_days'] += (int) $from->diffInDays($until) + 1;
            }
        }
        foreach ($products as &$product) {
            $product['utilization'] = $product['capacity_days'] > 0 ? round($product['used_days'] * 100 / $product['capacity_days'], 1) : null;
        }
        unset($product);
        $products = array_filter($products, fn ($p) => $p['units'] > 0 || $p['rented_units'] > 0);
        usort($products, fn ($a, $b) => $b['rental'] <=> $a['rental'] ?: $b['used_days'] <=> $a['used_days']);

        return array_values($products);
    }
}
