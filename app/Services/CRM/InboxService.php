<?php

namespace App\Services\CRM;

use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Conversation;
use App\Models\Followup;
use App\Models\User;
use App\Services\AI\AiInteractionPolicy;
use App\Services\WhatsApp\OutboundMessages;
use App\Support\ConversationStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InboxService
{
    public function __construct(private readonly AuditLogService $auditLogService) {}

    public function sendReply(Conversation $conversation, string $message, User $actor, Request $request): void
    {
        DB::transaction(function () use ($conversation, $message, $actor, $request) {
            $current = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            InboxAccess::authorize($current, $actor);
            $old = $current->only(['ai_enabled', 'status', 'assigned_user_id', 'ai_control_revision']);
            $current->update(['assigned_user_id' => $actor->id, 'status' => ConversationStatus::HUMAN_HANDLED, 'ai_enabled' => false,
                'ai_needs_attention' => false, 'ai_handoff_reason' => null]);
            $this->clearAiContext($current);
            $this->auditLogService->log($actor, 'conversation.reply', $current, $request, $old, $current->only(array_keys($old)), 'Balasan admin masuk antrean; isi pesan tidak disalin ke audit.');
            SendWhatsAppMessageJob::dispatch($current->id, $actor->id, 'admin', $message)->afterCommit();
        });
    }

    public function takeover(Conversation $conversation, User $actor, Request $request): void
    {
        if (! app(AiInteractionPolicy::class)->allowsLegacyAutomation()) {
            $this->setAiControl($conversation, $actor, $request, false);

            return;
        }
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

    public function resume(Conversation $conversation, User $actor, Request $request): void
    {
        abort_unless($actor->hasRole(['owner', 'manager', 'admin', 'sales']), 403);
        $this->setAiControl($conversation, $actor, $request, true);
    }

    private function setAiControl(Conversation $conversation, User $actor, Request $request, bool $resume): void
    {
        $data = $request->validate(['control_revision' => 'required|integer|min:0']);
        DB::transaction(function () use ($conversation, $actor, $request, $data, $resume) {
            $current = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            InboxAccess::authorize($current, $actor);
            if ((int) $current->ai_control_revision !== (int) $data['control_revision']) {
                throw ValidationException::withMessages(['control_revision' => 'Kendali percakapan berubah. Muat ulang Inbox sebelum melanjutkan.']);
            }
            if ($resume && (! in_array($current->customer?->status, ['lead', 'active'], true) || ! app(OutboundMessages::class)->chat($current))) {
                throw ValidationException::withMessages(['control_revision' => 'AI belum dapat dilanjutkan: periksa status pelanggan dan tujuan WhatsApp.']);
            }
            if ($resume && ! app(AiInteractionPolicy::class)->snapshot()['enabled']) {
                throw ValidationException::withMessages(['control_revision' => 'Balasan AI global sedang nonaktif. Minta owner/manager memeriksa pengaturan terlebih dahulu.']);
            }
            $old = $current->only(['ai_enabled', 'status', 'assigned_user_id', 'ai_control_revision']);
            $changes = ['ai_enabled' => $resume, 'status' => $resume ? ConversationStatus::OPEN : ConversationStatus::HUMAN_HANDLED,
                'assigned_user_id' => $resume ? null : $actor->id, 'ai_needs_attention' => false, 'ai_handoff_reason' => null, 'closed_at' => null];
            if ($resume) {
                $changes['ai_reply_after_message_id'] = (int) $current->messages()->where('direction', 'incoming')->max('id');
                $changes['ai_resumed_at'] = now();
                $changes['closed_at'] = null;
            }
            $current->update($changes);
            $this->clearAiContext($current);
            $this->auditLogService->log($actor, $resume ? 'conversation.ai.resume' : 'conversation.takeover', $current, $request, $old,
                $current->only(array_merge(array_keys($old), ['ai_reply_after_message_id'])), $resume ? 'AI dilanjutkan untuk pesan baru. Backlog tidak diputar ulang.' : 'Percakapan diambil alih admin.');
        });
    }

    private function clearAiContext(Conversation $conversation): void
    {
        DB::table('ai_conversation_contexts')->where('conversation_id', $conversation->id)->update(['domain' => null, 'menu' => null,
            'lookup_payload' => null, 'lookup_expires_at' => null, 'clarification_count' => 0, 'expires_at' => now(),
            'control_revision' => (int) $conversation->ai_control_revision, 'revision' => DB::raw('revision + 1'), 'updated_at' => now()]);
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
        abort_unless($actor->status === 'active' && $actor->hasRole(['owner', 'manager']), 403);
        $request->validate(['confirmed' => ['required', 'accepted'], 'reason' => ['required', 'string', 'min:5', 'max:500']]);
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
            'Menghapus '.$deletedCount.' pesan dari history chat inbox. Alasan: '.$request->input('reason')
        );

        return $deletedCount;
    }
}
