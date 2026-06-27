<?php

namespace App\Support;

final class AvailabilitySlotStatus
{
    public const AVAILABLE = 'available';

    public const BLOCKED = 'blocked';

    public const FULL = 'full';

    public static function all(): array
    {
        return [self::AVAILABLE, self::BLOCKED, self::FULL];
    }
}
