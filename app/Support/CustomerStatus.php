<?php

namespace App\Support;

final class CustomerStatus
{
    public const LEAD = 'lead';

    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    public const BLACKLISTED = 'blacklisted';

    public static function all(): array
    {
        return [
            self::LEAD,
            self::ACTIVE,
            self::INACTIVE,
            self::BLACKLISTED,
        ];
    }
}
