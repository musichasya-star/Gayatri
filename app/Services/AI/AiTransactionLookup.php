<?php

namespace App\Services\AI;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\WhatsApp\WhatsAppSenderIdentity;
use App\Support\AiDomain;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

class AiTransactionLookup
{
    public const DENIED = 'Data belum dapat ditampilkan. Pastikan kode dan nomor pemesanan benar, lalu kirim dari WhatsApp pemilik yang terdaftar. Ketik admin bila membutuhkan pemeriksaan kontak. Demi privasi, hasil ini tidak menunjukkan apakah suatu kode terdaftar.';

    public function identity(array $input): ?array
    {
        if (($input['mode'] ?? '') === 'simulator') {
            if (! app()->environment('local', 'baileys-local', 'testing') || ! ($input['include_demo'] ?? false)) {
                return null;
            }
            $profile = DB::table('ai_demo_profiles')->where('id', $input['demo_profile_id'] ?? 0)->first();

            return $profile ? ['customer_id' => (int) $profile->customer_id, 'phone' => $profile->phone,
                'is_demo' => true, 'demo_profile_id' => (int) $profile->id] : null;
        }
        $conversation = Conversation::find($input['conversation_id'] ?? 0);
        $source = Message::find($input['message_id'] ?? 0);

        return $conversation && $source ? app(WhatsAppSenderIdentity::class)->resolve($conversation, $source) : null;
    }

    public function read(string $code, array $identity, ?string $domain): ?array
    {
        if (str_starts_with($code, 'BK-')) {
            return app(BookingStatusReader::class)->read($code, $identity, in_array($domain, ['spa', 'lactation'], true) ? $domain : null);
        }
        if (preg_match('/^(RENT|RNT)-/', $code)) {
            return app(RentalStatusReader::class)->read($code, $identity);
        }

        return app(LaundryStatusReader::class)->read($code, $identity);
    }

    public function respond(string $message, array $state, array $input, array $base): array
    {
        $base['routing']['intent'] = 'status_lookup';
        $base['next_context']['lookup'] = null;
        $base['next_context']['menu'] = 'lookup';
        $base['evidence'] = [];
        try {
            $identity = $this->identity($input);
            if (! $identity) {
                $base['reply'] = 'Identitas WhatsApp pemilik belum dapat diverifikasi. Saya teruskan ke admin untuk memeriksa kontak melalui alur yang aman. Detail transaksi tidak ditampilkan sebelum verifikasi.'
                    .(($input['mode'] ?? '') === 'simulator' ? ' Simulator hanya dapat memeriksa profil DEMO yang dipilih.' : '');
                $base['status'] = 'escalated';
                $base['fallback_reason'] = 'lookup_identity_unverified';
                $base['routing']['reason_code'] = 'lookup_identity_unverified';
                $base['next_context']['menu'] = null;

                return $base;
            }
            $pending = $state['lookup'] ?? [];
            if (($pending['expires_at'] ?? 0) < now()->timestamp || ($pending['identity_key'] ?? '') !== $this->identityKey($identity)) {
                $pending = [];
            }
            $code = null;
            if (preg_match('/\b(?:BK|RENT|RNT|LND)-[A-Z0-9]+(?:-[A-Z0-9]+)*\b/i', $message, $m)
                || preg_match('/\b[A-F0-9]{8}(?:-[A-F0-9]{4}){3}-[A-F0-9]{12}\b/i', $message, $m)) {
                $code = strtoupper($m[0]);
            }
            $code ??= $pending['code'] ?? null;
            $domain = $pending['domain'] ?? ($base['routing']['domain'] ?? null);
            if (! in_array($domain, ['spa', 'lactation', 'rental', 'laundry'], true)) {
                $domain = null;
            }
            if (preg_match('/^(?:RENT|RNT)-/', (string) $code)) {
                $domain = 'rental';
            }
            if (str_starts_with((string) $code, 'LND-') || ($code && preg_match('/^[A-F0-9]{8}-/', $code))) {
                $domain = 'laundry';
            }
            if (($pending['step'] ?? null) === 'domain' && preg_match('/^[1-4]$/D', trim($message))) {
                $domain = AiDomain::MENU[(int) trim($message)];
            }
            $withoutCode = $code ? str_ireplace($code, '', $message) : $message;
            $phone = null;
            if (preg_match('/(?<![\w])(?:\+62|62|0)[ .()\-]*[0-9][0-9 .()\-]{6,22}[0-9](?![\w])/', $withoutCode, $m)) {
                $phone = app(WhatsAppSenderIdentity::class)->phone(trim($m[0]));
            }
            $next = ['domain' => $domain, 'code' => $code, 'identity_key' => $this->identityKey($identity),
                'expires_at' => $pending['expires_at'] ?? now()->addMinutes(10)->timestamp];
            if (! $code) {
                $next['step'] = $domain ? 'code' : 'domain';
                $base['reply'] = $domain ? 'Kirim kode booking/pesanan lengkap. Jangan kirim bukti pembayaran atau data kesehatan di sini.'
                    : "Layanan mana yang ingin diperiksa?\n1. Pijat & Spa\n2. Laktasi & Konsultasi ASI\n3. Rental Perlengkapan Bayi\n4. Laundry Ibu & Bayi\nKetik menu untuk membatalkan.";
                $base['next_context']['lookup'] = $next;
                $base['routing']['reason_code'] = 'lookup_need_'.$next['step'];

                return $base;
            }
            if (! $phone) {
                $next['step'] = 'phone';
                $base['reply'] = 'Kirim nomor WhatsApp yang digunakan saat pemesanan (08… atau +62…). Nomor ini harus cocok dengan WhatsApp pengirim yang sudah diverifikasi. Ketik menu untuk membatalkan.';
                $base['next_context']['lookup'] = $next;
                $base['routing']['reason_code'] = 'lookup_need_phone';

                return $base;
            }
            $conversation = isset($input['conversation_id']) ? Conversation::find($input['conversation_id']) : null;
            if (! app(AiLookupLimiter::class)->consume($identity, $conversation?->whatsapp_session_id)) {
                $this->audit($identity, $input, 'limited');
                $base['reply'] = 'Batas percobaan pengecekan telah tercapai. Tunggu 10 menit lalu mulai lagi, atau ketik admin untuk bantuan.';
                $base['routing']['reason_code'] = 'lookup_limited';

                return $base;
            }
            $view = $phone === $identity['phone'] && strlen($code) <= 100 ? $this->read($code, $identity, $domain) : null;
            $this->audit($identity, $input, $view ? 'verified' : 'denied');
            $base['next_context']['menu'] = 'main';
            if (! $view) {
                $base['reply'] = self::DENIED;
                $base['routing']['reason_code'] = 'lookup_denied';

                return $base;
            }
            $base['routing']['domain'] = $view['domain'];
            $base['routing']['reason_code'] = 'lookup_verified';
            $base['next_context']['domain'] = $view['domain'];
            $base['reply'] = ($identity['is_demo'] ? "[DEMO · simulasi lokal]\n" : '').implode("\n", $view['lines'])
                ."\nDiperiksa: ".now('Asia/Jakarta')->format('d-m-Y H:i')." WIB.\nKetik menu untuk layanan lain atau admin untuk bantuan. Chat ini hanya membaca status.";
            $base['evidence']['lookup'] = Crypt::encryptString(json_encode(['code' => $code, 'domain' => $domain,
                'identity' => $identity, 'source_id' => $input['message_id'] ?? null, 'fingerprint' => $view['fingerprint'],
                'expires_at' => now()->addMinutes(5)->timestamp], JSON_THROW_ON_ERROR));

            return $base;
        } catch (Throwable $e) {
            // Do not log exceptions containing query bindings, phone numbers, or codes.
            $base['reply'] = 'Pengecekan aman belum tersedia saat ini. Ketik admin untuk dibantu memeriksa transaksi.';
            $base['routing']['reason_code'] = 'lookup_unavailable';
            $base['status'] = 'escalated';
            $base['fallback_reason'] = 'lookup_unavailable';
            $base['next_context']['menu'] = null;

            return $base;
        }
    }

    private function identityKey(array $identity): string
    {
        return hash_hmac('sha256', json_encode($identity), (string) config('app.key'));
    }

    private function audit(array $identity, array $input, string $result): void
    {
        DB::table('ai_lookup_attempts')->insert(['scope_hash' => hash_hmac('sha256', $identity['phone'], (string) config('app.key')),
            'conversation_id' => $input['conversation_id'] ?? null, 'message_id' => $input['message_id'] ?? null,
            'result' => $result, 'created_at' => now()]);
    }

    public function evidenceProblem(string $encrypted, int $conversationId): ?string
    {
        try {
            $grant = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
            if (($grant['expires_at'] ?? 0) < now()->timestamp || ($grant['identity']['is_demo'] ?? true)) {
                return 'lookup_expired_or_demo';
            }
            $identity = $this->identity(['conversation_id' => $conversationId, 'message_id' => $grant['source_id']]);
            if (! $identity || $this->identityKey($identity) !== $this->identityKey($grant['identity'])) {
                return 'lookup_owner_changed';
            }
            $view = $this->read($grant['code'], $identity, $grant['domain']);

            return $view && hash_equals($view['fingerprint'], $grant['fingerprint']) ? null : 'lookup_status_changed';
        } catch (Throwable) {
            return 'lookup_verification_unavailable';
        }
    }
}
