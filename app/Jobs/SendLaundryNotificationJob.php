<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\LaundryNotification;
use App\Models\LaundryOrder;
use App\Models\LaundryTransportTask;
use App\Models\Message;
use App\Models\WhatsAppSession;
use App\Services\CRM\LaundryNotificationService;
use App\Services\CRM\LaundryPortal;
use App\Services\WhatsApp\GatewayConfig;
use App\Services\WhatsApp\WhatsAppGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class SendLaundryNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 1;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $notificationId) {}

    public function retryUntil(): \DateTimeInterface
    {
        return LaundryNotification::find($this->notificationId)?->expires_at ?? now();
    }

    public function handle(WhatsAppGateway $gateway, LaundryNotificationService $service): void
    {
        $row = LaundryNotification::find($this->notificationId);
        if (! $row || $row->status !== 'queued') {
            return;
        }
        $settings = $service->settings();
        if (! $settings['enabled'] || $settings['dry_run'] || $row->expires_at->isPast()) {
            $row->update(['status' => 'skipped', 'failure_reason' => 'Pengiriman nonaktif, beralih ke simulasi, atau melewati batas waktu.']);

            return;
        }
        $lock = Cache::lock('crm:laundry-whatsapp-send', 90);
        if (! $lock->get()) {
            $this->release(15);

            return;
        }
        try {
            if (now()->hour < $settings['start_hour'] || now()->hour >= $settings['end_hour']) {
                $next = now()->hour >= $settings['end_hour'] ? now()->addDay()->setTime($settings['start_hour'], 0) : now()->setTime($settings['start_hour'], 0);
                $this->release(max(1, (int) ceil(now()->diffInSeconds($next))));

                return;
            }
            if (RateLimiter::tooManyAttempts('crm:laundry-whatsapp-rate', 1)) {
                $this->release(15);

                return;
            }
            $claim = DB::transaction(function () use ($row, $service) {
                if ($row->laundry_order_id) {
                    LaundryOrder::whereKey($row->laundry_order_id)->lockForUpdate()->first();
                }
                if ($row->laundry_transport_task_id) {
                    LaundryTransportTask::whereKey($row->laundry_transport_task_id)->lockForUpdate()->first();
                }
                $row = LaundryNotification::whereKey($row->id)->lockForUpdate()->first();
                if ($row->status !== 'queued') {
                    return null;
                }
                $settings = $service->settings();
                $chatId = $service->recipient($row->order, $row->task);
                if (! $settings['enabled'] || $settings['dry_run'] || ! in_array($row->kind, $settings['events'], true) || ! $service->current($row) || ! $chatId) {
                    $row->update(['status' => 'skipped', 'failure_reason' => 'Mode, tujuan, saldo, atau status sudah berubah.']);

                    return null;
                }
                $customerId = $row->order?->customer_id ?? $row->task?->customer_id;
                $conversation = Conversation::where('wa_chat_id', $chatId)->with('whatsappSession')->first();
                if ($conversation && (int) $conversation->customer_id !== $customerId) {
                    $row->update(['status' => 'skipped', 'failure_reason' => 'Nomor terhubung ke pelanggan lain di inbox.']);

                    return null;
                }
                $session = $conversation?->whatsappSession?->session_name ?: (string) GatewayConfig::get('default_session');
                $content = $service->render($row->order, $row->task, $row->kind, $row->context, true);
                if (mb_strlen($content) > LaundryNotificationService::MAX_MESSAGE_LENGTH) {
                    $row->update(['status' => 'skipped', 'failure_reason' => 'Template menghasilkan pesan terlalu panjang. Ringkas template.']);

                    return null;
                }
                $row->update(['status' => 'sending', 'started_at' => now(), 'attempts' => $row->attempts + 1, 'chat_id' => $chatId, 'session_name' => $session, 'content' => LaundryPortal::redact($content), 'failure_reason' => null]);

                return compact('row', 'chatId', 'session', 'content', 'customerId', 'conversation', 'settings');
            });
            if (! $claim) {
                return;
            }
            extract($claim);
            RateLimiter::hit('crm:laundry-whatsapp-rate', $settings['min_interval_seconds']);
            try {
                $result = $gateway->sendText($session, $chatId, $content);
                $waId = data_get($result, 'id._serialized') ?: data_get($result, '_data.id._serialized') ?: data_get($result, 'id');
                if (! is_scalar($waId) || (string) $waId === '') {
                    throw new \RuntimeException('Missing message ID.');
                }
                $row->update(['status' => 'sent', 'sent_at' => now(), 'wa_message_id' => (string) $waId]);
                $tracking = WhatsAppSession::firstOrCreate(['session_name' => $session], ['status' => 'unknown']);
                $conversation ??= Conversation::firstOrCreate(['wa_chat_id' => $chatId], ['customer_id' => $customerId, 'whatsapp_session_id' => $tracking->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true]);
                if ((int) $conversation->customer_id !== $customerId) {
                    throw new \RuntimeException('Inbox changed.');
                }
                Message::firstOrCreate(['wa_message_id' => (string) $waId], ['conversation_id' => $conversation->id, 'customer_id' => $customerId, 'direction' => 'outgoing', 'sender_type' => 'system', 'message_type' => 'text', 'content' => LaundryPortal::redact($content), 'payload' => ['laundry_notification_id' => $row->id], 'sent_at' => now()]);
                $conversation->update(['last_message_at' => now()]);
            } catch (Throwable $exception) {
                $row->refresh();
                if ($row->status === 'sent') {
                    $row->update(['failure_reason' => 'Terkirim; pencatatan inbox perlu diperiksa.']);
                } else {
                    $known = $exception instanceof RequestException && in_array($exception->response->status(), [400, 401, 403, 404, 409, 422, 429], true);
                    $row->update(['status' => $known ? 'failed' : 'unknown', 'failure_reason' => $known ? 'Gateway menolak permintaan. Periksa koneksi dan pengaturan sebelum mencoba ulang.' : 'Hasil pengiriman belum pasti. Periksa WhatsApp sebelum mencoba ulang.']);
                }
            }
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        LaundryNotification::whereKey($this->notificationId)->where('status', 'sending')->update(['status' => 'unknown', 'failure_reason' => 'Proses terputus. Periksa WhatsApp sebelum mencoba ulang.']);
        LaundryNotification::whereKey($this->notificationId)->where('status', 'queued')->update(['status' => 'skipped', 'failure_reason' => 'Antrean berakhir sebelum pesan dikirim.']);
    }
}
