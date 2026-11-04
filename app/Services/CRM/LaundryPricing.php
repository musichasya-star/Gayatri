<?php

namespace App\Services\CRM;

use Illuminate\Validation\ValidationException;

class LaundryPricing
{
    public static function milli(string|int|float $quantity): int
    {
        if (! preg_match('/^(\d{1,4})(?:\.(\d{1,3}))?$/D', (string) $quantity, $matches)) {
            throw ValidationException::withMessages(['quantity' => 'Jumlah/berat harus positif dan maksimal tiga angka desimal.']);
        }

        return (int) $matches[1] * 1000 + (int) str_pad($matches[2] ?? '', 3, '0');
    }

    public static function calculate(array $tariff, ?int $milli): array
    {
        if (($tariff['requires_confirmation'] ?? false) || empty($tariff['minimum_milli']) || empty($tariff['increment_milli'])) {
            throw ValidationException::withMessages(['items' => 'Tarif ini memerlukan konfirmasi admin untuk minimum, pembulatan, dan estimasi pengerjaan sebelum pesanan dihitung.']);
        }
        if ($milli === null) {
            return ['quantity_milli' => null, 'billable_milli' => null, 'amount' => null];
        }
        if ($milli <= 0 || $milli > 1000000 || ($tariff['unit'] === 'piece' && $milli % 1000 !== 0)) {
            throw ValidationException::withMessages(['quantity' => 'Berat maksimal 1.000 kg; jumlah satuan maksimal 1.000 potong dan wajib bilangan bulat.']);
        }
        $step = (int) $tariff['increment_milli'];
        $basis = max($milli, (int) $tariff['minimum_milli']);
        $billable = intdiv($basis + $step - 1, $step) * $step;

        return ['quantity_milli' => $milli, 'billable_milli' => $billable, 'amount' => intdiv($billable * (int) $tariff['price'] + 500, 1000)];
    }
}
