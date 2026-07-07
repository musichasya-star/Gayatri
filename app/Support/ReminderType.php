<?php

namespace App\Support;

final class ReminderType
{
    public const H1 = 'h1';

    public const H0 = 'h0';

    public const PAYMENT = 'payment';

    public const RESCHEDULE = 'reschedule';

    public const FOLLOWUP = 'followup';

    public const PACKAGE_LOW = 'package_low';

    public static function all(): array
    {
        return [
            self::H1,
            self::H0,
            self::PAYMENT,
            self::RESCHEDULE,
            self::FOLLOWUP,
            self::PACKAGE_LOW,
        ];
    }

    public static function labels(): array
    {
        return [
            self::H1 => 'H-1 Treatment',
            self::H0 => 'H-0 Treatment',
            self::PAYMENT => 'Reminder Pembayaran',
            self::RESCHEDULE => 'Reminder Reschedule',
            self::FOLLOWUP => 'Reminder Treatment Lanjutan',
            self::PACKAGE_LOW => 'Paket Hampir Habis',
        ];
    }

    public static function label(string $type): string
    {
        return self::labels()[$type] ?? strtoupper($type);
    }
}
