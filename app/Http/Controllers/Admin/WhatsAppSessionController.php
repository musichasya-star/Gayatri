<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppSession;
use App\Services\WhatsApp\WahaService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class WhatsAppSessionController extends Controller
{
    public function __construct(private readonly WahaService $wahaService) {}

    public function index(): View
    {
        $sessionName = (string) config('waha.default_session');
        $remoteSession = null;
        $qr = null;
        $error = null;

        try {
            $remoteSession = $this->ensureGatewaySession($sessionName);

            if ($this->shouldShowQr($remoteSession)) {
                $qr = $this->wahaService->getQr($sessionName);
            }
        } catch (RequestException $exception) {
            $error = $exception->getMessage();
        }

        $localSession = WhatsAppSession::firstWhere('session_name', $sessionName);

        return view('admin.whatsapp.session', [
            'sessionName' => $sessionName,
            'remoteSession' => $remoteSession,
            'localSession' => $localSession,
            'qr' => $qr,
            'error' => $error,
        ]);
    }

    public function start(Request $request): RedirectResponse
    {
        $sessionName = (string) $request->input('session_name', config('waha.default_session'));

        try {
            $this->ensureGatewaySession($sessionName);
            $this->wahaService->updateSession($sessionName, $this->sessionConfig());
            $this->wahaService->startSession($sessionName);
        } catch (Throwable $exception) {
            return redirect()->route('admin.whatsapp.session')->withErrors(['waha' => 'Gagal start session WAHA: '.$exception->getMessage()]);
        }

        return redirect()->route('admin.whatsapp.session')->with('status', 'Session WAHA berhasil dijalankan.');
    }

    public function stop(Request $request): RedirectResponse
    {
        $sessionName = (string) $request->input('session_name', config('waha.default_session'));

        try {
            $this->wahaService->stopSession($sessionName);
        } catch (Throwable $exception) {
            return redirect()->route('admin.whatsapp.session')->withErrors(['waha' => 'Gagal stop session WAHA: '.$exception->getMessage()]);
        }

        return redirect()->route('admin.whatsapp.session')->with('status', 'Session WAHA berhasil dihentikan.');
    }

    public function logout(Request $request): RedirectResponse
    {
        $sessionName = (string) $request->input('session_name', config('waha.default_session'));

        try {
            $this->wahaService->logoutSession($sessionName);
        } catch (Throwable $exception) {
            return redirect()->route('admin.whatsapp.session')->withErrors(['waha' => 'Gagal logout session WAHA: '.$exception->getMessage()]);
        }

        return redirect()->route('admin.whatsapp.session')->with('status', 'Session WAHA berhasil logout.');
    }

    public function restart(Request $request): RedirectResponse
    {
        $sessionName = (string) $request->input('session_name', config('waha.default_session'));

        try {
            $this->wahaService->updateSession($sessionName, $this->sessionConfig());
            $this->wahaService->restartSession($sessionName);
        } catch (Throwable $exception) {
            return redirect()->route('admin.whatsapp.session')->withErrors(['waha' => 'Gagal restart session WAHA: '.$exception->getMessage()]);
        }

        return redirect()->route('admin.whatsapp.session')->with('status', 'Session WAHA berhasil direstart.');
    }

    private function ensureGatewaySession(string $sessionName): array
    {
        try {
            return $this->wahaService->getSession($sessionName);
        } catch (RequestException $exception) {
            if ($exception->response?->status() !== 404) {
                throw $exception;
            }
        }

        $this->wahaService->createSession($sessionName, $this->sessionConfig());

        return $this->wahaService->getSession($sessionName);
    }

    private function sessionConfig(): array
    {
        return [
            'webhooks' => [
                [
                    'url' => $this->webhookUrl('webhooks.waha.messages'),
                    'events' => ['message', 'message.any', 'message.ack'],
                    'customHeaders' => $this->webhookHeaders(),
                ],
                [
                    'url' => $this->webhookUrl('webhooks.waha.status'),
                    'events' => ['session.status'],
                    'customHeaders' => $this->webhookHeaders(),
                ],
            ],
        ];
    }

    private function shouldShowQr(?array $remoteSession): bool
    {
        $status = (string) data_get($remoteSession, 'status', '');

        return in_array($status, ['SCAN_QR_CODE', 'STARTING'], true)
            || ($status !== 'WORKING' && blank(data_get($remoteSession, 'me.id')));
    }

    private function webhookUrl(string $routeName): string
    {
        $url = route($routeName);
        $baseUrl = trim((string) config('waha.webhook_base_url'));

        if ($baseUrl !== '') {
            return rtrim($baseUrl, '/').parse_url($url, PHP_URL_PATH);
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (in_array($host, ['localhost', '127.0.0.1'], true)) {
            return str_replace('://'.$host, '://host.docker.internal', $url);
        }

        return $url;
    }

    private function webhookHeaders(): array
    {
        $headers = [];

        if (filled(config('waha.webhook_secret'))) {
            $headers[] = ['name' => 'X-Webhook-Secret', 'value' => (string) config('waha.webhook_secret')];
        }

        if (filled(config('waha.api_key'))) {
            $headers[] = ['name' => 'X-Api-Key', 'value' => (string) config('waha.api_key')];
        }

        return $headers;
    }
}
