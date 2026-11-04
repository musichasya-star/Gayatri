<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppSession;
use App\Services\WhatsApp\GatewayConfig;
use App\Services\WhatsApp\WhatsAppGateway;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class WhatsAppSessionController extends Controller
{
    public function __construct(private readonly WhatsAppGateway $wahaService) {}

    public function index(): View
    {
        $sessionName = (string) GatewayConfig::get('default_session');
        $remoteSession = null;
        $qr = null;
        $error = null;

        try {
            $remoteSession = $this->findSession($this->wahaService->listSessions(), $sessionName);
            if ($remoteSession === null) {
                $error = 'Sesi belum terdaftar. Gunakan Mulai sesi setelah konfigurasi gateway diperiksa.';
            }

            if ($remoteSession !== null && $this->shouldShowQr($remoteSession)) {
                try {
                    $qr = $this->wahaService->getQr($sessionName);
                } catch (RequestException $exception) {
                    // A socket needs a moment to obtain its first QR from WhatsApp.
                    if (GatewayConfig::provider() !== 'baileys' || $exception->response->status() !== 409) {
                        throw $exception;
                    }
                }
            }
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }

        $localSession = WhatsAppSession::firstWhere('session_name', $sessionName);

        return view('admin.whatsapp.session', [
            'sessionName' => $sessionName,
            'remoteSession' => $remoteSession,
            'localSession' => $localSession,
            'qr' => $qr,
            'error' => $error,
            'gatewayLabel' => GatewayConfig::label(),
            'webhookRoutePrefix' => GatewayConfig::provider() === 'baileys' ? 'webhooks.whatsapp' : 'webhooks.waha',
        ]);
    }

    public function start(Request $request): RedirectResponse
    {
        $sessionName = (string) $request->input('session_name', GatewayConfig::get('default_session'));

        try {
            $this->ensureGatewaySession($sessionName);
            $this->wahaService->updateSession($sessionName, $this->sessionConfig());
            $this->wahaService->startSession($sessionName);
        } catch (Throwable $exception) {
            return redirect()->route('admin.whatsapp.session')->withErrors(['whatsapp' => 'Gagal start session '.GatewayConfig::label().': '.$exception->getMessage()]);
        }

        return redirect()->route('admin.whatsapp.session')->with('status', 'Session '.GatewayConfig::label().' berhasil dijalankan.');
    }

    public function stop(Request $request): RedirectResponse
    {
        $sessionName = (string) $request->input('session_name', GatewayConfig::get('default_session'));

        try {
            $this->wahaService->stopSession($sessionName);
        } catch (Throwable $exception) {
            return redirect()->route('admin.whatsapp.session')->withErrors(['whatsapp' => 'Gagal stop session '.GatewayConfig::label().': '.$exception->getMessage()]);
        }

        return redirect()->route('admin.whatsapp.session')->with('status', 'Session '.GatewayConfig::label().' berhasil dihentikan.');
    }

    public function logout(Request $request): RedirectResponse
    {
        $sessionName = (string) $request->input('session_name', GatewayConfig::get('default_session'));

        try {
            $this->wahaService->logoutSession($sessionName);
        } catch (Throwable $exception) {
            return redirect()->route('admin.whatsapp.session')->withErrors(['whatsapp' => 'Gagal logout session '.GatewayConfig::label().': '.$exception->getMessage()]);
        }

        return redirect()->route('admin.whatsapp.session')->with('status', 'Session '.GatewayConfig::label().' berhasil logout.');
    }

    public function restart(Request $request): RedirectResponse
    {
        $sessionName = (string) $request->input('session_name', GatewayConfig::get('default_session'));

        try {
            $this->wahaService->updateSession($sessionName, $this->sessionConfig());
            $this->wahaService->restartSession($sessionName);
        } catch (Throwable $exception) {
            return redirect()->route('admin.whatsapp.session')->withErrors(['whatsapp' => 'Gagal restart session '.GatewayConfig::label().': '.$exception->getMessage()]);
        }

        return redirect()->route('admin.whatsapp.session')->with('status', 'Session '.GatewayConfig::label().' berhasil direstart.');
    }

    private function ensureGatewaySession(string $sessionName): array
    {
        $sessions = $this->wahaService->listSessions();
        $listedSession = $this->findSession($sessions, $sessionName);

        if ($listedSession !== null) {
            return $listedSession;
        }

        return $this->wahaService->createSession($sessionName, $this->sessionConfig());
    }

    private function findSession(array $sessions, string $sessionName): ?array
    {
        foreach ($sessions as $session) {
            if (is_array($session) && (string) ($session['name'] ?? '') === $sessionName) {
                return $session;
            }
        }

        return null;
    }

    private function sessionConfig(): array
    {
        $prefix = GatewayConfig::provider() === 'baileys' ? 'webhooks.whatsapp' : 'webhooks.waha';

        return [
            'webhooks' => [
                [
                    'url' => $this->webhookUrl($prefix.'.messages'),
                    'events' => ['message', 'message.any', 'message.ack'],
                    'customHeaders' => $this->webhookHeaders(),
                ],
                [
                    'url' => $this->webhookUrl($prefix.'.status'),
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
        $baseUrl = trim((string) GatewayConfig::get('webhook_base_url'));

        if ($baseUrl !== '') {
            // Keep subdirectory installations (for example /Gayatri/public) intact.
            $routePath = '/webhooks/'.(GatewayConfig::provider() === 'baileys' ? 'whatsapp' : 'waha').'/'.(str_ends_with($routeName, '.status') ? 'status' : 'messages');

            return rtrim($baseUrl, '/').$routePath;
        }

        $wahaHost = parse_url((string) GatewayConfig::get('base_url'), PHP_URL_HOST);
        $host = parse_url($url, PHP_URL_HOST);

        if (GatewayConfig::provider() === 'waha' && in_array((string) $wahaHost, ['localhost', '127.0.0.1'], true)
            && in_array((string) $host, ['localhost', '127.0.0.1'], true)) {
            return str_replace('://'.$host, '://host.docker.internal', $url);
        }

        return $url;
    }

    private function webhookHeaders(): array
    {
        $headers = [];

        if (filled(GatewayConfig::get('webhook_secret'))) {
            $headers[] = ['name' => 'X-Webhook-Secret', 'value' => (string) GatewayConfig::get('webhook_secret')];
        }

        if (filled(GatewayConfig::get('api_key'))) {
            $headers[] = ['name' => 'X-Api-Key', 'value' => (string) GatewayConfig::get('api_key')];
        }

        return $headers;
    }
}
