<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\RequestException;
use Throwable;

final class DeliveryFailure
{
    public static function fromException(Throwable $error): array
    {
        $status = $error instanceof RequestException ? $error->response->status() : null;
        $known = in_array($status, [400, 401, 403, 404, 409, 413, 422, 429], true);

        return ['status' => $known ? 'failed' : 'unknown', 'code' => $status ? 'http_'.$status : 'transport_unknown', 'reason' => match ($status) {
            401, 403 => 'Gateway menolak autentikasi. Periksa API key dan hak akses.',
            400, 413, 422 => 'Gateway menolak isi pesan atau tujuan. Periksa nomor dan panjang pesan.',
            404 => 'Endpoint atau sesi gateway tidak ditemukan. Periksa konfigurasi.',
            409 => 'Sesi WhatsApp belum siap. Periksa koneksi dan pindai QR bila diperlukan.',
            429 => 'Batas pengiriman gateway tercapai. Tunggu sebelum mencoba ulang.',
            default => 'Hasil pengiriman belum pasti: koneksi terputus, timeout, atau respons gateway tidak lengkap. Periksa WhatsApp sebelum mencoba ulang.',
        }];
    }

    public static function safeText(?string $text): string
    {
        if (! $text) {
            return '—';
        }
        foreach (['baileys', 'waha'] as $provider) {
            foreach (['api_key', 'webhook_secret'] as $key) {
                $secret = (string) config($provider.'.'.$key);
                if ($secret !== '') {
                    $text = str_replace($secret, '[RAHASIA]', $text);
                }
            }
        }
        $text = preg_replace('~https?://[^\s<>"\']+~i', '[URL disembunyikan]', $text);
        $text = preg_replace('/(?:bearer\s+|(?:api[_-]?key|token|secret|password)\s*[:=]\s*)[^\s,;]+/i', '[RAHASIA]', $text);

        return mb_substr(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $text), 0, 350);
    }
}
