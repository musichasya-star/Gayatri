<?php

namespace App\Support;

final class BookingStatus
{
    public const DRAFT = 'draft';

    public const PENDING = 'pending';

    public const PENDING_CONFIRMATION = 'pending_confirmation';

    public const CONFIRMED = 'confirmed';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const CANCELLED_BY_USER = 'cancelled_by_user';

    public const RESCHEDULED = 'rescheduled';

    public const NO_SHOW = 'no_show';

    public static function all(): array
    {
        return [
            self::DRAFT,
            self::PENDING,
            self::PENDING_CONFIRMATION,
            self::CONFIRMED,
            self::COMPLETED,
            self::CANCELLED,
            self::CANCELLED_BY_USER,
            self::RESCHEDULED,
            self::NO_SHOW,
        ];
    }
}
