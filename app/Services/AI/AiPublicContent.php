<?php

namespace App\Services\AI;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AiPublicContent
{
    public function unsafe(string $text): bool
    {
        return (bool) preg_match('/(?:ignore|abaikan).{0,35}(?:instruction|instruksi|aturan)|system[_ ]?prompt|api[_ -]?key\s*[:=]|password\s*[:=]|sk-(?:proj-)?[a-z0-9_-]{12,}|(?:booking|pesanan|pembayaran)(?:\s+(?:anda|bunda|kamu|ini))?\s+(?:sudah|telah)\s+(?:confirmed|dikonfirmasi|lunas)|\bRp\s*[\d.,]+/iu', $text);
    }

    public function validate(string $text): void
    {
        if ($this->unsafe($text)) {
            throw ValidationException::withMessages(['content' => 'Konten mengandung instruksi, kredensial, klaim transaksi, atau nominal harga. Gunakan informasi publik; harga dikelola melalui katalog.']);
        }
    }

    public function clean(string $text, int $limit = 1400): string
    {
        $text = strip_tags($text);
        $text = preg_replace('/(?<=[\pL\pN])(?=https?:\/\/)/iu', ' ', $text);
        $text = preg_replace_callback('~https?://[^\s<>"\x00-\x20]+~iu', function ($match) {
            $url = rtrim($match[0], '.,!?)];}*');
            $suffix = substr($match[0], strlen($url));

            return ($this->publicUrl($url) ?? '[tautan tidak tersedia untuk jawaban otomatis]').$suffix;
        }, $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        $text = trim($text);
        $prefix = Str::limit($text, $limit, '');
        if ($prefix !== $text) {
            preg_match_all('~https?://[^\s<>"\x00-\x20]+~iu', $text, $urls, PREG_OFFSET_CAPTURE);
            foreach ($urls[0] as [$url, $offset]) {
                if ($offset < strlen($prefix) && $offset + strlen($url) > strlen($prefix)) {
                    $prefix = rtrim(substr($text, 0, $offset));
                    break;
                }
            }

            return $prefix.'...';
        }

        return $text;
    }

    /** Validate a public marketing URL locally; never fetch a URL supplied by knowledge. */
    public function publicUrl(string $url): ?string
    {
        $base = parse_url((string) config('app.url'));
        $parts = parse_url($url);
        if (! is_array($base) || ! is_array($parts) || ! filter_var($url, FILTER_VALIDATE_URL)
            || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || ($parts['scheme'] ?? '') !== ($base['scheme'] ?? '')
            || isset($parts['user']) || isset($parts['pass']) || empty($parts['host']) || empty($base['host'])) {
            return null;
        }
        $host = fn ($value) => preg_replace('/^www\./', '', strtolower($value));
        $defaultPort = ($base['scheme'] ?? '') === 'https' ? 443 : 80;
        if ($host($parts['host']) !== $host($base['host']) || ($parts['port'] ?? $defaultPort) !== ($base['port'] ?? $defaultPort)) {
            return null;
        }
        $path = $parts['path'] ?? '/';
        $decoded = rawurldecode(rawurldecode($path));
        if (str_contains($decoded, '..') || str_contains($decoded, '\\') || preg_match('/[\x00-\x20\x7F]/', $decoded)) {
            return null;
        }
        parse_str($parts['query'] ?? '', $query);
        $allowedKeys = ['group', 'type', 'search', 'category', 'service_group', 'service_id', 'branch_id', 'page', 'start_date', 'end_date'];
        if (array_diff(array_keys($query), $allowedKeys) || array_filter($query, fn ($value) => ! is_string($value) || mb_strlen($value) > 150)
            || (isset($parts['fragment']) && ! preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $parts['fragment']))) {
            return null;
        }
        foreach ($query as $value) {
            if (preg_match('~https?://|/(?:portal|access|akses)/|\b(?:token|secret|password|api[_-]?key)\s*[:=]|\bsk-[a-z0-9_-]{12,}~iu', rawurldecode($value))) {
                return null;
            }
        }
        try {
            $route = Route::getRoutes()->match(Request::create(rtrim((string) config('app.url'), '/').$path, 'GET'));
        } catch (\Throwable) {
            return null;
        }

        return in_array($route->getName(), ['landing.show', 'landing.booking-page', 'catalogue.index', 'rental.index', 'rental.show', 'rental.cart.index', 'laundry.index'], true) ? $url : null;
    }
}
