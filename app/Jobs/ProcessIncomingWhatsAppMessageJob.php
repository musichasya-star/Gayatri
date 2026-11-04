<?php

namespace App\Jobs;

use App\Models\CampaignRecipient;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\WhatsAppSession;
use App\Services\AI\AiInteractionPolicy;
use App\Services\CRM\FeedbackService;
use App\Services\WhatsApp\GatewayConfig;
use App\Services\WhatsApp\WhatsAppSenderIdentity;
use App\Support\ConversationStatus;
use App\Support\CustomerPhone;
use App\Support\CustomerStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ProcessIncomingWhatsAppMessageJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly array $event) {}

    public function handle(?FeedbackService $feedbackService = null): void
    {
        $feedbackService ??= app(FeedbackService::class);

        $sessionName = (string) data_get($this->event, 'session', GatewayConfig::get('default_session'));
        $payload = (array) data_get($this->event, 'payload', []);
        unset($payload['_gayatri_sender']);
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
        $senderIdentity = app(WhatsAppSenderIdentity::class);
        $whatsappNumber = $senderIdentity->trustedPhone($this->event, $session) ?? $whatsappNumber;

        $conversation = Conversation::with('customer')->where('wa_chat_id', $chatId)->first();

        if ($conversation && $conversation->customer) {
            $customer = $conversation->customer;

            if ($conversation->whatsapp_session_id !== $session->id) {
                $conversation->update(['whatsapp_session_id' => $session->id]);
            }
        } else {
            $phone = $senderIdentity->phone($whatsappNumber);
            $matches = $phone ? CustomerPhone::matching($phone)->get() : collect();
            $ambiguous = $matches->count() > 1;
            $customer = $matches->count() === 1 ? $matches->first() : Customer::firstOrCreate(
                ['whatsapp_number' => $ambiguous ? 'unverified:'.$chatId : $whatsappNumber],
                [
                    'name' => 'Customer '.$whatsappNumber,
                    'phone' => $whatsappNumber,
                    'status' => $ambiguous ? CustomerStatus::INACTIVE : CustomerStatus::LEAD,
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

        $received = DB::transaction(function () use ($conversation, $session, $messageId, $payload, $senderIdentity) {
            $current = Conversation::with('customer')->lockForUpdate()->findOrFail($conversation->id);
            $attributes = [
                'conversation_id' => $current->id,
                'customer_id' => $current->customer_id,
                'wa_message_id' => $messageId !== '' ? $messageId : null,
                'direction' => 'incoming',
                'sender_type' => 'customer',
                'message_type' => $this->resolveMessageType($payload),
                'content' => $this->resolveContent($payload),
                'payload' => $payload,
                'sent_at' => $this->resolveTimestamp($payload['timestamp'] ?? null),
            ];
            $message = $messageId !== '' ? Message::firstOrCreate(['wa_message_id' => $messageId], $attributes) : Message::create($attributes);
            if (! $message->wasRecentlyCreated) {
                return null;
            }
            $senderIdentity->observe($this->event, $session, $message);
            $current->refresh()->load('customer');
            $nextStatus = in_array($current->status, [ConversationStatus::HUMAN_HANDLED, ConversationStatus::CLOSED, ConversationStatus::ESCALATED], true)
                ? $current->status : ConversationStatus::OPEN;
            $changes = [
                'status' => $nextStatus,
                'last_message_at' => $this->resolveTimestamp($payload['timestamp'] ?? null) ?? now(),
                'last_incoming_at' => $this->resolveTimestamp($payload['timestamp'] ?? null) ?? now(),
                'unread_count' => $current->unread_count + 1,
            ];
            $policy = app(AiInteractionPolicy::class);
            if ($message->message_type !== 'text' && ! $policy->allowsLegacyAutomation() && $policy->canHandle($current)) {
                $changes = array_replace($changes, ['status' => ConversationStatus::ESCALATED, 'ai_enabled' => false,
                    'ai_needs_attention' => true, 'ai_handoff_reason' => 'unsupported_media']);
            }
            $current->update($changes);

            return [$message, $current];
        }, 3);
        if (! $received) {
            return;
        }
        [$message, $conversation] = $received;
        $customer = $conversation->customer;

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
            if ($feedbackService->captureIncoming($message)) {
                $message->update(['ai_reply_dispatched_at' => now()]);

                return;
            }
            GenerateAiReplyJob::dispatch($conversation->id, $message->id);
            if (app(AiInteractionPolicy::class)->allowsLegacyAutomation()) {
                ExtractDataFromIncomingMessageJob::dispatch($message->id);
            }
        }
    }

    private function normalizeChatId(string $chatId): string
    {
        return str_ends_with($chatId, '@lid') ? $chatId : str_replace(['@c.us', '@s.whatsapp.net'], '', $chatId);
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
