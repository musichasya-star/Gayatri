<?php

namespace App\Services\CRM;

use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Conversation;
use App\Models\Followup;
use App\Models\User;
use App\Support\ConversationStatus;
use Illuminate\Http\Request;

class InboxService
{
    public function __construct(private readonly AuditLogService $auditLogService) {}

    public function sendReply(Conversation $conversation, string $message, User $actor, Request $request): void
    {
        SendWhatsAppMessageJob::dispatch($conversation->id, $actor->id, 'admin', $message);

        $oldValues = $conversation->getOriginal();
        $conversation->update([
            'assigned_user_id' => $actor->id,
            'status' => ConversationStatus::HUMAN_HANDLED,
            'ai_enabled' => false,
        ]);

        $this->auditLogService->log(
            $actor,
            'conversation.reply',
            $conversation,
            $request,
            $oldValues,
            $conversation->fresh()->toArray(),
            'Mengirim balasan manual dari inbox.'
        );
    }

    public function takeover(Conversation $conversation, User $actor, Request $request): void
    {
        $oldValues = $conversation->getOriginal();

        if (! $conversation->ai_enabled && $conversation->assigned_user_id !== null) {
            $conversation->update([
                'assigned_user_id' => null,
                'status' => ConversationStatus::OPEN,
                'ai_enabled' => true,
            ]);
        } else {
            $conversation->update([
                'assigned_user_id' => $actor->id,
                'status' => ConversationStatus::HUMAN_HANDLED,
                'ai_enabled' => false,
            ]);
        }

        $this->auditLogService->log(
            $actor,
            'conversation.takeover',
            $conversation,
            $request,
            $oldValues,
            $conversation->fresh()->toArray(),
            $conversation->ai_enabled ? 'Melepas takeover dan mengaktifkan AI kembali.' : 'Mengambil alih percakapan dari AI.'
        );
    }

    public function close(Conversation $conversation, User $actor, Request $request): void
    {
        $oldValues = $conversation->getOriginal();
        $conversation->update([
            'status' => ConversationStatus::CLOSED,
            'closed_at' => now(),
            'unread_count' => 0,
        ]);

        $this->auditLogService->log(
            $actor,
            'conversation.closed',
            $conversation,
            $request,
            $oldValues,
            $conversation->fresh()->toArray(),
            'Menutup percakapan customer.'
        );
    }

    public function markFollowup(Conversation $conversation, User $actor, Request $request, ?string $note = null): Followup
    {
        $followup = Followup::create([
            'customer_id' => $conversation->customer_id,
            'conversation_id' => $conversation->id,
            'assigned_user_id' => $actor->id,
            'title' => 'Follow-up dari inbox',
            'notes' => $note ?: 'Follow-up dibuat dari halaman inbox.',
            'status' => 'open',
            'priority' => 'high',
            'due_at' => now()->addDay(),
        ]);

        $oldValues = $conversation->getOriginal();
        $conversation->update([
            'status' => ConversationStatus::NEED_FOLLOWUP,
            'assigned_user_id' => $actor->id,
        ]);

        $this->auditLogService->log(
            $actor,
            'conversation.followup',
            $conversation,
            $request,
            $oldValues,
            $conversation->fresh()->toArray(),
            'Menandai conversation sebagai follow-up.'
        );

        return $followup;
    }

    public function saveInternalNote(Conversation $conversation, User $actor, Request $request, string $note): void
    {
        $oldValues = $conversation->getOriginal();
        $conversation->update([
            'internal_note' => $note,
            'assigned_user_id' => $actor->id,
        ]);

        $this->auditLogService->log(
            $actor,
            'conversation.note',
            $conversation,
            $request,
            $oldValues,
            $conversation->fresh()->toArray(),
            'Memperbarui catatan internal conversation.'
        );
    }

    public function deleteHistory(Conversation $conversation, User $actor, Request $request): int
    {
        $oldValues = $conversation->getOriginal();
        $deletedCount = $conversation->messages()->count();

        $conversation->messages()->delete();
        $conversation->update([
            'unread_count' => 0,
            'last_message_at' => null,
            'last_incoming_at' => null,
        ]);

        $this->auditLogService->log(
            $actor,
            'conversation.history_deleted',
            $conversation,
            $request,
            $oldValues,
            $conversation->fresh()->toArray(),
            'Menghapus '.$deletedCount.' pesan dari history chat inbox.'
        );

        return $deletedCount;
    }
}
