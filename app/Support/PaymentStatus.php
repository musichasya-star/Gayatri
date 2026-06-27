<?php

namespace App\Support;

final class PaymentStatus
{
    public const UNPAID = 'unpaid';

    public const PARTIAL = 'partial';

    public const PAID = 'paid';

    public const REFUNDED = 'refunded';

    public static function all(): array
    {
        return [
            self::UNPAID,
            self::PARTIAL,
            self::PAID,
            self::REFUNDED,
        ];
    }
}
