<?php

namespace App\Jobs;

use App\Models\BirthdayGreeting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsAppSession;
use App\Services\CRM\BirthdayGreetingService;
use App\Services\WhatsApp\GatewayConfig;
use App\Services\WhatsApp\WhatsAppGateway;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class SendBirthdayGreetingJob implements ShouldQueue
{
    use Queueable;

    // Releases caused by pacing or a temporarily disabled feature may repeat until midnight.
    public int $tries = 0;

    public int $maxExceptions = 1;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $greetingId) {}

    public function retryUntil(): Carbon
    {
        $date = BirthdayGreeting::find($this->greetingId)?->birthday_on?->toDateString();

        return Carbon::parse($date ?: 'today', BirthdayGreetingService::TIMEZONE)->endOfDay();
    }

    public function handle(WhatsAppGateway $gateway, BirthdayGreetingService $service): void
    {
        $greeting = BirthdayGreeting::with('customer.conversations')->find($this->greetingId);
        if (! $greeting || $greeting->status !== 'queued' || ! $greeting->customer) {
            return;
        }
        $now = $service->now();
        if ($greeting->birthday_on->toDateString() !== $now->toDateString()) {
            $this->skip('Tanggal ulang tahun sudah terlewati.');

            return;
        }
        $settings = $service->settings();
        if (! $service->canDispatch($settings) || ! $settings['include_'.$greeting->birthday_type]) {
            $this->release(60);

            return;
        }
        $chatId = $service->recipient($greeting->customer);
        if (! $chatId || ! $service->occursOn($greeting->customer, $greeting->birthday_type, $now, $settings)) {
            $this->skip('Pelanggan tidak aktif, WhatsApp tidak valid, atau tanggal lahir telah berubah.');

            return;
        }
        $lock = Cache::lock('crm:birthday-whatsapp-send', 90);
        if (! $lock->get()) {
            $this->release(10);

            return;
        }
        try {
            if (RateLimiter::tooManyAttempts('crm:birthday-whatsapp-rate', 1)) {
                $this->release(10);

                return;
            }
            $session = (string) GatewayConfig::get('default_session');
            $message = $service->render($greeting->customer, $greeting->birthday_type, $settings, $now);
            $claimed = BirthdayGreeting::whereKey($this->greetingId)->where('status', 'queued')->update([
                'status' => 'sending', 'started_at' => now(), 'attempts' => DB::raw('attempts + 1'),
                'session_name' => $session, 'chat_id' => $chatId, 'message' => $message, 'failure_reason' => null,
            ]);
            if (! $claimed) {
                return;
            }
            RateLimiter::hit('crm:birthday-whatsapp-rate', 10);
            try {
                $result = $gateway->sendText($session, $chatId, $message);
                $waId = data_get($result, 'id._serialized') ?: data_get($result, 'id');
                if (! is_scalar($waId) || (string) $waId === '') {
                    throw new \RuntimeException('Gateway merespons tanpa message ID. Periksa WhatsApp sebelum mencoba ulang.');
                }
                // Persist confirmed acceptance before inbox bookkeeping; never resend a confirmed message.
                $greeting->update(['status' => 'sent', 'wa_message_id' => (string) $waId, 'sent_at' => now(), 'failure_reason' => null]);
                $tracking = WhatsAppSession::firstOrCreate(['session_name' => $session], ['status' => 'unknown']);
                $conversation = Conversation::firstOrCreate(['wa_chat_id' => $chatId], [
                    'customer_id' => $greeting->customer_id, 'whatsapp_session_id' => $tracking->id,
                    'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true,
                ]);
                if ($conversation->customer_id !== $greeting->customer_id) {
                    throw new \RuntimeException('Pesan diterima gateway, tetapi percakapan WhatsApp terhubung ke customer lain.');
                }
                $record = Message::firstOrCreate(['wa_message_id' => (string) $waId], [
                    'conversation_id' => $conversation->id, 'customer_id' => $greeting->customer_id,
                    'direction' => 'outgoing', 'sender_type' => 'system', 'message_type' => 'text',
                    'content' => $message, 'payload' => $result + ['birthday_greeting_id' => $greeting->id], 'sent_at' => now(),
                ]);
                $greeting->update(['message_id' => $record->id]);
                $conversation->update(['last_message_at' => now()]);
            } catch (Throwable $exception) {
                if ($greeting->fresh()?->status === 'sent') {
                    $greeting->update(['failure_reason' => 'Terkirim; pencatatan inbox perlu diperiksa: '.$exception->getMessage()]);
                } else {
                    $safeToRetry = $exception instanceof RequestException
                        && in_array($exception->response->status(), [400, 401, 403, 404, 409, 422, 429], true);
                    $greeting->update([
                        'status' => $safeToRetry ? 'failed' : 'unknown',
                        'failure_reason' => $exception->getMessage(),
                    ]);
                }
            }
        } finally {
            $lock->release();
        }
    }

    private function skip(string $reason): void
    {
        BirthdayGreeting::whereKey($this->greetingId)->where('status', 'queued')->update(['status' => 'skipped', 'failure_reason' => $reason]);
    }

    public function failed(?Throwable $exception): void
    {
        BirthdayGreeting::whereKey($this->greetingId)->where('status', 'sending')->update([
            'status' => 'unknown', 'failure_reason' => 'Proses terputus saat pengiriman. Periksa WhatsApp sebelum mencoba ulang.',
        ]);
        $this->skip('Antrean berakhir tanpa pengiriman. '.($exception?->getMessage() ?? ''));
    }
}
