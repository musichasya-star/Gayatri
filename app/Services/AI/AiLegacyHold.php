<?php

namespace App\Services\AI;

use App\Models\AiAutomationApproval;
use App\Models\ConversationFlow;
use App\Models\Reminder;
use Illuminate\Support\Facades\DB;

class AiLegacyHold
{
    public function conversation(int $conversationId): int
    {
        return $this->flows(ConversationFlow::where('conversation_id', $conversationId));
    }

    public function all(): array
    {
        return DB::transaction(function () {
            return [
                'flows_held' => $this->flows(ConversationFlow::query()),
                'approvals_held' => AiAutomationApproval::where('status', 'pending')->update([
                    'status' => 'held', 'rejection_reason' => 'legacy_approval_blocked: migration_to_information_web',
                    'updated_at' => now(),
                ]),
                'ai_reminders_held' => Reminder::where('payload->source', 'ai_automation')
                    ->whereIn('status', ['pending', 'scheduled'])->whereNull('sent_at')->whereNull('message_id')
                    ->update(['status' => 'cancelled', 'failed_reason' => 'transaction_action_blocked: reminder AI lama ditahan.', 'updated_at' => now()]),
            ];
        });
    }

    private function flows($query): int
    {
        $count = 0;
        $query->where('status', 'active')->chunkById(100, function ($flows) use (&$count) {
            foreach ($flows as $flow) {
                $count += ConversationFlow::whereKey($flow->id)->where('status', 'active')->update([
                    'status' => 'held', 'completed_at' => now(),
                    'payload' => json_encode(array_replace($flow->payload ?? [], ['policy_hold' => 'migration_to_information_web']), JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
            }
        });

        return $count;
    }
}
