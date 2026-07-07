<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Reminder;
use App\Services\CRM\ReminderService;
use App\Services\WhatsApp\WahaService;
use App\Support\ReminderStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendBookingReminderJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $reminderId) {}

    public function handle(WahaService $wahaService, ?ReminderService $reminderService = null): void
    {
        $reminder = Reminder::with(['booking.service', 'customer', 'conversation.whatsappSession'])->find($this->reminderId);

        if (! $reminder || $reminder->status === ReminderStatus::CANCELLED) {
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
            $session = $conversation->whatsappSession?->session_name ?? config('waha.default_session');
            $message = ($reminderService ?? app(ReminderService::class))->buildMessage($reminder);
            $result = $wahaService->sendText($session, $conversation->wa_chat_id, $message);

            Message::create([
                'conversation_id' => $conversation->id,
                'customer_id' => $reminder->customer_id,
                'wa_message_id' => data_get($result, 'id'),
                'direction' => 'outgoing',
                'sender_type' => 'system',
                'message_type' => 'text',
                'content' => $message,
                'payload' => $result,
                'sent_at' => now(),
            ]);

            $reminder->update([
                'status' => ReminderStatus::SENT,
                'sent_at' => now(),
                'failed_reason' => null,
            ]);
        } catch (Throwable $exception) {
            $reminder->update([
                'status' => ReminderStatus::FAILED,
                'failed_reason' => $exception->getMessage(),
            ]);
        }
    }
}
