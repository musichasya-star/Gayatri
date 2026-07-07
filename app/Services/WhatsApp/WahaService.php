<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class WahaService
{
    public function listSessions(): array
    {
        return $this->request()->get('/api/sessions')->throw()->json() ?? [];
    }

    public function createSession(string $name, array $config = []): array
    {
        return $this->request()->post('/api/sessions', [
            'name' => $name,
            'config' => $config,
        ])->throw()->json();
    }

    public function updateSession(string $name, array $config = []): array
    {
        return $this->request()->put("/api/sessions/{$name}", [
            'config' => $config,
        ])->throw()->json();
    }

    public function getSession(string $name): array
    {
        return $this->request()->get("/api/sessions/{$name}")->throw()->json();
    }

    public function startSession(string $name): array
    {
        return $this->request()->post("/api/sessions/{$name}/start")->throw()->json();
    }

    public function stopSession(string $name): array
    {
        return $this->request()->post("/api/sessions/{$name}/stop")->throw()->json();
    }

    public function restartSession(string $name): array
    {
        return $this->request()->post("/api/sessions/{$name}/restart")->throw()->json();
    }

    public function logoutSession(string $name): array
    {
        return $this->request()->post('/api/sessions/logout', [
            'name' => $name,
        ])->throw()->json();
    }

    public function getQr(string $session): array
    {
        $response = $this->request()
            ->get("/api/{$session}/auth/qr", ['format' => 'image'])
            ->throw();

        $contentType = (string) $response->header('Content-Type', '');

        if (str_contains($contentType, 'application/json')) {
            return $response->json() ?? [];
        }

        return [
            'mimetype' => str_contains($contentType, 'image/') ? $contentType : 'image/png',
            'data' => base64_encode($response->body()),
        ];
    }

    public function getMe(string $session): array
    {
        return $this->request()->get("/api/sessions/{$session}/me")->throw()->json();
    }

    public function sendText(string $session, string $chatId, string $text): array
    {
        $response = $this->request()->post('/api/sendText', [
            'session' => $session,
            'chatId' => $chatId,
            'text' => $text,
        ])->throw();

        return $response->json() ?? [
            'session' => $session,
            'chatId' => $chatId,
            'status' => 'sent',
        ];
    }

    public function startTyping(string $session, string $chatId): void
    {
        $this->request()->post('/api/startTyping', [
            'session' => $session,
            'chatId' => $chatId,
        ])->throw();
    }

    public function stopTyping(string $session, string $chatId): void
    {
        $this->request()->post('/api/stopTyping', [
            'session' => $session,
            'chatId' => $chatId,
        ])->throw();
    }

    public function ensureSession(string $name, array $config = []): array
    {
        try {
            $session = $this->getSession($name);
        } catch (RequestException) {
            return $this->createSession($name, $config);
        }

        if (! empty($config)) {
            return $this->updateSession($name, $config);
        }

        return $session;
    }

    private function request(): PendingRequest
    {
        $request = Http::baseUrl(rtrim((string) config('waha.base_url'), '/'))
            ->timeout((int) config('waha.timeout', 30))
            ->acceptJson();

        if (filled(config('waha.api_key'))) {
            $request = $request->withHeaders([
                'X-Api-Key' => (string) config('waha.api_key'),
            ]);
        }

        return $request;
    }
}
