<?php

namespace App\Support;

final class UserRole
{
    public const OWNER = 'owner';

    public const MANAGER = 'manager';

    public const ADMIN = 'admin';

    public const SALES = 'sales';

    public const THERAPIST = 'therapist';

    public static function all(): array
    {
        return [
            self::OWNER,
            self::MANAGER,
            self::ADMIN,
            self::SALES,
            self::THERAPIST,
        ];
    }
}
