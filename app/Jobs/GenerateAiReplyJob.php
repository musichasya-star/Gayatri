<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\AI\AiChatCustomization;
use App\Services\AI\AiConversationContext;
use App\Services\AI\AiInteractionPolicy;
use App\Services\AI\AiLegacyHold;
use App\Services\AI\AiService;
use App\Services\AI\AiTraceService;
use App\Services\AI\ConversationFlowService;
use App\Services\AppSettingService;
use App\Support\ConversationStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class GenerateAiReplyJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $conversationId, private readonly int $messageId) {}

    public function handle(AiService $aiService, AppSettingService $settings, ConversationFlowService $flowService, ?AiChatCustomization $customization = null): void
    {
        $policy = app(AiInteractionPolicy::class);
        $snapshot = $policy->snapshot();
        if (! $policy->allowsLegacyAutomation() && ! $snapshot['enabled']) {
            return;
        }
        $settings->applyToConfig();

        $customization ??= app(AiChatCustomization::class);
        $chatSettings = $customization->settings();
        if (! $chatSettings['enabled']) {
            return;
        }

        $conversation = Conversation::with(['customer', 'whatsappSession'])->find($this->conversationId);
        $message = Message::find($this->messageId);

        if (! $conversation || ! $message || $message->direction !== 'incoming'
            || $message->conversation_id !== $conversation->id || $message->customer_id !== $conversation->customer_id) {
            return;
        }

        if (! $policy->allowsLegacyAutomation()) {
            $this->handleInformation($conversation, $message, $aiService, $policy, $snapshot);

            return;
        }
        if (! $conversation->ai_enabled || in_array($conversation->status, [ConversationStatus::HUMAN_HANDLED, ConversationStatus::CLOSED], true)) {
            return;
        }

        $result = $flowService->handle($conversation, $message);
        if ($result) {
            $result['reply'] = $customization->format($result['reply'], $chatSettings, $conversation->customer?->name);
        } else {
            $result = $aiService->generateReply($conversation, $message, $chatSettings);
        }

        if (($result['fallback_reason'] ?? null) === 'missing_persona') {
            $conversation->update([
                'status' => ConversationStatus::ESCALATED,
                'ai_enabled' => false,
            ]);

            return;
        }

        if (! empty($result['reply'])) {
            SendWhatsAppMessageJob::dispatch(
                $conversation->id,
                null,
                'ai',
                $result['reply'],
                $conversation->whatsappSession?->session_name
            );
        }

        if ($result['status'] === 'success') {
            $conversation->update([
                'status' => ConversationStatus::AI_HANDLED,
                'unread_count' => 0,
            ]);

            return;
        }

        if (($result['fallback_reason'] ?? null) === 'no_knowledge') {
            $conversation->update([
                'status' => ConversationStatus::NEED_FOLLOWUP,
            ]);

            return;
        }

        $conversation->update([
            'status' => ConversationStatus::ESCALATED,
            'ai_enabled' => false,
        ]);
    }

    private function handleInformation(Conversation $conversation, Message $message, AiService $aiService, AiInteractionPolicy $policy, array $snapshot): void
    {
        if (! $policy->canHandle($conversation) || $message->message_type !== 'text' || $message->ai_reply_dispatched_at) {
            return;
        }
        DB::transaction(function () use ($conversation, $message, $aiService, $snapshot, $policy) {
            $current = Conversation::with('customer', 'whatsappSession')->lockForUpdate()->find($conversation->id);
            $source = Message::lockForUpdate()->find($message->id);
            if (! $current || ! $source || $source->ai_reply_dispatched_at || ! $policy->canHandle($current) || $policy->beforeResumeBoundary($current, $source)) {
                return;
            }
            $initialControlRevision = (int) $current->ai_control_revision;
            app(AiLegacyHold::class)->conversation($current->id);
            // The information assistant is local and deterministic. Serialize generation per source, with no network call under this lock.
            $result = $aiService->generateReply($current, $source);
            $current->refresh()->load('customer', 'whatsappSession');
            $source->refresh();
            $decisionId = $result['log_id'] ?? null;
            if (empty($result['reply'])) {
                app(AiTraceService::class)->outcome($decisionId, 'held', $result['fallback_reason'] ?? 'ai_disabled');

                return;
            }
            $context = $policy->replyContext($current, $source, $result['reply'], $snapshot, $result['status'] === 'escalated', $result['evidence'] ?? []);
            $context['control_revision'] = $initialControlRevision;
            $context['decision_id'] = $decisionId;
            if (! $policy->canHandle($current) || ($problem = $policy->sendProblem($current, $result['reply'], $context))) {
                app(AiTraceService::class)->outcome($decisionId, 'held', $problem ?? 'human_takeover');

                return;
            }
            $source->update(['ai_reply_dispatched_at' => now()]);
            if (isset($result['next_context'])) {
                $revision = app(AiConversationContext::class)->commit($current, $source, $result['next_context']);
                if ($revision === null) {
                    // Leave the source available for explicit review instead of dispatching stale output.
                    $source->update(['ai_reply_dispatched_at' => null]);
                    app(AiTraceService::class)->outcome($decisionId, 'held', 'stale_context');

                    return;
                }
                $context['evidence']['context_revision'] = $revision;
            }
            if ($result['status'] === 'escalated') {
                $current->update(['status' => ConversationStatus::ESCALATED, 'ai_enabled' => false,
                    'ai_needs_attention' => true, 'ai_handoff_reason' => $result['fallback_reason'] ?? $result['routing']['reason_code'] ?? 'requested_human',
                    'unread_count' => max(1, $current->unread_count)]);
                $context['control_revision'] = (int) $current->ai_control_revision;
            } else {
                $current->update(['status' => ConversationStatus::AI_HANDLED]);
            }
            app(AiTraceService::class)->queued($decisionId, $context);
            DB::afterCommit(function () use ($current, $result, $context, $decisionId) {
                try {
                    SendWhatsAppMessageJob::dispatch($current->id, null, 'ai', $result['reply'], $current->whatsappSession?->session_name, null, null, $context);
                } catch (\Throwable) {
                    app(AiTraceService::class)->outcome($decisionId, 'held', 'queue_unavailable');
                    app(AiTraceService::class)->attention($decisionId, 'queue_unavailable', true);
                }
            });
        }, 3);
    }
}
