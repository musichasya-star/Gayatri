<?php

namespace App\Jobs;

use App\Models\CampaignRecipient;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\CRM\CampaignService;
use App\Services\WhatsApp\WahaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessCampaignRecipientJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $recipientId) {}

    public function handle(WahaService $wahaService, CampaignService $campaignService): void
    {
        $recipient = CampaignRecipient::with(['campaign.promo', 'customer'])->find($this->recipientId);

        if (! $recipient || $recipient->status !== 'pending') {
            return;
        }

        if (! $recipient->customer?->whatsapp_number || in_array($recipient->customer->status, ['blacklisted', 'blocked'], true)) {
            $recipient->update(['status' => 'failed', 'failed_reason' => 'Customer tidak valid atau opt-out.']);

            return;
        }

        $recipient->update(['status' => 'processing']);
        $conversation = Conversation::firstOrCreate(
            ['wa_chat_id' => $recipient->customer->whatsapp_number.'@c.us'],
            ['customer_id' => $recipient->customer_id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true]
        );

        try {
            $result = $wahaService->sendText(config('waha.default_session'), $conversation->wa_chat_id, $this->message($recipient));
            $recipient->update([
                'status' => 'sent',
                'wa_message_id' => data_get($result, 'id'),
                'sent_at' => now(),
                'failed_reason' => null,
            ]);

            Message::create([
                'conversation_id' => $conversation->id,
                'customer_id' => $recipient->customer_id,
                'wa_message_id' => data_get($result, 'id'),
                'direction' => 'outgoing',
                'sender_type' => 'system',
                'message_type' => 'text',
                'content' => $this->message($recipient),
                'payload' => $result,
                'sent_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $recipient->update(['status' => 'failed', 'failed_reason' => $exception->getMessage()]);
        }

        $campaignService->markCampaignIfComplete($recipient->campaign);
    }

    private function message(CampaignRecipient $recipient): string
    {
        $message = $recipient->campaign->message_template;
        $promo = $recipient->campaign->promo;

        return strtr($message, [
            '{name}' => $recipient->customer?->name ?? 'Bunda',
            '{promo_code}' => $promo?->code ?? '',
            '{promo_title}' => $promo?->title ?? '',
        ]);
    }
}
