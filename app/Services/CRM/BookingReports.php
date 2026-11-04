<?php

namespace App\Services\CRM;

use App\Models\AiLog;
use App\Models\Booking;
use App\Models\BookingAddon;
use App\Models\BookingPayment;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Followup;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class BookingReports
{
    public const TYPES = ['customers', 'bookings', 'revenue', 'payments', 'balances', 'campaigns', 'followups', 'ai'];

    public function period(Builder $query, string $column, Carbon $start, Carbon $end): Builder
    {
        return $query->where($column, '>=', $start->copy()->startOfDay()->toDateTimeString())->where($column, '<', $end->copy()->addDay()->startOfDay()->toDateTimeString());
    }

    public function bookings(): Builder
    {
        return Booking::query()->where(fn ($q) => $q->whereNull('notes')->orWhere('notes', 'not like', '[DEMO]%'))->whereHas('customer', fn ($q) => $q->where('name', 'not like', '[DEMO]%'));
    }

    public function receivables(): Builder
    {
        $paid = BookingPayment::whereNull('voided_at')->select('booking_id')->selectRaw("SUM(CASE WHEN kind = 'payment' THEN amount_cents ELSE -amount_cents END) AS paid_cents")->groupBy('booking_id');
        $addons = BookingAddon::select('booking_id')->selectRaw('SUM(price) AS addons_total')->groupBy('booking_id');
        $total = '(CASE WHEN COALESCE(bookings.service_price_snapshot, services.price, 0) + COALESCE(booking_report_addons.addons_total, 0) - COALESCE(bookings.promo_discount, 0) > 0 THEN COALESCE(bookings.service_price_snapshot, services.price, 0) + COALESCE(booking_report_addons.addons_total, 0) - COALESCE(bookings.promo_discount, 0) ELSE 0 END + COALESCE(bookings.travel_fee, 0))';
        $net = '(COALESCE(bookings.legacy_credit_cents, 0) + COALESCE(booking_report_paid.paid_cents, 0))';

        return $this->bookings()->where('finance_managed', true)->whereNotIn('bookings.status', ['cancelled', 'cancelled_by_user', 'no_show'])
            ->leftJoin('services', 'services.id', '=', 'bookings.service_id')->leftJoinSub($addons, 'booking_report_addons', fn ($join) => $join->on('booking_report_addons.booking_id', '=', 'bookings.id'))->leftJoinSub($paid, 'booking_report_paid', fn ($join) => $join->on('booking_report_paid.booking_id', '=', 'bookings.id'))
            ->select('bookings.*')->selectRaw('ROUND('.$total.' * 100) - '.$net.' AS balance_cents')->whereRaw('ROUND('.$total.' * 100) > '.$net);
    }

    public function query(string $type, Carbon $start, Carbon $end): Builder
    {
        return match ($type) {
            'customers' => $this->period(Customer::with('branch')->where('name', 'not like', '[DEMO]%'), 'created_at', $start, $end),
            'bookings' => $this->period($this->bookings()->with('customer', 'service', 'therapist'), 'booking_date', $start, $end),
            'revenue' => $this->period($this->bookings()->with('customer', 'service', 'addOns')->whereNotIn('status', ['cancelled', 'cancelled_by_user', 'no_show']), 'booking_date', $start, $end),
            'payments' => $this->period(BookingPayment::whereNull('voided_at')->whereHas('booking', fn ($q) => $q->where(fn ($q) => $q->whereNull('notes')->orWhere('notes', 'not like', '[DEMO]%'))->whereHas('customer', fn ($q) => $q->where('name', 'not like', '[DEMO]%')))->with('booking.customer'), 'paid_at', $start, $end),
            'balances' => $this->receivables()->with('customer', 'service'),
            'campaigns' => $this->period(Campaign::with('promo'), 'created_at', $start, $end),
            'followups' => $this->period(Followup::with('customer'), 'created_at', $start, $end),
            'ai' => $this->period(AiLog::with('customer'), 'created_at', $start, $end),
        };
    }

    public function row(string $type, $row): array
    {
        return match ($type) {
            'customers' => ['name' => $row->name, 'whatsapp' => $row->whatsapp_number, 'status' => $row->status, 'branch' => $row->branch?->name, 'created_at' => $row->created_at?->format('Y-m-d H:i')],
            'bookings' => ['code' => $row->booking_code, 'customer' => $row->customer?->name, 'service' => $row->service?->name, 'therapist' => $row->therapist?->name, 'date' => $row->booking_date->toDateString(), 'time' => substr($row->start_time, 0, 5), 'status' => $row->status],
            'revenue' => ['code' => $row->booking_code, 'customer' => $row->customer?->name, 'service' => $row->service?->name, 'date' => $row->booking_date->toDateString(), 'status' => $row->status, 'gross_revenue' => (float) ($row->service_price_snapshot ?? $row->service?->price ?? 0) + (float) $row->addOns->sum('price') + (float) $row->travel_fee, 'discount' => (float) $row->promo_discount, 'net_revenue' => $row->totalPrice()],
            'payments' => ['code' => $row->booking->booking_code, 'customer' => $row->booking->customer?->name, 'date' => $row->paid_at->format('Y-m-d H:i'), 'kind' => BookingPayment::KINDS[$row->kind], 'method' => BookingPayment::METHODS[$row->method], 'amount' => ($row->kind === 'refund' ? -$row->amount_cents : $row->amount_cents) / 100, 'reference' => $row->reference, 'receipt' => $row->receipt_number],
            'balances' => ['code' => $row->booking_code, 'customer' => $row->customer?->name, 'date' => $row->booking_date->toDateString(), 'status' => $row->status, 'balance' => $row->balance_cents / 100],
            'campaigns' => ['name' => $row->name, 'promo' => $row->promo?->title, 'status' => $row->status, 'recipients' => $row->recipient_count, 'sent' => $row->sent_count, 'failed' => $row->failed_count, 'scheduled_at' => $row->scheduled_at?->format('Y-m-d H:i')],
            'followups' => ['customer' => $row->customer?->name, 'title' => $row->title, 'priority' => $row->priority, 'status' => $row->status, 'due_at' => $row->due_at?->format('Y-m-d H:i'), 'completed_at' => $row->completed_at?->format('Y-m-d H:i')],
            'ai' => ['customer' => $row->customer?->name, 'status' => $row->status, 'confidence' => $row->confidence, 'fallback_reason' => $row->fallback_reason, 'created_at' => $row->created_at?->format('Y-m-d H:i')],
        };
    }

    public function summary(Carbon $start, Carbon $end, array $types): array
    {
        $result = [];
        foreach ($types as $type) {
            $query = $this->query($type, $start, $end);
            $result[$type] = match ($type) {
                'revenue' => $query->lazyById(200)->sum(fn ($b) => $b->totalPrice()),
                'payments' => ($query->lazyById(200)->sum(fn ($p) => $p->kind === 'payment' ? $p->amount_cents : -$p->amount_cents)) / 100,
                'balances' => $query->lazyById(200, 'bookings.id', 'id')->sum('balance_cents') / 100,
                default => $query->count(),
            };
        }

        return $result;
    }
}
