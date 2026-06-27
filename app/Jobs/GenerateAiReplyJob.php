<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\AI\AiService;
use App\Services\AppSettingService;
use App\Support\ConversationStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateAiReplyJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $conversationId, private readonly int $messageId) {}

    public function handle(AiService $aiService, AppSettingService $settings): void
    {
        $settings->applyToConfig();

        if ((string) config('crm.default_ai_mode', 'auto_reply') !== 'auto_reply') {
            return;
        }

        $conversation = Conversation::with(['customer', 'whatsappSession'])->find($this->conversationId);
        $message = Message::find($this->messageId);

        if (! $conversation || ! $message || $message->direction !== 'incoming') {
            return;
        }

        if (! $conversation->ai_enabled || in_array($conversation->status, [ConversationStatus::HUMAN_HANDLED, ConversationStatus::CLOSED], true)) {
            return;
        }

        $result = $aiService->generateReply($conversation, $message);

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
}
