<?php

namespace App\Support;

final class ReminderStatus
{
    public const PENDING = 'pending';

    public const SCHEDULED = 'scheduled';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public static function all(): array
    {
        return [
            self::PENDING,
            self::SCHEDULED,
            self::SENT,
            self::FAILED,
            self::CANCELLED,
        ];
    }
}
