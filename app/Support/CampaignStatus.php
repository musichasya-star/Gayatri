<?php

namespace App\Support;

final class CampaignStatus
{
    public const DRAFT = 'draft';

    public const APPROVED = 'approved';

    public const SCHEDULED = 'scheduled';

    public const RUNNING = 'running';

    public const PAUSED = 'paused';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public static function all(): array
    {
        return [
            self::DRAFT,
            self::APPROVED,
            self::SCHEDULED,
            self::RUNNING,
            self::PAUSED,
            self::COMPLETED,
            self::CANCELLED,
        ];
    }
}
