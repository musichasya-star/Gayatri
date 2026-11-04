<?php

namespace App\Support;

final class ReminderStatus
{
    public const PENDING = 'pending';

    public const SCHEDULED = 'scheduled';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const SENDING = 'sending';

    public const UNKNOWN = 'unknown';

    public static function all(): array
    {
        return [
            self::PENDING,
            self::SCHEDULED,
            self::SENT,
            self::FAILED,
            self::CANCELLED,
            self::SENDING,
            self::UNKNOWN,
        ];
    }
}
