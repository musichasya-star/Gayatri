<?php

namespace App\Services\AI;

use App\Models\KnowledgeBase;
use Illuminate\Support\Facades\DB;

class AiReplyEvidence
{
    public function knowledgeHash(KnowledgeBase $knowledge): string
    {
        return hash('sha256', json_encode([
            $knowledge->title, $knowledge->content, $knowledge->status, $knowledge->publication_status, $knowledge->audience, $knowledge->is_demo,
            $knowledge->valid_from?->timestamp, $knowledge->valid_until?->timestamp, $knowledge->revision,
            $knowledge->domains()->orderBy('domain')->pluck('domain')->all(),
        ], JSON_THROW_ON_ERROR));
    }

    public function problem(array $evidence, int $conversationId): ?string
    {
        if (isset($evidence['preferences']) && ! hash_equals(app(AiConversationPreferences::class)->fingerprint(), $evidence['preferences'])) {
            return 'reply_preferences_changed';
        }
        if (isset($evidence['lookup']) && ($problem = app(AiTransactionLookup::class)->evidenceProblem($evidence['lookup'], $conversationId))) {
            return $problem;
        }
        $directory = app(AiServiceDirectory::class);
        if (isset($evidence['domain'], $evidence['directory'])
            && ! hash_equals($directory->fingerprint($evidence['domain']), $evidence['directory'])) {
            return 'catalog_changed';
        }
        foreach ($evidence['knowledge'] ?? [] as $id => $hash) {
            $knowledge = KnowledgeBase::find($id);
            if (! $knowledge || $knowledge->is_demo || $knowledge->status !== 'active' || $knowledge->publication_status !== 'published'
                || $knowledge->audience !== 'customer_public' || $knowledge->valid_from?->isFuture() || $knowledge->valid_until?->isPast()
                || ! hash_equals($this->knowledgeHash($knowledge), $hash)) {
                return 'knowledge_changed';
            }
        }
        if (isset($evidence['context_revision'])) {
            $revision = DB::table('ai_conversation_contexts')->where('conversation_id', $conversationId)->value('revision');
            if ((int) $revision !== $evidence['context_revision']) {
                return 'stale_context';
            }
        }

        return null;
    }
}
