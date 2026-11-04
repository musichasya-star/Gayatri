<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Reminder;
use App\Services\AI\AiInteractionPolicy;
use App\Services\CRM\ReminderService;
use App\Services\WhatsApp\DeliveryFailure;
use App\Services\WhatsApp\GatewayConfig;
use App\Services\WhatsApp\WhatsAppGateway;
use App\Support\ReminderStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendBookingReminderJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $reminderId) {}

    public function handle(WhatsAppGateway $wahaService, ?ReminderService $reminderService = null): void
    {
        $reminder = Reminder::with(['booking.service', 'customer', 'conversation.whatsappSession'])->find($this->reminderId);

        if (! $reminder || $reminder->sent_at || $reminder->message_id || data_get($reminder->payload, 'gateway_message_id') || ! in_array($reminder->status, [ReminderStatus::PENDING, ReminderStatus::SCHEDULED], true)) {
            return;
        }
        if (data_get($reminder->payload, 'source') === 'ai_automation'
            && ! app(AiInteractionPolicy::class)->allowsLegacyAutomation()) {
            $reminder->update(['status' => ReminderStatus::CANCELLED, 'failed_reason' => 'transaction_action_blocked: reminder AI lama ditahan.']);

            return;
        }
        if (in_array($reminder->customer?->status, ['inactive', 'blacklisted', 'blocked'], true) || in_array($reminder->booking?->status, ['cancelled', 'cancelled_by_user', 'completed', 'no_show'], true)) {
            $reminder->update(['status' => ReminderStatus::CANCELLED, 'failed_reason' => 'Status pelanggan atau booking sudah tidak sesuai.']);

            return;
        }
        if (! Reminder::whereKey($reminder->id)->whereIn('status', [ReminderStatus::PENDING, ReminderStatus::SCHEDULED])->whereNull('sent_at')->whereNull('message_id')->update(['status' => ReminderStatus::SENDING])) {
            return;
        }

        $conversation = $reminder->conversation ?: Conversation::query()
            ->where('customer_id', $reminder->customer_id)
            ->latest('last_message_at')
            ->first();

        if (! $conversation || blank($conversation->wa_chat_id)) {
            $reminder->update(['status' => ReminderStatus::FAILED, 'failed_reason' => 'Conversation WhatsApp tidak ditemukan.']);

            return;
        }

        try {
            $session = $conversation->whatsappSession?->session_name ?? GatewayConfig::get('default_session');
            $message = ($reminderService ?? app(ReminderService::class))->buildMessage($reminder);
            $result = $wahaService->sendText($session, $conversation->wa_chat_id, $message);

            $waId = data_get($result, 'id._serialized') ?: data_get($result, 'id');
            if (! is_scalar($waId) || (string) $waId === '') {
                throw new \RuntimeException('Missing gateway message ID.');
            }
            $reminder->update(['status' => ReminderStatus::SENT, 'sent_at' => now(), 'failed_reason' => null, 'payload' => array_replace($reminder->payload ?? [], ['gateway_message_id' => (string) $waId])]);
            $record = Message::create([
                'conversation_id' => $conversation->id,
                'customer_id' => $reminder->customer_id,
                'wa_message_id' => (string) $waId,
                'direction' => 'outgoing',
                'sender_type' => 'system',
                'message_type' => 'text',
                'content' => $message,
                'payload' => $result + ['reminder_id' => $reminder->id],
                'sent_at' => now(),
            ]);

            $reminder->update([
                'message_id' => $record->id,
                'status' => ReminderStatus::SENT,
                'sent_at' => now(),
                'failed_reason' => null,
            ]);
        } catch (Throwable $exception) {
            if ($reminder->fresh()->sent_at) {
                return;
            }
            $failure = DeliveryFailure::fromException($exception);
            $reminder->update(['status' => $failure['status'], 'failed_reason' => $failure['reason']]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Reminder::whereKey($this->reminderId)->where('status', ReminderStatus::SENDING)->whereNull('sent_at')->update(['status' => ReminderStatus::UNKNOWN, 'failed_reason' => 'Worker terputus. Periksa WhatsApp sebelum membuat pengiriman baru.']);
    }
}
