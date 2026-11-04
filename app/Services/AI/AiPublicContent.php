<?php

namespace App\Services\AI;

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
        $text = preg_replace('/https?:\/\/\S+/iu', '[tautan tersedia melalui menu layanan]', $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        return Str::limit(trim($text), $limit);
    }
}
