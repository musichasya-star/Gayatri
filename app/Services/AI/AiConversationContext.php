<?php

namespace App\Services\AI;

use App\Models\Conversation;
use App\Models\Message;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class AiConversationContext
{
    public function read(?Conversation $conversation): array
    {
        if (! $conversation) {
            return [];
        }
        $row = DB::table('ai_conversation_contexts')->where('conversation_id', $conversation->id)->first();
        if (! $row) {
            return ['revision' => 0];
        }
        if (! $row->expires_at || Carbon::parse($row->expires_at)->isPast() || (int) $row->control_revision !== (int) $conversation->ai_control_revision) {
            return ['revision' => (int) $row->revision];
        }

        $state = ['domain' => $row->domain, 'menu' => $row->menu, 'revision' => (int) $row->revision, 'clarification_count' => (int) $row->clarification_count,
            'catalog_item_id' => $row->catalog_item_id ? (int) $row->catalog_item_id : null];
        if ($row->lookup_payload && $row->lookup_expires_at && Carbon::parse($row->lookup_expires_at)->isFuture()) {
            try {
                $state['lookup'] = json_decode(Crypt::decryptString($row->lookup_payload), true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) { /* An unreadable state cannot authorize a lookup. */
            }
        }

        return $state;
    }

    /** Called inside the conversation/source-message transaction, never from previews. */
    public function commit(Conversation $conversation, Message $message, array $next): ?int
    {
        DB::table('ai_conversation_contexts')->insertOrIgnore([
            'conversation_id' => $conversation->id, 'revision' => 0, 'control_revision' => (int) $conversation->ai_control_revision,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = DB::table('ai_conversation_contexts')->where('conversation_id', $conversation->id)->lockForUpdate()->first();
        if (! $row || (int) $row->revision !== ($next['expected_revision'] ?? 0)
            || ($row->last_message_id && $row->last_message_id >= $message->id)) {
            return null;
        }
        DB::table('ai_conversation_contexts')->where('id', $row->id)->update([
            'domain' => $next['domain'] ?? null, 'menu' => $next['menu'] ?? null,
            'catalog_item_id' => empty($next['domain']) ? null : ($next['catalog_item_id'] ?? null),
            'clarification_count' => min(4, max(0, (int) ($next['clarification_count'] ?? 0))),
            'revision' => $row->revision + 1, 'control_revision' => (int) $conversation->ai_control_revision,
            'last_message_id' => $message->id, 'expires_at' => now()->addMinutes(30), 'updated_at' => now(),
            'lookup_payload' => empty($next['lookup']) ? null : Crypt::encryptString(json_encode($next['lookup'], JSON_THROW_ON_ERROR)),
            'lookup_expires_at' => empty($next['lookup']) ? null : Carbon::createFromTimestamp($next['lookup']['expires_at'])->timezone(config('app.timezone')),
        ]);

        return (int) $row->revision + 1;
    }
}
