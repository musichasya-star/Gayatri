<?php

namespace App\Services\WhatsApp;

use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\AI\AiInteractionPolicy;
use App\Services\AI\AiTraceService;
use App\Services\CRM\AuditLogService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OutboundMessages
{
    public function claim(int $conversationId, ?int $userId, string $sender, string $text, ?string $session, string $key, ?int $retryId, ?string $token, ?array $aiContext = null): ?Message
    {
        return DB::transaction(function () use ($conversationId, $userId, $sender, $text, $session, $key, $retryId, $token, $aiContext) {
            $conversation = Conversation::with('customer', 'whatsappSession')->find($conversationId);
            if (! $conversation) {
                return null;
            }
            $chat = $this->chat($conversation);
            $session ??= $conversation->whatsappSession?->session_name ?: (string) GatewayConfig::get('default_session');
            $message = $retryId ? Message::whereKey($retryId)->lockForUpdate()->first() : Message::firstOrCreate(['outbound_key' => $key], [
                'conversation_id' => $conversation->id, 'customer_id' => $conversation->customer_id, 'user_id' => $userId,
                'direction' => 'outgoing', 'sender_type' => $sender, 'message_type' => 'text', 'content' => $text,
                'delivery_status' => 'queued', 'queued_at' => now(), 'dispatch_token' => (string) Str::uuid(),
                'send_provider' => GatewayConfig::provider(), 'send_session' => $session, 'send_chat_id' => $chat,
                'ai_reply_context' => $sender === 'ai' ? $aiContext : null,
            ]);
            if (! $message) {
                return null;
            }
            $message = Message::whereKey($message->id)->lockForUpdate()->firstOrFail();
            app(AiTraceService::class)->link($message);
            if ($message->direction !== 'outgoing' || $message->conversation_id !== $conversation->id || $this->accepted($message) || $message->delivery_status !== 'queued' || ($retryId && (! $token || ! hash_equals((string) $message->dispatch_token, $token)))) {
                return null;
            }
            if (! $chat || $message->customer_id !== $conversation->customer_id || ! $conversation->customer || in_array($conversation->customer->status, ['inactive', 'blacklisted', 'blocked'], true)
                || $message->send_provider !== GatewayConfig::provider() || $message->send_chat_id !== $chat || $message->send_session !== $session) {
                $message->update(['delivery_status' => 'skipped', 'failed_reason' => 'Pengiriman dihentikan: status pelanggan, tujuan, provider, atau sesi berubah.', 'failure_code' => 'recipient_changed', 'delivery_revision' => $message->delivery_revision + 1]);

                return null;
            }
            if ($message->sender_type === 'ai' && ($problem = app(AiInteractionPolicy::class)->sendProblem($conversation, (string) $message->content, $message->ai_reply_context))) {
                $message->update(['delivery_status' => 'skipped', 'failed_reason' => 'Balasan AI ditahan: '.$problem, 'failure_code' => $problem, 'delivery_revision' => $message->delivery_revision + 1]);

                return null;
            }
            $message->update(['delivery_status' => 'sending', 'sending_started_at' => now(), 'send_attempts' => $message->send_attempts + 1, 'delivery_revision' => $message->delivery_revision + 1]);

            return $message;
        });
    }

    public function accepted(Message $message): bool
    {
        return (bool) ($message->wa_message_id || $message->sent_at || $message->delivered_at || $message->read_at);
    }

    public function chat(Conversation $conversation): ?string
    {
        $chat = $conversation->wa_chat_id ?: ($conversation->customer?->normalized_whatsapp_number ? $conversation->customer->normalized_whatsapp_number.'@c.us' : '');

        return preg_match('/^\d+(?:@c\.us|@s\.whatsapp\.net|@lid)$/D', (string) $chat) ? $chat : null;
    }

    public function retryProblem(Message $message): ?string
    {
        if ($message->sender_type === 'ai' && $message->conversation
            && ($problem = app(AiInteractionPolicy::class)->sendProblem($message->conversation, (string) $message->content, $message->ai_reply_context))) {
            return 'Balasan AI tidak dapat diulang: '.$problem.'. Tinjau dan buat balasan admin baru.';
        }
        if ($message->direction !== 'outgoing' || $this->accepted($message)) {
            return 'Pesan sudah diterima gateway atau memiliki bukti pengiriman; kirim ulang dikunci.';
        }
        $status = $message->delivery_status ?: ($message->failed_at ? 'unknown' : 'queued');
        if ($status === 'sending' && ($message->sending_started_at ?? $message->updated_at)->lt(now()->subMinutes(10))) {
            return 'Worker belum menyelesaikan pengiriman lebih dari 10 menit. Periksa worker dan WhatsApp; kirim ulang tetap dikunci sampai hasil pengiriman dipastikan.';
        }
        if (! in_array($status, ['failed', 'unknown'], true)) {
            return 'Pesan masih antre, sedang dikirim, atau tidak dapat dicoba ulang.';
        }
        if (! in_array($message->sender_type, ['admin', 'ai'], true)) {
            return 'Pesan otomatis harus ditinjau melalui modul asal agar konteks dan jadwal diperiksa kembali.';
        }
        if (blank($message->content) || str_contains((string) $message->content, '[TAUTAN-PRIBADI]')) {
            return 'Isi pesan tidak tersedia lengkap. Buat balasan baru melalui inbox.';
        }
        if (! $message->conversation || (int) $message->customer_id !== (int) $message->conversation->customer_id || ! $message->customer || in_array($message->customer->status, ['inactive', 'blacklisted', 'blocked'], true)) {
            return 'Pelanggan nonaktif/terblokir atau percakapan sudah berubah.';
        }
        $chat = $this->chat($message->conversation);
        $session = $message->conversation->whatsappSession?->session_name ?: (string) GatewayConfig::get('default_session');
        if (! $chat || ($message->send_chat_id && $message->send_chat_id !== $chat) || ($message->send_session && $message->send_session !== $session) || ($message->send_provider && $message->send_provider !== GatewayConfig::provider())) {
            return 'Nomor tujuan, sesi, atau provider telah berubah. Tinjau melalui inbox.';
        }
        if (($message->retry_count ?? 0) >= 5) {
            return 'Batas lima percobaan ulang tercapai. Tinjau melalui inbox.';
        }
        if ($message->created_at->lt(now()->subDay())) {
            return 'Pesan berusia lebih dari 24 jam. Buat balasan baru sesuai konteks terakhir.';
        }
        if ($message->sender_type === 'ai' && $message->conversation->messages()->where('direction', 'incoming')->where('created_at', '>', $message->created_at)->exists()) {
            return 'Ada pesan pelanggan yang lebih baru. Tinjau balasan AI melalui inbox.';
        }

        return null;
    }

    public function retry(Message $message, User $actor, Request $request): void
    {
        abort_unless($actor->status === 'active' && $actor->hasRole(['owner', 'manager', 'admin']), 403);
        $data = $request->validate(['fingerprint' => ['required', 'string', 'size:64'], 'reason' => ['required', 'string', 'min:5', 'max:500'], 'confirmed_not_sent' => ['required', 'accepted']]);
        DB::transaction(function () use ($message, $actor, $request, $data) {
            $message = Message::whereKey($message->id)->lockForUpdate()->firstOrFail();
            if ($data['fingerprint'] !== WhatsAppMonitor::fingerprint('message', $message)) {
                throw ValidationException::withMessages(['retry' => 'Data berubah. Muat ulang halaman sebelum mencoba ulang.']);
            }
            if ($problem = $this->retryProblem($message)) {
                throw ValidationException::withMessages(['retry' => $problem]);
            }
            $last = DB::table('whatsapp_retry_requests')->where('source', 'message')->where('source_id', $message->id)->latest('id')->first();
            if ($last && Carbon::parse($last->created_at)->gt(now()->subMinute())) {
                throw ValidationException::withMessages(['retry' => 'Tunggu satu menit sebelum meminta percobaan ulang berikutnya.']);
            }
            $session = $message->conversation->whatsappSession?->session_name ?: (string) GatewayConfig::get('default_session');
            $status = $message->delivery_status ?: 'unknown';
            $message->update(['delivery_status' => 'queued', 'queued_at' => now(), 'sending_started_at' => null, 'dispatch_token' => (string) Str::uuid(),
                'retry_count' => ($message->retry_count ?? 0) + 1, 'delivery_revision' => $message->delivery_revision + 1,
                'send_provider' => GatewayConfig::provider(), 'send_session' => $session, 'send_chat_id' => $this->chat($message->conversation), 'failed_at' => null, 'failed_reason' => null, 'failure_code' => null]);
            DB::table('whatsapp_retry_requests')->insert(['source' => 'message', 'source_id' => $message->id, 'user_id' => $actor->id, 'previous_status' => $status, 'reason' => $data['reason'], 'created_at' => now()]);
            app(AuditLogService::class)->log($actor, 'whatsapp.message.retry', $message, $request, ['status' => $status], ['status' => 'queued', 'reason' => $data['reason']], 'Admin memeriksa WhatsApp dan mengantrekan satu percobaan ulang.');
            SendWhatsAppMessageJob::dispatch($message->conversation_id, $message->user_id, $message->sender_type, $message->content, $session, $message->id, $message->dispatch_token)->afterCommit();
        });
    }
}
