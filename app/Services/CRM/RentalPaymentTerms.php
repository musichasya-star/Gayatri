<?php

namespace App\Services\CRM;

use App\Models\RentalOrder;

class RentalPaymentTerms
{
    public function snapshot(array $settings, int $charges): array
    {
        $type = $settings['dp_type'] ?? 'none';
        $value = (int) ($settings['dp_value'] ?? 0);
        $minimum = match ($type) {
            'percent' => (int) ceil($charges * $value / 100),
            'fixed' => min($value, $charges),
            default => 0,
        };

        return ['dp_type' => $type, 'dp_value' => $value, 'minimum_dp' => $minimum, 'dp_due_hours' => (int) ($settings['dp_due_hours'] ?? 24),
            'settlement_days_before' => (int) ($settings['settlement_days_before'] ?? 0), 'require_full_payment' => (bool) ($settings['require_full_payment'] ?? false),
            'payment_instructions' => (string) ($settings['payment_instructions'] ?? '')];
    }

    public function summary(RentalOrder $order): array
    {
        $order->loadMissing('items', 'payments');
        $minimum = min((int) ($order->payment_terms['minimum_dp'] ?? 0), (int) $order->charges());

        return ['minimum_dp' => $minimum, 'dp_remaining' => max(0, $minimum - (int) $order->received()), 'balance' => (int) $order->balance(),
            'dp_overdue' => $minimum > $order->received() && $order->dp_due_at?->isPast(),
            'balance_overdue' => $order->balance() > 0 && $order->balance_due_at?->isPast(),
            'payable' => in_array($order->status, ['reserved', 'rented', 'inspection', 'completed'], true) && $order->balance() > 0];
    }
}
