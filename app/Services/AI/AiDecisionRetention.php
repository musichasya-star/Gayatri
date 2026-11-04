<?php

namespace App\Services\AI;

use App\Models\AiLog;

class AiDecisionRetention
{
    public function query()
    {
        return AiLog::where('created_at', '<', now()->subDays(90))->where(function ($q) {
            $q->where(fn ($p) => $p->whereNull('outgoing_message_id')->whereIn('execution_state', ['simulation', 'held']))
                ->orWhereHas('outgoingMessage', fn ($m) => $m->whereIn('delivery_status', ['sent', 'failed', 'skipped']));
        });
    }

    public function prune(): int
    {
        return $this->query()->delete();
    }
}
