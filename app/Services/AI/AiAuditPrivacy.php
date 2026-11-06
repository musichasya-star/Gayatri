<?php

namespace App\Services\AI;

class AiAuditPrivacy
{
    public function text(?string $text, int $limit = 2600): string
    {
        $text = (string) $text;
        foreach (['ai.api_key', 'waha.api_key', 'waha.webhook_secret', 'baileys.api_key', 'baileys.webhook_secret', 'app.key'] as $key) {
            $secret = (string) config($key, '');
            if (strlen($secret) >= 8) {
                $text = str_replace($secret, '[rahasia disembunyikan]', $text);
            }
        }
        $text = preg_replace('/\b(?:sk-(?:proj-)?[a-z0-9_-]{12,}|[a-f0-9]{64})\b/i', '[rahasia disembunyikan]', $text);
        $text = preg_replace('~https?://[^\s<>]*?(?:/portal/|/access/|token=)[^\s<>]*~iu', '[tautan pribadi disembunyikan]', $text);
        $text = preg_replace('/(?:bearer\s+|(?:api[_-]?key|token|secret|password)\s*[:=]\s*)[^\s,;]+/i', '[rahasia disembunyikan]', $text);
        $text = preg_replace('/(?<![\w])(?:\+62|62|0)[ .()-]*\d[\d .()-]{7,20}\d(?![\w])/u', '[nomor disembunyikan]', $text);

        return mb_substr(strip_tags($text), 0, min(12000, max(1, $limit)));
    }
}
