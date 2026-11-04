<?php

namespace App\Services\WhatsApp;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsAppSession;
use App\Support\CustomerPhone;
use Illuminate\Support\Facades\DB;
use Throwable;

class WhatsAppSenderIdentity
{
    public function jid(string $jid): ?string
    {
        if (! preg_match('/^([0-9]+)(?::[0-9]+)?@(c\.us|s\.whatsapp\.net|lid)$/D', $jid, $m)) {
            return null;
        }

        return $m[1].($m[2] === 'lid' ? '@lid' : '@c.us');
    }

    public function phone(string $value): ?string
    {
        try {
            if (! preg_match('/^(?:\+62|62|0)[0-9 ()+.\-]{7,24}$/D', $value)) {
                return null;
            }

            return CustomerPhone::normalize($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function authorized(array $event, WhatsAppSession $session): bool
    {
        return data_get($event, '_gayatri_gateway.provider') === GatewayConfig::provider()
            && data_get($event, '_gayatri_gateway.session') === $session->session_name
            && is_int(data_get($event, '_gayatri_gateway.accepted_at'))
            && data_get($event, '_gayatri_gateway.accepted_at') >= now()->subMinutes(15)->timestamp
            && data_get($event, '_gayatri_gateway.accepted_at') <= now()->addMinute()->timestamp;
    }

    /** Only gateway metadata or an existing verified mapping can supply the number. */
    public function trustedPhone(array $event, WhatsAppSession $session): ?string
    {
        if (! $this->authorized($event, $session)) {
            return null;
        }
        $from = $this->jid((string) data_get($event, 'payload.from', data_get($event, 'payload.chatId', '')));
        if (! $from) {
            return null;
        }
        if (! str_ends_with($from, '@lid')) {
            return $this->phone(explode('@', $from)[0]);
        }
        $metadata = data_get($event, 'payload.senderIdentity', []);
        if (GatewayConfig::provider() === 'baileys' && is_array($metadata) && ($metadata['version'] ?? null) === 1
            && $this->jid((string) ($metadata['originalJid'] ?? '')) === $from
            && in_array($metadata['source'] ?? '', ['message_key_alt', 'baileys_cache'], true)) {
            $phoneJid = $this->jid((string) ($metadata['phoneJid'] ?? ''));
            if ($phoneJid && str_ends_with($phoneJid, '@c.us')) {
                return $this->phone(explode('@', $phoneJid)[0]);
            }
        }
        $mapping = DB::table('whatsapp_identities')->where('provider', GatewayConfig::provider())
            ->where('whatsapp_session_id', $session->id)->where('jid', $from)
            ->where('status', 'verified')->whereNull('revoked_at')->where('verified_at', '>=', now()->subDays(30))->first();

        return $mapping?->phone;
    }

    public function observe(array $event, WhatsAppSession $session, Message $message): void
    {
        if (! $this->authorized($event, $session) || ! $message->sent_at || $message->sent_at->lt(now()->subMinutes(15))
            || $message->sent_at->gt(now()->addMinute())) {
            return;
        }
        $phone = $this->trustedPhone($event, $session);
        $from = $this->jid((string) data_get($event, 'payload.from', data_get($event, 'payload.chatId', '')));
        if (! $phone || ! $from) {
            return;
        }
        $aliases = [$from];
        $meta = data_get($event, 'payload.senderIdentity', []);
        $source = str_ends_with($from, '@lid') ? 'cached_verified_pair' : 'phone_jid';
        if (GatewayConfig::provider() === 'baileys' && is_array($meta) && ($meta['version'] ?? null) === 1
            && ($meta['source'] ?? '') !== 'unmapped_lid') {
            $phoneJid = $this->jid((string) ($meta['phoneJid'] ?? ''));
            $original = $this->jid((string) ($meta['originalJid'] ?? ''));
            if (! $phoneJid || ! str_ends_with($phoneJid, '@c.us') || $this->phone(explode('@', $phoneJid)[0]) !== $phone
                || ! $original || ($from !== $original && $from !== $phoneJid)
                || (str_ends_with($original, '@c.us') && ($original !== $phoneJid || ($meta['source'] ?? '') !== 'phone_jid'))
                || (str_ends_with($original, '@lid') && ! in_array($meta['source'] ?? '', ['message_key_alt', 'baileys_cache'], true))
                || ! in_array($meta['source'] ?? '', ['phone_jid', 'message_key_alt', 'baileys_cache'], true)) {
                return;
            }
            $source = $meta['source'];
            $aliases[] = $original;
            $lid = $this->jid((string) ($meta['lid'] ?? ''));
            if ($lid && str_ends_with($lid, '@lid')) {
                $aliases[] = $lid;
            }
        }
        $identity = DB::transaction(function () use ($aliases, $phone, $session, $source, $message, $from) {
            $current = null;
            foreach (array_unique($aliases) as $alias) {
                DB::table('whatsapp_identities')->insertOrIgnore([
                    'provider' => GatewayConfig::provider(), 'whatsapp_session_id' => $session->id,
                    'jid' => $alias, 'phone' => $phone, 'source' => $source, 'status' => 'verified', 'revision' => 1,
                    'verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $row = DB::table('whatsapp_identities')->where('provider', GatewayConfig::provider())
                    ->where('whatsapp_session_id', $session->id)->where('jid', $alias)->lockForUpdate()->first();
                if (! $row || $row->phone !== $phone || $row->status !== 'verified' || $row->revoked_at) {
                    if ($row && $row->status !== 'conflict') {
                        DB::table('whatsapp_identities')->where('id', $row->id)->update(['status' => 'conflict',
                            'revoked_at' => now(), 'revision' => $row->revision + 1, 'updated_at' => now()]);
                    }

                    return null;
                }
                $changes = ['last_message_id' => $message->id, 'updated_at' => now()];
                if ($source !== 'cached_verified_pair') {
                    $changes['verified_at'] = now();
                }
                DB::table('whatsapp_identities')->where('id', $row->id)->update($changes);
                if ($alias === $from) {
                    $current = $row;
                }
            }

            return $current;
        });
        if ($identity) {
            $proof = ['id' => $identity->id, 'revision' => (int) $identity->revision];
            $proof['signature'] = $this->signature($message, $identity);
            $payload = $message->payload ?? [];
            $payload['_gayatri_sender'] = $proof;
            $message->update(['payload' => $payload]);
        }
    }

    private function signature(Message $message, object $identity): string
    {
        return hash_hmac('sha256', json_encode([$message->id, $message->conversation_id, $message->customer_id, $message->wa_message_id,
            $identity->id, (int) $identity->revision, $identity->provider, $identity->whatsapp_session_id, $identity->jid, $identity->phone]),
            (string) config('app.key'));
    }

    public function resolve(Conversation $conversation, Message $source): ?array
    {
        if ($source->direction !== 'incoming' || $source->conversation_id !== $conversation->id || $source->customer_id !== $conversation->customer_id
            || ! $source->sent_at || $source->sent_at->lt(now()->subMinutes(15)) || $source->sent_at->gt(now()->addMinute())) {
            return null;
        }
        $proof = data_get($source->payload, '_gayatri_sender', []);
        $row = DB::table('whatsapp_identities')->where('id', $proof['id'] ?? null)
            ->where('provider', GatewayConfig::provider())->where('whatsapp_session_id', $conversation->whatsapp_session_id)
            ->where('status', 'verified')->whereNull('revoked_at')->where('verified_at', '>=', now()->subDays(30))->first();
        if (! $row || (int) $row->revision !== ($proof['revision'] ?? null)
            || $this->jid((string) data_get($source->payload, 'from', '')) !== $row->jid
            || ! hash_equals($this->signature($source, $row), (string) ($proof['signature'] ?? ''))) {
            return null;
        }
        $lid = $this->jid((string) data_get($source->payload, 'senderIdentity.lid', ''));
        if ($lid && ! DB::table('whatsapp_identities')->where('provider', $row->provider)
            ->where('whatsapp_session_id', $row->whatsapp_session_id)->where('jid', $lid)->where('phone', $row->phone)
            ->where('status', 'verified')->whereNull('revoked_at')->where('verified_at', '>=', now()->subDays(30))->exists()) {
            return null;
        }
        $owners = CustomerPhone::matching($row->phone)->get(['id', 'status']);
        if ($owners->count() !== 1 || $owners[0]->id !== $conversation->customer_id
            || ! in_array($owners[0]->status, ['lead', 'active'], true)
            || DB::table('ai_demo_profiles')->where('customer_id', $owners[0]->id)->exists()) {
            return null;
        }

        return ['customer_id' => $owners[0]->id, 'phone' => $row->phone, 'is_demo' => false,
            'identity_id' => $row->id, 'identity_revision' => (int) $row->revision];
    }
}
