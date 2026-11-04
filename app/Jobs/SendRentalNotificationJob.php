<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\RentalNotification;
use App\Models\RentalOrder;
use App\Models\WhatsAppSession;
use App\Services\CRM\RentalMessagingService;
use App\Services\CRM\RentalNotificationService;
use App\Services\WhatsApp\GatewayConfig;
use App\Services\WhatsApp\WhatsAppGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class SendRentalNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 1;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $notificationId) {}

    public function retryUntil(): \DateTimeInterface
    {
        return RentalNotification::find($this->notificationId)?->expires_at ?? now();
    }

    public function handle(WhatsAppGateway $gateway, RentalNotificationService $service): void
    {
        $row = RentalNotification::with('order.customer')->find($this->notificationId);
        if (! $row || $row->status !== 'queued') {
            return;
        }
        $settings = $service->settings();
        if (! $settings['enabled'] || ! in_array($row->kind, $settings['events'], true) || ! $service->current($row) || ! ($chatId = $service->recipientFor($row->order, $row->kind, $settings))) {
            $row->update(['status' => 'skipped', 'failure_reason' => 'Pengaturan, pelanggan, status, atau jadwal berubah. Pesan tidak lagi berlaku.']);

            return;
        }
        $lock = Cache::lock('crm:rental-whatsapp-send', 90);
        if (! $lock->get()) {
            $this->release(10);

            return;
        }
        try {
            if (RateLimiter::tooManyAttempts('crm:rental-whatsapp-rate', 1)) {
                $this->release(10);

                return;
            }
            $claim = DB::transaction(function () use ($row, $service) {
                $order = RentalOrder::whereKey($row->rental_order_id)->lockForUpdate()->first();
                $row = RentalNotification::whereKey($row->id)->lockForUpdate()->first();
                if (! $row || $row->status !== 'queued') {
                    return null;
                }
                $row->setRelation('order', $order);
                $settings = $service->settings();
                if (! $order || ! $settings['enabled'] || ! in_array($row->kind, $settings['events'], true) || ! $service->current($row) || ! ($chatId = $service->recipientFor($order, $row->kind, $settings))) {
                    $row->update(['status' => 'skipped', 'failure_reason' => 'Saldo, tujuan, tautan, atau status berubah sebelum pengiriman.']);

                    return null;
                }
                $conversation = Conversation::where('wa_chat_id', $chatId)->with('whatsappSession')->first();
                if ($conversation && (int) $conversation->customer_id !== (int) $order->customer_id) {
                    $row->update(['status' => 'skipped', 'failure_reason' => 'Tujuan WhatsApp tercatat pada pelanggan lain. Cocokkan inbox sebelum mengirim.']);

                    return null;
                }
                $session = $conversation?->whatsappSession?->session_name ?: (string) GatewayConfig::get('default_session');
                $content = $service->render($order, $row->kind, $row->context ?? [], $settings, true);
                $row->update(['status' => 'sending', 'started_at' => now(), 'content' => RentalMessagingService::redact($content), 'chat_id' => $chatId, 'session_name' => $session, 'attempts' => $row->attempts + 1, 'failure_reason' => null]);

                return compact('row', 'content', 'chatId', 'session', 'conversation', 'settings');
            });
            if (! $claim) {
                return;
            }
            ['row' => $row, 'content' => $content, 'chatId' => $chatId, 'session' => $session, 'conversation' => $conversation, 'settings' => $settings] = $claim;
            RateLimiter::hit('crm:rental-whatsapp-rate', (int) $settings['min_interval_seconds']);
            try {
                $result = $gateway->sendText($session, $chatId, $content);
                $waId = data_get($result, 'id._serialized') ?: data_get($result, '_data.id._serialized') ?: data_get($result, 'id');
                if (! is_scalar($waId) || (string) $waId === '') {
                    throw new \RuntimeException('Gateway tidak memberikan message ID. Periksa WhatsApp sebelum mencoba ulang.');
                }
                $row->update(['status' => 'sent', 'wa_message_id' => (string) $waId, 'sent_at' => now(), 'failure_reason' => null]);
                $tracking = WhatsAppSession::firstOrCreate(['session_name' => $session], ['status' => 'unknown']);
                $conversation ??= Conversation::firstOrCreate(['wa_chat_id' => $chatId], ['customer_id' => $row->order->customer_id, 'whatsapp_session_id' => $tracking->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true]);
                if ($conversation->customer_id !== $row->order->customer_id) {
                    throw new \RuntimeException('Percakapan terhubung ke pelanggan berbeda; periksa pencatatan inbox.');
                }
                $message = Message::firstOrCreate(['wa_message_id' => (string) $waId], ['conversation_id' => $conversation->id, 'customer_id' => $row->order->customer_id, 'direction' => 'outgoing', 'sender_type' => 'system', 'message_type' => 'text', 'content' => RentalMessagingService::redact($content), 'payload' => RentalMessagingService::redactPayload($result) + ['rental_notification_id' => $row->id], 'sent_at' => now()]);
                $row->update(['message_id' => $message->id]);
                $conversation->update(['last_message_at' => now()]);
            } catch (Throwable $e) {
                if ($row->fresh()?->status === 'sent') {
                    $row->update(['failure_reason' => RentalMessagingService::redact('Terkirim; pencatatan inbox perlu diperiksa: '.$e->getMessage())]);
                } else {
                    $row->update(['status' => $e instanceof RequestException && in_array($e->response->status(), [400, 401, 403, 404, 409, 422, 429], true) ? 'failed' : 'unknown', 'failure_reason' => RentalMessagingService::redact($e->getMessage())]);
                }
            }
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        RentalNotification::whereKey($this->notificationId)->where('status', 'sending')->update(['status' => 'unknown', 'failure_reason' => 'Proses pengiriman terputus. Periksa WhatsApp sebelum mencoba ulang.']);
        RentalNotification::whereKey($this->notificationId)->where('status', 'queued')->update(['status' => 'skipped', 'failure_reason' => 'Antrean berakhir sebelum pesan dikirim.']);
    }
}
