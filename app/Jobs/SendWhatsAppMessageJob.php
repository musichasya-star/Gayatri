<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\WhatsApp\WahaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendWhatsAppMessageJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $conversationId,
        private readonly ?int $userId,
        private readonly string $senderType,
        private readonly string $text,
        private readonly ?string $sessionName = null,
        private readonly ?int $retryMessageId = null,
    ) {}

    public function handle(WahaService $wahaService): void
    {
        $conversation = Conversation::with('customer')->find($this->conversationId);

        if (! $conversation || ! $conversation->customer?->whatsapp_number) {
            return;
        }

        $session = $this->sessionName ?: ($conversation->whatsappSession?->session_name ?? config('waha.default_session'));
        $chatId = $conversation->wa_chat_id ?: $conversation->customer->whatsapp_number.'@c.us';
        try {
            $this->showTyping($wahaService, $session, $chatId);
            $result = $wahaService->sendText($session, $chatId, $this->text);
            $this->hideTyping($wahaService, $session, $chatId);
        } catch (Throwable $exception) {
            $this->hideTyping($wahaService, $session, $chatId);
            $this->markFailed($conversation, $exception);

            return;
        }

        $data = [
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'user_id' => $this->userId,
            'wa_message_id' => $this->resolveWaMessageId($result),
            'direction' => 'outgoing',
            'sender_type' => $this->senderType,
            'message_type' => 'text',
            'content' => $this->text,
            'payload' => $result,
            'sent_at' => now(),
            'failed_at' => null,
            'failed_reason' => null,
        ];

        if ($this->retryMessageId) {
            Message::whereKey($this->retryMessageId)->update($data + ['retry_count' => Message::find($this->retryMessageId)?->retry_count ?? 0]);
        } else {
            Message::create($data);
        }

        $conversation->update([
            'last_message_at' => now(),
        ]);
    }

    private function markFailed(Conversation $conversation, Throwable $exception): void
    {
        $data = [
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'user_id' => $this->userId,
            'direction' => 'outgoing',
            'sender_type' => $this->senderType,
            'message_type' => 'text',
            'content' => $this->text,
            'failed_at' => now(),
            'failed_reason' => $exception->getMessage(),
        ];

        if ($this->retryMessageId) {
            $message = Message::find($this->retryMessageId);
            $message?->update($data + ['retry_count' => ($message->retry_count ?? 0) + 1]);

            return;
        }

        Message::create($data + ['retry_count' => 0]);
    }

    private function showTyping(WahaService $wahaService, string $session, string $chatId): void
    {
        if (! (bool) config('waha.typing_enabled', true)) {
            return;
        }

        try {
            $wahaService->startTyping($session, $chatId);
            usleep(max(0, (int) config('waha.typing_delay_ms', 1200)) * 1000);
        } catch (Throwable) {
            // Typing indicator is cosmetic; never block outgoing messages.
        }
    }

    private function hideTyping(WahaService $wahaService, string $session, string $chatId): void
    {
        if (! (bool) config('waha.typing_enabled', true)) {
            return;
        }

        try {
            $wahaService->stopTyping($session, $chatId);
        } catch (Throwable) {
            // Best-effort cleanup only.
        }
    }

    private function resolveWaMessageId(array $result): ?string
    {
        $id = data_get($result, 'id._serialized')
            ?: data_get($result, '_data.id._serialized')
            ?: data_get($result, 'id');

        return is_scalar($id) ? (string) $id : null;
    }
}
