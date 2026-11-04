<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\AI\AiInteractionPolicy;
use App\Services\AI\AiTraceService;
use App\Services\AppSettingService;
use App\Services\WhatsApp\DeliveryFailure;
use App\Services\WhatsApp\GatewayConfig;
use App\Services\WhatsApp\OutboundMessages;
use App\Services\WhatsApp\WhatsAppGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class SendWhatsAppMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    private string $outboundKey;

    public function __construct(
        private readonly int $conversationId,
        private readonly ?int $userId,
        private readonly string $senderType,
        private readonly string $text,
        private readonly ?string $sessionName = null,
        private readonly ?int $retryMessageId = null,
        private readonly ?string $attemptToken = null,
        private readonly ?array $aiContext = null,
    ) {
        $this->outboundKey = $senderType === 'ai' && isset($aiContext['decision_id'], $aiContext['source_message_id'])
            ? hash('sha256', 'ai:'.$conversationId.':'.$aiContext['source_message_id'].':'.$aiContext['decision_id']) : (string) Str::uuid();
    }

    public function handle(WhatsAppGateway $wahaService): void
    {
        app(AppSettingService::class)->applyToConfig();
        $outbox = app(OutboundMessages::class);
        $message = $outbox->claim($this->conversationId, $this->userId, $this->senderType, $this->text, $this->sessionName, $this->outboundKey ?? ($this->job?->uuid() ?: (string) Str::uuid()), $this->retryMessageId, $this->attemptToken ?? null, $this->aiContext ?? null);
        if (! $message) {
            return;
        }
        $session = $message->send_session;
        $chatId = $message->send_chat_id;
        try {
            $this->showTyping($wahaService, $session, $chatId);
            if ($message->sender_type === 'ai') {
                $conversation = Conversation::with('customer', 'whatsappSession')->find($message->conversation_id);
                $problem = $conversation ? app(AiInteractionPolicy::class)->sendProblem($conversation, $this->text, $message->ai_reply_context) : 'recipient_changed';
                if ($problem) {
                    $this->hideTyping($wahaService, $session, $chatId);
                    $message->update(['delivery_status' => 'skipped', 'failed_reason' => 'Balasan AI ditahan: '.$problem, 'failure_code' => $problem, 'delivery_revision' => $message->delivery_revision + 1]);

                    return;
                }
            }
            $result = $wahaService->sendText($session, $chatId, $this->text);
            $this->hideTyping($wahaService, $session, $chatId);
            $waId = $this->resolveWaMessageId($result);
            if (! $waId) {
                throw new \RuntimeException('Missing gateway message ID.');
            }
            // Persist gateway acceptance before secondary inbox bookkeeping.
            $message->update(['wa_message_id' => $waId, 'payload' => $result, 'sent_at' => now(), 'delivery_status' => 'sent', 'failed_at' => null, 'failed_reason' => null, 'failure_code' => null, 'delivery_revision' => $message->delivery_revision + 1]);
        } catch (Throwable $exception) {
            $this->hideTyping($wahaService, $session, $chatId);
            if (! $outbox->accepted($message->fresh())) {
                $failure = DeliveryFailure::fromException($exception);
                $message->update(['delivery_status' => $failure['status'], 'failed_at' => now(), 'failed_reason' => $failure['reason'], 'failure_code' => $failure['code'], 'delivery_revision' => $message->delivery_revision + 1]);
                app(AiTraceService::class)->attention(data_get($message->ai_reply_context, 'decision_id'), $failure['status'] === 'unknown' ? 'gateway_delivery_unknown' : 'gateway_delivery_failed');
            }

            return;
        }

        Conversation::whereKey($this->conversationId)->update([
            'last_message_at' => now(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $query = $this->retryMessageId ? Message::whereKey($this->retryMessageId)->where('dispatch_token', $this->attemptToken ?? null) : Message::where('outbound_key', $this->outboundKey ?? '');
        $changed = (clone $query)->whereNull('sent_at')->whereNull('wa_message_id')->whereIn('delivery_status', ['queued', 'sending'])->update(['delivery_status' => 'unknown', 'failed_at' => now(), 'failure_code' => 'worker_interrupted', 'failed_reason' => 'Worker terputus. Hasil pengiriman belum pasti; periksa WhatsApp sebelum mencoba ulang.']);
        if ($this->senderType === 'ai' && ($changed || (! $this->retryMessageId && ! (clone $query)->exists()))) {
            $decisionId = $this->aiContext['decision_id'] ?? ($this->retryMessageId ? data_get(Message::find($this->retryMessageId)?->ai_reply_context, 'decision_id') : null);
            app(AiTraceService::class)->outcome($decisionId, 'unknown', 'worker_interrupted');
            app(AiTraceService::class)->attention($decisionId, 'gateway_delivery_unknown');
        }
    }

    private function showTyping(WhatsAppGateway $wahaService, string $session, string $chatId): void
    {
        if (! (bool) GatewayConfig::get('typing_enabled', true)) {
            return;
        }

        try {
            $wahaService->startTyping($session, $chatId);
            usleep(max(0, (int) GatewayConfig::get('typing_delay_ms', 1200)) * 1000);
        } catch (Throwable) {
            // Typing indicator is cosmetic; never block outgoing messages.
        }
    }

    private function hideTyping(WhatsAppGateway $wahaService, string $session, string $chatId): void
    {
        if (! (bool) GatewayConfig::get('typing_enabled', true)) {
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
