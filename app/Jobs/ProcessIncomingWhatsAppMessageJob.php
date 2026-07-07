<?php

namespace App\Jobs;

use App\Models\CampaignRecipient;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\WhatsAppSession;
use App\Services\CRM\FeedbackService;
use App\Support\ConversationStatus;
use App\Support\CustomerStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessIncomingWhatsAppMessageJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly array $event) {}

    public function handle(?FeedbackService $feedbackService = null): void
    {
        $feedbackService ??= app(FeedbackService::class);

        $sessionName = (string) data_get($this->event, 'session', config('waha.default_session'));
        $payload = (array) data_get($this->event, 'payload', []);
        $chatId = (string) ($payload['from'] ?? $payload['chatId'] ?? '');

        if ($chatId === '' || ! $this->isDirectCustomerChat($chatId)) {
            return;
        }

        $whatsappNumber = $this->normalizeChatId($chatId);
        $messageId = (string) ($payload['id'] ?? '');

        $session = WhatsAppSession::firstOrCreate(
            ['session_name' => $sessionName],
            ['status' => 'unknown']
        );

        $conversation = Conversation::with('customer')->where('wa_chat_id', $chatId)->first();

        if ($conversation && $conversation->customer) {
            $customer = $conversation->customer;

            if ($conversation->whatsapp_session_id !== $session->id) {
                $conversation->update(['whatsapp_session_id' => $session->id]);
            }
        } else {
            $customer = Customer::firstOrCreate(
                ['whatsapp_number' => $whatsappNumber],
                [
                    'name' => 'Customer '.$whatsappNumber,
                    'phone' => $whatsappNumber,
                    'status' => CustomerStatus::LEAD,
                    'last_interaction_at' => now(),
                ]
            );

            $conversation = Conversation::firstOrCreate(
                ['wa_chat_id' => $chatId],
                [
                    'customer_id' => $customer->id,
                    'whatsapp_session_id' => $session->id,
                    'status' => ConversationStatus::OPEN,
                    'channel' => 'whatsapp',
                    'ai_enabled' => true,
                ]
            );

            if ($conversation->customer_id !== $customer->id || $conversation->whatsapp_session_id !== $session->id) {
                $conversation->update([
                    'customer_id' => $customer->id,
                    'whatsapp_session_id' => $session->id,
                ]);
            }
        }

        if ($messageId !== '' && Message::where('wa_message_id', $messageId)->exists()) {
            return;
        }

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'wa_message_id' => $messageId !== '' ? $messageId : null,
            'direction' => 'incoming',
            'sender_type' => 'customer',
            'message_type' => $this->resolveMessageType($payload),
            'content' => $this->resolveContent($payload),
            'payload' => $payload,
            'sent_at' => $this->resolveTimestamp($payload['timestamp'] ?? null),
        ]);

        $nextStatus = $conversation->status === ConversationStatus::HUMAN_HANDLED
            ? $conversation->status
            : ConversationStatus::OPEN;

        $conversation->update([
            'status' => $nextStatus,
            'last_message_at' => $this->resolveTimestamp($payload['timestamp'] ?? null) ?? now(),
            'last_incoming_at' => $this->resolveTimestamp($payload['timestamp'] ?? null) ?? now(),
            'unread_count' => $conversation->unread_count + 1,
        ]);

        $customer->update([
            'last_interaction_at' => now(),
        ]);

        $campaignRecipient = CampaignRecipient::query()
            ->where('customer_id', $customer->id)
            ->whereNotNull('sent_at')
            ->whereNull('replied_at')
            ->latest('sent_at')
            ->first();

        $campaignRecipient?->update(['replied_at' => now()]);

        if ($message->message_type === 'text' && trim((string) $message->content) !== '') {
            $feedbackService->captureIncoming($message);
            GenerateAiReplyJob::dispatch($conversation->id, $message->id);
            ExtractDataFromIncomingMessageJob::dispatch($message->id);
        }
    }

    private function normalizeChatId(string $chatId): string
    {
        return str_replace(['@c.us', '@s.whatsapp.net', '@lid'], '', $chatId);
    }

    private function isDirectCustomerChat(string $chatId): bool
    {
        return str_ends_with($chatId, '@c.us') || str_ends_with($chatId, '@s.whatsapp.net') || str_ends_with($chatId, '@lid');
    }

    private function resolveMessageType(array $payload): string
    {
        if (($payload['hasMedia'] ?? false) === true) {
            return 'media';
        }

        return 'text';
    }

    private function resolveContent(array $payload): string
    {
        $buttonId = (string) (data_get($payload, 'selectedButtonId')
            ?: data_get($payload, 'selectedButton.id')
            ?: data_get($payload, 'buttonReply.id')
            ?: data_get($payload, 'button.id')
            ?: data_get($payload, '_data.selectedButtonId'));

        return match ($buttonId) {
            'booking_confirm_yes' => 'Iya lanjutkan',
            'booking_confirm_no' => 'Tidak',
            default => (string) ($payload['body']
                ?? data_get($payload, 'selectedButton.text')
                ?? data_get($payload, 'buttonReply.title')
                ?? ''),
        };
    }

    private function resolveTimestamp(mixed $timestamp): ?string
    {
        if (! is_numeric($timestamp)) {
            return null;
        }

        $value = (int) $timestamp;

        if ($value > 9999999999) {
            $value = (int) floor($value / 1000);
        }

        return date('Y-m-d H:i:s', $value);
    }
}
