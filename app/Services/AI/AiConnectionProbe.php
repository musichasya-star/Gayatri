<?php

namespace App\Services\AI;

use App\Services\AppSettingService;
use Illuminate\Support\Facades\Http;
use Throwable;

class AiConnectionProbe
{
    /** All messages and audit codes are local constants; provider error bodies stay private. */
    public function inspect(string $provider, string $model, string $key): array
    {
        if ($provider === 'local') {
            return $this->result(true, 'local', 'Koneksi AI local berhasil. Provider local tidak membutuhkan API key.');
        }
        if (blank($key)) {
            return $this->result(false, 'missing_api_key', 'API key belum disimpan untuk provider '.ucfirst($provider).'. Isi kunci provider tersebut atau pilih provider yang sudah dikonfigurasi.');
        }
        if ($mismatch = app(AppSettingService::class)->aiKeyMismatch($provider, $key)) {
            return $this->result(false, 'provider_key_mismatch', $mismatch);
        }
        try {
            $request = Http::timeout(15)->connectTimeout(5)->acceptJson()->withoutRedirecting();
            $response = match ($provider) {
                'openai' => $request->withToken($key)->get('https://api.openai.com/v1/models/'.rawurlencode($model)),
                'openrouter' => $request->withToken($key)->get('https://openrouter.ai/api/v1/auth/key'),
                'deepseek' => $request->withToken($key)->get('https://api.deepseek.com/models'),
                'gemini' => $request->get('https://generativelanguage.googleapis.com/v1beta/models', ['key' => $key]),
                default => null,
            };
            if (! $response) {
                return $this->result(false, 'unsupported_provider', 'Provider AI tidak didukung.');
            }
            $status = $response->status();
            if ($response->successful()) {
                return $this->result(true, 'connected', 'Koneksi AI berhasil untuk provider '.ucfirst($provider).' model '.$model.'.', $status);
            }
            $quota = preg_match('/quota|billing|credit|balance/i', (string) $response->json('error.code').' '.(string) $response->json('error.message').' '.(string) $response->json('message')) === 1;
            [$code, $message] = match (true) {
                $status === 401 => ['invalid_api_key', 'API key ditolak oleh '.ucfirst($provider).'. Pastikan kunci berasal dari provider tersebut dan masih aktif.'],
                $status === 402 || ($status === 429 && $quota) => ['insufficient_quota', 'Saldo atau kuota API '.ucfirst($provider).' tidak mencukupi. Periksa billing dan batas penggunaan akun.'],
                $status === 403 => ['access_denied', 'API key tidak memiliki izin untuk model atau project yang dipilih. Periksa akses pada akun provider.'],
                $status === 404 => ['model_not_found', 'Model tidak ditemukan atau belum tersedia untuk akun provider ini.'],
                $status === 429 => ['rate_limited', 'Batas permintaan API tercapai. Tunggu sebentar lalu coba lagi.'],
                $status >= 500 => ['provider_unavailable', 'Layanan provider sedang bermasalah. Coba kembali beberapa saat lagi.'],
                default => ['request_rejected', 'Provider menolak permintaan uji. Periksa provider, model, dan izin akun.'],
            };

            return $this->result(false, $code, 'Koneksi AI gagal: '.$message, $status);
        } catch (Throwable) {
            return $this->result(false, 'network_error', 'Koneksi AI gagal: provider tidak dapat dihubungi atau permintaan melewati batas waktu. Coba kembali.');
        }
    }

    private function result(bool $ok, string $code, string $message, ?int $status = null): array
    {
        return ['ok' => $ok, 'code' => $code, 'message' => $message, 'http_status' => $status];
    }
}
