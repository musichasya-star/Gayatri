<?php

namespace App\Support;

final class UserStatus
{
    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    public static function all(): array
    {
        return [self::ACTIVE, self::INACTIVE];
    }
}
