<?php

namespace App\Services\AI;

use App\Services\WhatsApp\WhatsAppSenderIdentity;

class LookupContact
{
    public function matches(?string $phone, array $identity): bool
    {
        return $phone !== null && app(WhatsAppSenderIdentity::class)->phone($phone) === $identity['phone'];
    }

    public function result(string $type, $record, string $domain, array $lines, array $contact): array
    {
        $view = ['type' => $type, 'id' => $record->id, 'domain' => $domain, 'lines' => $lines];
        $view['fingerprint'] = hash('sha256', json_encode([$type, $record->id, $record->customer_id, $lines, $contact], JSON_THROW_ON_ERROR));

        return $view;
    }
}
