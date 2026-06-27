<?php

namespace App\Support;

final class ConversationStatus
{
    public const OPEN = 'open';

    public const AI_HANDLED = 'ai_handled';

    public const HUMAN_HANDLED = 'human_handled';

    public const NEED_FOLLOWUP = 'need_followup';

    public const ESCALATED = 'escalated';

    public const CLOSED = 'closed';

    public static function all(): array
    {
        return [
            self::OPEN,
            self::AI_HANDLED,
            self::HUMAN_HANDLED,
            self::NEED_FOLLOWUP,
            self::ESCALATED,
            self::CLOSED,
        ];
    }
}
