<?php

namespace App\Services\CRM;

use App\Models\RentalOrder;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RentalPolicy
{
    public function snapshot(array $settings, array $rows): array
    {
        $policy = [
            'version' => 1, 'text' => $settings['rental_terms'],
            'products' => collect($rows)->map(fn ($row) => ['name' => $row['name'], 'terms' => $row['terms'] ?? ''])->values()->all(),
            'late_fee' => ['daily_rate' => (int) $settings['late_fee_daily'], 'grace_days' => (int) $settings['late_fee_grace_days'], 'cap' => (int) $settings['late_fee_cap']],
            'cancellation' => ['type' => $settings['cancellation_fee_type'], 'value' => (int) $settings['cancellation_fee_value'], 'free_days_before' => (int) $settings['cancellation_free_days']],
        ];
        $policy['hash'] = hash('sha256', json_encode($policy));

        return $policy;
    }

    public function accept(RentalOrder $order, string $hash, Request $request): void
    {
        DB::transaction(function () use ($order, $hash, $request) {
            $order = RentalOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! $order->terms_snapshot || ! hash_equals($order->terms_snapshot['hash'], $hash)) {
                throw new InvalidArgumentException('Ketentuan tidak sesuai pesanan. Muat ulang portal.');
            }
            if ($order->terms_accepted_at) {
                return;
            }
            if (! in_array($order->status, ['pending', 'reserved'], true) || $order->isExpired()) {
                throw new InvalidArgumentException('Persetujuan hanya untuk pesanan aktif sebelum serah-terima.');
            }
            $order->update(['terms_accepted_at' => now(), 'terms_acceptance_source' => 'portal']);
            app(AuditLogService::class)->log(null, 'rental.terms.accept', $order, $request, [], ['hash' => $hash, 'source' => 'portal'], 'Ketentuan pesanan disetujui melalui portal pribadi');
        });
    }

    public function cancellation(RentalOrder $order, ?CarbonInterface $at = null): array
    {
        $order->loadMissing('payments', 'items');
        $policy = $order->terms_accepted_at ? ($order->terms_snapshot['cancellation'] ?? ['type' => 'none', 'value' => 0, 'free_days_before' => 0]) : ['type' => 'none', 'value' => 0, 'free_days_before' => 0];
        $at ??= now();
        $daysBefore = (int) $at->copy()->startOfDay()->diffInDays($order->start_date, false);
        $free = $policy['free_days_before'] > 0 && $daysBefore >= $policy['free_days_before'];
        $base = (int) ((float) $order->rental_subtotal + (float) $order->delivery_fee + (float) $order->collection_fee);
        $fee = $free ? 0 : match ($policy['type']) {
            'fixed' => (int) $policy['value'],
            'percent' => (int) ceil($base * $policy['value'] / 100),
            default => 0,
        };
        $received = max(0, (int) $order->received());
        $fee = min($fee, $base, $received);

        return ['policy' => $policy, 'assessed_at' => $at->toIso8601String(), 'days_before' => $daysBefore, 'free_cancellation' => $free, 'service_base' => $base, 'received' => $received, 'fee' => $fee, 'refund' => max(0, $received - $fee)];
    }
}
