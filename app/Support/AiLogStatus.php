<?php

namespace App\Support;

final class AiLogStatus
{
    public const PENDING = 'pending';

    public const SUCCESS = 'success';

    public const ESCALATED = 'escalated';

    public const FALLBACK = 'fallback';

    public const BLOCKED = 'blocked';

    public const FAILED = 'failed';

    public static function all(): array
    {
        return [
            self::PENDING,
            self::SUCCESS,
            self::ESCALATED,
            self::FALLBACK,
            self::BLOCKED,
            self::FAILED,
        ];
    }
}
