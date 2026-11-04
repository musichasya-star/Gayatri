<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

class GatewayHealth
{
    public function inspect(bool $fresh = false): array
    {
        $provider = GatewayConfig::provider();
        $key = 'whatsapp:health:'.hash('sha256', $provider.'|'.GatewayConfig::get('base_url').'|'.GatewayConfig::get('api_key'));
        try {
            if ($fresh) {
                Cache::forget($key);
            }

            return Cache::remember($key, 15, fn () => $this->probe($provider));
        } catch (Throwable) {
            return $this->probe($provider);
        }
    }

    private function probe(string $provider): array
    {
        $result = ['reachable' => false, 'checked_at' => now()->toDateTimeString(), 'provider' => GatewayConfig::label(), 'sessions' => [], 'error' => null, 'webhooks_pending' => null, 'webhooks_attempts' => null];
        try {
            $response = Http::baseUrl(rtrim((string) GatewayConfig::get('base_url'), '/'))
                ->withHeaders(['X-Api-Key' => (string) GatewayConfig::get('api_key')])->withOptions(['allow_redirects' => false])->connectTimeout(2)->timeout(4)->acceptJson()
                ->get($provider === 'baileys' ? '/health' : '/api/sessions')->throw();
            $body = $response->json();
            if (! is_array($body) || ($provider === 'baileys' && data_get($body, 'ok') !== true)) {
                throw new \RuntimeException('Invalid gateway health response.');
            }
            $sessions = $provider === 'baileys' ? ($body['sessions'] ?? []) : $body;
            if (! is_array($sessions)) {
                throw new \RuntimeException('Invalid sessions response.');
            }
            $result['reachable'] = true;
            foreach ($sessions as $session) {
                if (! is_array($session) || ! is_string($session['name'] ?? null)) {
                    continue;
                }
                $result['sessions'][] = ['name' => mb_substr($session['name'], 0, 100), 'status' => mb_substr(strtoupper((string) ($session['status'] ?? 'UNKNOWN')), 0, 40)];
            }
            if ($provider === 'baileys') {
                $result['webhooks_pending'] = max(0, (int) data_get($body, 'webhooks.pending', 0));
                $result['webhooks_attempts'] = max(0, (int) data_get($body, 'webhooks.max_attempts', 0));
            }
        } catch (Throwable $error) {
            $failure = DeliveryFailure::fromException($error);
            $result['error'] = $failure['status'] === 'failed' ? $failure['reason'] : 'Pemeriksaan gateway gagal: layanan mati, jaringan terputus, timeout, atau respons kesehatan tidak valid. Status sesi belum dapat dipastikan.';
        }

        return $result;
    }

    public function requireWorking(string $session): void
    {
        $health = $this->inspect(true);
        if (! $health['reachable'] || ! collect($health['sessions'])->contains(fn ($row) => $row['name'] === $session && in_array($row['status'], ['WORKING', 'CONNECTED'], true))) {
            throw ValidationException::withMessages(['retry' => 'Koneksi sesi WhatsApp belum siap. Periksa gateway sebelum meminta kirim ulang.']);
        }
    }
}
