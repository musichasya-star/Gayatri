<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AppSettingService;
use App\Services\CRM\AuditLogService;
use App\Services\WhatsApp\WahaService;
use Illuminate\Http\Client\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class SettingController extends Controller
{
    private const AI_PROVIDERS = ['local', 'openrouter', 'gemini', 'openai', 'deepseek'];

    private const AI_MODELS = [
        'local' => ['local-deterministic'],
        'openrouter' => ['openai/gpt-4o-mini', 'anthropic/claude-3.5-sonnet', 'google/gemini-2.0-flash-001', 'deepseek/deepseek-chat'],
        'gemini' => ['gemini-1.5-flash', 'gemini-1.5-pro', 'gemini-2.0-flash'],
        'openai' => ['gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini'],
        'deepseek' => ['deepseek-chat', 'deepseek-reasoner'],
    ];

    public function __construct(
        private readonly AppSettingService $settings,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function index(): View
    {
        return view('admin.settings.index', [
            'aiProviders' => self::AI_PROVIDERS,
            'aiModels' => self::AI_MODELS,
            'settings' => $this->currentSettings(),
        ]);
    }

    public function updateAi(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(self::AI_PROVIDERS)],
            'model' => ['required', 'string', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'temperature' => ['required', 'numeric', 'min:0', 'max:2'],
            'max_tokens' => ['required', 'integer', 'min:100', 'max:8000'],
        ]);

        if (! in_array($data['model'], self::AI_MODELS[$data['provider']] ?? [], true)) {
            return back()->withErrors(['model' => 'Model AI tidak sesuai dengan provider yang dipilih.'])->withInput();
        }

        $this->settings->setMany([
            'ai.provider' => $data['provider'],
            'ai.model' => $data['model'],
            'ai.temperature' => $data['temperature'],
            'ai.max_tokens' => $data['max_tokens'],
        ]);

        if (filled($data['api_key'] ?? null)) {
            $this->settings->set('ai.api_key', $data['api_key']);
        }

        $this->settings->applyToConfig();
        $this->auditLogService->log($request->user(), 'settings.ai.update', null, $request, [], [
            'provider' => $data['provider'],
            'model' => $data['model'],
            'temperature' => $data['temperature'],
            'max_tokens' => $data['max_tokens'],
            'api_key_changed' => filled($data['api_key'] ?? null),
        ], 'AI settings updated');

        return back()->with('status', 'Setting AI berhasil disimpan.');
    }

    public function updateWaha(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'base_url' => ['required', 'url', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'default_session' => ['required', 'string', 'max:100'],
            'webhook_secret' => ['nullable', 'string', 'max:255'],
            'timeout' => ['required', 'integer', 'min:3', 'max:120'],
        ]);

        $this->settings->setMany([
            'waha.base_url' => rtrim($data['base_url'], '/'),
            'waha.default_session' => $data['default_session'],
            'waha.timeout' => $data['timeout'],
        ]);

        if (filled($data['api_key'] ?? null)) {
            $this->settings->set('waha.api_key', $data['api_key']);
        }

        if (filled($data['webhook_secret'] ?? null)) {
            $this->settings->set('waha.webhook_secret', $data['webhook_secret']);
        }

        $this->settings->applyToConfig();
        $this->auditLogService->log($request->user(), 'settings.waha.update', null, $request, [], [
            'base_url' => rtrim($data['base_url'], '/'),
            'default_session' => $data['default_session'],
            'timeout' => $data['timeout'],
            'api_key_changed' => filled($data['api_key'] ?? null),
            'webhook_secret_changed' => filled($data['webhook_secret'] ?? null),
        ], 'WAHA settings updated');

        return back()->with('status', 'Setting WAHA berhasil disimpan.');
    }

    public function testWaha(WahaService $wahaService): RedirectResponse
    {
        $this->settings->applyToConfig();

        try {
            $sessions = $wahaService->listSessions();
        } catch (Throwable $exception) {
            $this->auditLogService->log(request()->user(), 'settings.waha.test_failed', null, request(), [], ['error' => $exception->getMessage()], 'WAHA connection test failed');

            return back()->withErrors(['waha' => 'Koneksi WAHA gagal: '.$exception->getMessage()]);
        }

        $this->auditLogService->log(request()->user(), 'settings.waha.test_success', null, request(), [], ['sessions' => count($sessions)], 'WAHA connection test success');

        return back()->with('status', 'Koneksi WAHA berhasil. Sessions terdeteksi: '.count($sessions).'.');
    }

    public function testAi(Request $request): RedirectResponse
    {
        $this->settings->applyToConfig();

        $provider = (string) config('ai.provider', 'local');
        $model = (string) config('ai.model', 'local-deterministic');
        $apiKey = (string) config('ai.api_key', '');

        if ($provider === 'local') {
            $this->auditLogService->log($request->user(), 'settings.ai.test_success', null, $request, [], [
                'provider' => $provider,
                'model' => $model,
            ], 'AI connection test success');

            return back()->with('status', 'Koneksi AI local berhasil. Provider local tidak membutuhkan API key.');
        }

        if (blank($apiKey)) {
            $this->auditLogService->log($request->user(), 'settings.ai.test_failed', null, $request, [], [
                'provider' => $provider,
                'model' => $model,
                'error' => 'missing_api_key',
            ], 'AI connection test failed');

            return back()->withErrors(['ai' => 'Koneksi AI gagal: API key belum disimpan untuk provider '.$provider.'.']);
        }

        try {
            $response = $this->testAiProvider($provider, $model, $apiKey);
        } catch (Throwable $exception) {
            $this->auditLogService->log($request->user(), 'settings.ai.test_failed', null, $request, [], [
                'provider' => $provider,
                'model' => $model,
                'error' => $exception->getMessage(),
            ], 'AI connection test failed');

            return back()->withErrors(['ai' => 'Koneksi AI gagal: '.$exception->getMessage()]);
        }

        if (! $response->successful()) {
            $message = $response->json('error.message') ?: $response->json('message') ?: 'HTTP '.$response->status();
            $this->auditLogService->log($request->user(), 'settings.ai.test_failed', null, $request, [], [
                'provider' => $provider,
                'model' => $model,
                'status' => $response->status(),
                'error' => $message,
            ], 'AI connection test failed');

            return back()->withErrors(['ai' => 'Koneksi AI gagal: '.$message]);
        }

        $this->auditLogService->log($request->user(), 'settings.ai.test_success', null, $request, [], [
            'provider' => $provider,
            'model' => $model,
            'status' => $response->status(),
        ], 'AI connection test success');

        return back()->with('status', 'Koneksi AI berhasil untuk provider '.ucfirst($provider).' model '.$model.'.');
    }

    private function testAiProvider(string $provider, string $model, string $apiKey): Response
    {
        $timeout = 15;

        return match ($provider) {
            'openrouter' => Http::timeout($timeout)->acceptJson()->withToken($apiKey)->get('https://openrouter.ai/api/v1/auth/key'),
            'openai' => Http::timeout($timeout)->acceptJson()->withToken($apiKey)->get('https://api.openai.com/v1/models/'.$model),
            'deepseek' => Http::timeout($timeout)->acceptJson()->withToken($apiKey)->get('https://api.deepseek.com/models'),
            'gemini' => Http::timeout($timeout)->acceptJson()->get('https://generativelanguage.googleapis.com/v1beta/models', ['key' => $apiKey]),
            default => throw new \InvalidArgumentException('Provider AI tidak didukung.'),
        };
    }

    private function currentSettings(): array
    {
        return [
            'ai.provider' => $this->settings->get('ai.provider', config('ai.provider', 'local')) ?: 'local',
            'ai.model' => $this->settings->get('ai.model', config('ai.model', 'local-deterministic')) ?: 'local-deterministic',
            'ai.api_key_set' => filled($this->settings->get('ai.api_key', config('ai.api_key'))),
            'ai.temperature' => $this->settings->get('ai.temperature', config('ai.temperature', 0.3)),
            'ai.max_tokens' => $this->settings->get('ai.max_tokens', config('ai.max_tokens', 800)),
            'waha.base_url' => $this->settings->get('waha.base_url', config('waha.base_url', 'http://localhost:3000')),
            'waha.api_key_set' => filled($this->settings->get('waha.api_key', config('waha.api_key'))),
            'waha.default_session' => $this->settings->get('waha.default_session', config('waha.default_session', 'default')),
            'waha.webhook_secret_set' => filled($this->settings->get('waha.webhook_secret', config('waha.webhook_secret'))),
            'waha.timeout' => $this->settings->get('waha.timeout', config('waha.timeout', 30)),
        ];
    }
}
