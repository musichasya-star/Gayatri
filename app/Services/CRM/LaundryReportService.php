<?php

namespace App\Services\CRM;

use App\Models\LaundryOrder;
use App\Models\LaundryOrderItem;
use App\Models\LaundryPayment;
use App\Models\LaundryPaymentProof;
use App\Models\LaundryPublicRequest;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class LaundryReportService
{
    public function orders(bool $includeDemo = false): Builder
    {
        return LaundryOrder::query()->when(! $includeDemo, fn ($q) => $q->where('laundry_orders.is_demo', false));
    }

    private function period(Builder $query, string $column, Carbon $start, Carbon $end): Builder
    {
        // Half-open WIB bounds include the whole last day on both SQLite and MySQL.
        return $query->where($column, '>=', $start->copy()->startOfDay()->toDateTimeString())
            ->where($column, '<', $end->copy()->addDay()->startOfDay()->toDateTimeString());
    }

    public function periodOrders(Carbon $start, Carbon $end, bool $includeDemo = false): Builder
    {
        return $this->period($this->orders($includeDemo), 'received_at', $start, $end);
    }

    public function payments(Carbon $start, Carbon $end, bool $includeDemo = false): Builder
    {
        return $this->period(LaundryPayment::whereNull('voided_at')->whereHas('order', fn ($q) => $q->when(! $includeDemo, fn ($q) => $q->where('is_demo', false))), 'paid_at', $start, $end);
    }

    public function corrections(Carbon $start, Carbon $end, bool $includeDemo = false): Builder
    {
        return $this->period(LaundryPayment::whereNotNull('voided_at')->whereHas('order', fn ($q) => $q->when(! $includeDemo, fn ($q) => $q->where('is_demo', false))), 'voided_at', $start, $end);
    }

    public function receivables(bool $includeDemo = false): Builder
    {
        $paid = LaundryPayment::whereNull('voided_at')->select('laundry_order_id')->selectRaw('SUM(amount) AS paid_total')->groupBy('laundry_order_id');

        return $this->orders($includeDemo)->where('laundry_orders.status', 'approved')->whereNotNull('actual_total')
            ->leftJoinSub($paid, 'laundry_report_payments', fn ($join) => $join->on('laundry_report_payments.laundry_order_id', '=', 'laundry_orders.id'))
            ->select('laundry_orders.*')->selectRaw('COALESCE(laundry_report_payments.paid_total, 0) AS report_paid')
            ->whereRaw('(actual_total + COALESCE(pickup_fee, 0) + COALESCE(delivery_fee, 0)) > COALESCE(laundry_report_payments.paid_total, 0)');
    }

    public function figures(LaundryOrder $order): array
    {
        $paid = array_key_exists('report_paid', $order->getAttributes()) ? (int) $order->report_paid : $order->paidAmount();
        $approved = $order->status === 'approved' && $order->actual_total !== null;

        return ['service' => $approved ? $order->actual_total : null, 'pickup' => (int) $order->pickup_fee, 'delivery' => (int) $order->delivery_fee,
            'total' => $approved ? $order->totalDue() : null, 'paid' => $paid,
            'balance' => $approved ? max(0, $order->totalDue() - $paid) : ($order->status === 'cancelled' ? 0 : null)];
    }

    public function services(Carbon $start, Carbon $end, bool $includeDemo = false): array
    {
        $query = LaundryOrderItem::whereHas('order', fn ($q) => $this->period($q, 'received_at', $start, $end)->where('status', 'approved')->when(! $includeDemo, fn ($q) => $q->where('is_demo', false)));

        return $query->select('laundry_tariff_id', 'tariff_snapshot->code as service_code', 'tariff_snapshot->name as service_name', 'tariff_snapshot->unit as service_unit')
            ->selectRaw('COUNT(*) AS bags, COUNT(DISTINCT laundry_order_id) AS orders, SUM(actual_milli) AS quantity_milli, SUM(actual_billable_milli) AS billable_milli, SUM(actual_pieces) AS pieces, SUM(actual_amount) AS service_total')
            ->groupBy('laundry_tariff_id', 'service_code', 'service_name', 'service_unit')->orderByDesc('service_total')->orderBy('service_code')->get()
            ->map(fn ($row) => ['tariff_id' => $row->laundry_tariff_id, 'code' => $row->service_code, 'name' => $row->service_name, 'unit' => $row->service_unit,
                'bags' => (int) $row->bags, 'orders' => (int) $row->orders, 'quantity_milli' => (int) $row->quantity_milli, 'billable_milli' => (int) $row->billable_milli,
                'pieces' => (int) $row->pieces, 'service_total' => (int) $row->service_total])->all();
    }

    public function report(Carbon $start, Carbon $end, bool $includeDemo = false): array
    {
        $cohort = $this->periodOrders($start, $end, $includeDemo);
        $approved = (clone $cohort)->where('status', 'approved')->whereNotNull('actual_total');
        $charges = (clone $approved)->selectRaw('COALESCE(SUM(actual_total), 0) AS service, COALESCE(SUM(pickup_fee), 0) AS pickup, COALESCE(SUM(delivery_fee), 0) AS delivery')->first();
        $statusCounts = (clone $cohort)->select('status')->selectRaw('COUNT(*) AS count')->groupBy('status')->pluck('count', 'status')->map(fn ($n) => (int) $n)->all();
        $payments = $this->payments($start, $end, $includeDemo);
        $methods = (clone $payments)->select('method')->selectRaw('SUM(amount) AS amount')->groupBy('method')->pluck('amount', 'method')->map(fn ($n) => (int) $n)->all();
        $services = $this->services($start, $end, $includeDemo);
        $days = [];
        for ($day = $start->copy()->startOfDay(); $day->lte($end); $day->addDay()) {
            $date = $day->toDateString();
            $days[$date] = ['date' => $date, 'orders' => 0, 'completed' => 0, 'service' => 0, 'pickup' => 0, 'delivery' => 0, 'paid' => 0];
        }
        $groups = [
            ['query' => clone $cohort, 'column' => 'received_at', 'fields' => 'COUNT(*) AS orders', 'keys' => ['orders']],
            ['query' => clone $approved, 'column' => 'received_at', 'fields' => 'SUM(actual_total) AS service, SUM(pickup_fee) AS pickup, SUM(delivery_fee) AS delivery', 'keys' => ['service', 'pickup', 'delivery']],
            ['query' => clone $payments, 'column' => 'paid_at', 'fields' => 'SUM(amount) AS paid', 'keys' => ['paid']],
            ['query' => $this->period($this->orders($includeDemo)->where('status', '!=', 'cancelled')->where('workflow_status', 'handed_over'), 'handed_over_at', $start, $end), 'column' => 'handed_over_at', 'fields' => 'COUNT(*) AS completed', 'keys' => ['completed']],
        ];
        foreach ($groups as $group) {
            foreach ($group['query']->selectRaw('DATE('.$group['column'].') AS report_date, '.$group['fields'])->groupBy('report_date')->get() as $row) {
                foreach ($group['keys'] as $key) {
                    $days[$row->report_date][$key] = (int) $row->$key;
                }
            }
        }
        $current = $this->orders($includeDemo)->where('status', '!=', 'cancelled');
        $open = (clone $current)->whereNotIn('workflow_status', ['ready', 'handed_over', 'cancelled']);
        $balances = DB::query()->fromSub($this->receivables($includeDemo)->toBase(), 'receivables')->selectRaw('COUNT(*) AS count, COALESCE(SUM(actual_total + COALESCE(pickup_fee, 0) + COALESCE(delivery_fee, 0) - report_paid), 0) AS amount')->first();
        $workflowCounts = (clone $current)->select('workflow_status')->selectRaw('COUNT(*) AS count')->groupBy('workflow_status')->pluck('count', 'workflow_status')->map(fn ($n) => (int) $n)->all();

        return ['period' => ['orders' => array_sum($statusCounts), 'approved_orders' => $statusCounts['approved'] ?? 0,
            'service' => (int) $charges->service, 'pickup' => (int) $charges->pickup, 'delivery' => (int) $charges->delivery,
            'charges' => (int) $charges->service + (int) $charges->pickup + (int) $charges->delivery,
            'paid' => array_sum($methods), 'completed' => array_sum(array_column($days, 'completed')),
            'kg_milli' => array_sum(array_column(array_filter($services, fn ($s) => $s['unit'] === 'kg'), 'quantity_milli')),
            'piece_count' => intdiv(array_sum(array_column(array_filter($services, fn ($s) => $s['unit'] === 'piece'), 'quantity_milli')), 1000),
            'corrections' => $this->corrections($start, $end, $includeDemo)->count(),
            'correction_amount' => (int) $this->corrections($start, $end, $includeDemo)->sum('amount')],
            'current' => ['open' => (clone $current)->where('workflow_status', '!=', 'handed_over')->count(),
                'overdue' => (clone $open)->where('estimated_ready_at', '<', now())->count(),
                'ready' => (clone $current)->where('workflow_status', 'ready')->count(),
                'awaiting' => (clone $current)->where('status', 'awaiting_approval')->count(),
                'unweighed' => (clone $current)->whereNull('actual_total')->count(),
                'pending_requests' => LaundryPublicRequest::where('status', 'pending')->when(! $includeDemo, fn ($q) => $q->where('is_demo', false))->count(),
                'pending_proofs' => LaundryPaymentProof::where('status', 'pending')->whereHas('order', fn ($q) => $q->when(! $includeDemo, fn ($q) => $q->where('is_demo', false)))->count(),
                'receivable_orders' => (int) $balances->count, 'balance' => (int) $balances->amount],
            'days' => array_values($days), 'services' => $services, 'status_counts' => $statusCounts, 'workflow_counts' => $workflowCounts, 'methods' => $methods];
    }
}
