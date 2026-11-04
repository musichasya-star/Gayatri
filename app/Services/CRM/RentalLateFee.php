<?php

namespace App\Services\CRM;

use App\Models\RentalItem;
use App\Models\RentalOrder;
use InvalidArgumentException;

class RentalLateFee
{
    public function calculate(RentalOrder $order, RentalItem $item): array
    {
        $policy = $order->terms_snapshot['late_fee'] ?? null;
        $due = $item->rental_end_date ?? $order->end_date;
        $actual = ($item->returned_at ?? now())->copy()->timezone('Asia/Jakarta')->startOfDay();
        $days = max(0, (int) $due->copy()->startOfDay()->diffInDays($actual, false));
        $billable = max(0, $days - ($policy['grace_days'] ?? 0));
        $fee = $billable * ($policy['daily_rate'] ?? 0);
        if (($policy['cap'] ?? 0) > 0) {
            $fee = min($fee, $policy['cap']);
        }

        return ['policy' => $policy, 'due_date' => $due->toDateString(), 'actual_date' => $actual->toDateString(), 'actual_return' => (bool) $item->returned_at,
            'late_days' => $days, 'billable_days' => $billable, 'suggested_fee' => min(100000000, $fee), 'calculated_at' => now()->toIso8601String()];
    }

    public function finalize(RentalOrder $order, RentalItem $item, int $amount, ?string $reason): array
    {
        $calculation = $this->calculate($order, $item);
        $reason = trim($reason ?? '');
        if ($calculation['policy'] && $amount !== $calculation['suggested_fee'] && mb_strlen($reason) < 5) {
            throw new InvalidArgumentException('Isi alasan penyesuaian denda minimal 5 karakter jika nominal berbeda dari kalkulator.');
        }

        return array_merge($calculation, ['applied_fee' => $amount, 'override_reason' => $reason ?: null]);
    }
}
