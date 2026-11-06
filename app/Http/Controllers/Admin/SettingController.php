<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AI\AiConnectionProbe;
use App\Services\AppSettingService;
use App\Services\CRM\AuditLogService;
use App\Services\WhatsApp\GatewayConfig;
use App\Services\WhatsApp\WahaService;
use App\Services\WhatsApp\WhatsAppGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class SettingController extends Controller
{
    private const AI_PROVIDERS = ['local', 'openrouter', 'gemini', 'openai', 'deepseek'];

    private const AI_MODELS = [
        'local' => ['local-deterministic'],
        'openrouter' => ['openai/gpt-4o-mini', 'anthropic/claude-3.5-sonnet', 'google/gemini-2.0-flash-001', 'deepseek/deepseek-chat', 'openai/gpt-6-luna'],
        'gemini' => ['gemini-1.5-flash', 'gemini-1.5-pro', 'gemini-2.0-flash'],
        'openai' => ['gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini', 'gpt-6-luna'],
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
            'aiProviderSettings' => collect(self::AI_PROVIDERS)->mapWithKeys(fn ($provider) => [$provider => ['api_key_set' => filled($this->settings->aiApiKey($provider)), 'model' => $this->settings->get('ai.providers.'.$provider.'.model', $provider === config('ai.provider') ? config('ai.model') : self::AI_MODELS[$provider][0])]])->all(),
            'gatewayProvider' => GatewayConfig::provider(),
            'gatewaySettings' => collect(['baileys', 'waha'])->mapWithKeys(fn ($provider) => [$provider => [
                'base_url' => config($provider.'.base_url'),
                'default_session' => config($provider.'.default_session'),
                'webhook_base_url' => config($provider.'.webhook_base_url'),
                'timeout' => config($provider.'.timeout', 30),
                'api_key_set' => filled(config($provider.'.api_key')),
                'webhook_secret_set' => filled(config($provider.'.webhook_secret')),
            ]])->all(),
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
            return back()->withErrors(['model' => 'Model AI tidak sesuai dengan provider yang dipilih.'])->withInput($request->except(['api_key', 'webhook_secret']));
        }

        $key = filled($data['api_key'] ?? null) ? trim($data['api_key']) : $this->settings->aiApiKey($data['provider']);
        if (filled($key) && ($mismatch = $this->settings->aiKeyMismatch($data['provider'], $key))) {
            return back()->withErrors(['api_key' => $mismatch])->withInput($request->only(['provider', 'model', 'temperature', 'max_tokens']));
        }
        DB::transaction(function () use ($data, $key) {
            $previous = (string) config('ai.provider', 'local');
            $previousKey = $this->settings->aiApiKey($previous);
            if ($previous !== 'local' && filled($previousKey)) {
                $this->settings->set('ai.providers.'.$previous.'.api_key', $previousKey);
            }
            if ($data['provider'] !== 'local') {
                $this->settings->set('ai.providers.'.$data['provider'].'.api_key', $key);
            }
            $this->settings->setMany([
                'ai.provider' => $data['provider'], 'ai.model' => $data['model'],
                'ai.temperature' => $data['temperature'], 'ai.max_tokens' => $data['max_tokens'],
                'ai.providers.'.$data['provider'].'.model' => $data['model'],
                'ai.api_key' => $key, 'ai.api_key_provider' => $data['provider'],
            ]);
        });

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
        $request->merge(['gateway_provider' => 'waha']);

        return $this->updateWhatsapp($request);
    }

    public function updateWhatsapp(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'gateway_provider' => ['required', Rule::in(['baileys', 'waha'])],
            'base_url' => ['required', 'url', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'default_session' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'webhook_base_url' => ['nullable', 'url', 'max:255'],
            'webhook_secret' => ['nullable', 'string', 'max:255'],
            'timeout' => ['required', 'integer', 'min:3', 'max:120'],
        ]);

        $provider = $data['gateway_provider'];
        if ($provider === 'baileys' && (blank($data['api_key'] ?? null) && blank($this->settings->get('baileys.api_key', config('baileys.api_key')))
            || blank($data['webhook_secret'] ?? null) && blank($this->settings->get('baileys.webhook_secret', config('baileys.webhook_secret'))))) {
            return back()->withErrors(['whatsapp' => 'API key dan webhook secret Baileys wajib dikonfigurasi.']);
        }

        $this->settings->setMany([
            'whatsapp.provider' => $provider,
            $provider.'.base_url' => rtrim($data['base_url'], '/'),
            $provider.'.default_session' => $data['default_session'],
            $provider.'.timeout' => $data['timeout'],
        ]);

        $webhookBaseUrl = trim((string) ($data['webhook_base_url'] ?? ''));
        $this->settings->set($provider.'.webhook_base_url', $webhookBaseUrl === '' ? null : rtrim($webhookBaseUrl, '/'));

        if (filled($data['api_key'] ?? null)) {
            $this->settings->set($provider.'.api_key', $data['api_key']);
        }

        if (filled($data['webhook_secret'] ?? null)) {
            $this->settings->set($provider.'.webhook_secret', $data['webhook_secret']);
        }

        $this->settings->applyToConfig();
        $this->auditLogService->log($request->user(), 'settings.'.$provider.'.update', null, $request, [], [
            'base_url' => rtrim($data['base_url'], '/'),
            'default_session' => $data['default_session'],
            'timeout' => $data['timeout'],
            'webhook_base_url_set' => $webhookBaseUrl !== '',
            'api_key_changed' => filled($data['api_key'] ?? null),
            'webhook_secret_changed' => filled($data['webhook_secret'] ?? null),
        ], GatewayConfig::label().' settings updated');

        return back()->with('status', 'Setting '.GatewayConfig::label().' berhasil disimpan. Restart queue worker setelah mengganti gateway.');
    }

    public function testWhatsapp(): RedirectResponse
    {
        $this->settings->applyToConfig();

        return $this->testGateway(app(WhatsAppGateway::class), GatewayConfig::provider());
    }

    public function testWaha(WahaService $wahaService): RedirectResponse
    {
        $this->settings->applyToConfig();

        return $this->testGateway($wahaService, 'waha');
    }

    private function testGateway(WhatsAppGateway $wahaService, string $provider): RedirectResponse
    {
        $label = $provider === 'baileys' ? 'Baileys' : 'WAHA';

        try {
            $sessions = $wahaService->listSessions();
        } catch (Throwable $exception) {
            $this->auditLogService->log(request()->user(), 'settings.'.$provider.'.test_failed', null, request(), [], ['error' => $exception->getMessage()], $label.' connection test failed');

            return back()->withErrors([$provider => 'Koneksi '.$label.' gagal: '.$exception->getMessage()]);
        }

        $this->auditLogService->log(request()->user(), 'settings.'.$provider.'.test_success', null, request(), [], ['sessions' => count($sessions)], $label.' connection test success');

        return back()->with('status', 'Koneksi '.$label.' berhasil. Sessions terdeteksi: '.count($sessions).'.');
    }

    public function testAi(Request $request, AiConnectionProbe $probe): RedirectResponse|JsonResponse
    {
        $this->settings->applyToConfig();
        $data = $request->validate([
            'provider' => ['sometimes', 'required', Rule::in(self::AI_PROVIDERS)],
            'model' => ['sometimes', 'required', 'string', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:500'],
        ]);
        $provider = $data['provider'] ?? (string) config('ai.provider', 'local');
        $model = $data['model'] ?? (string) config('ai.model', 'local-deterministic');
        if (! in_array($model, self::AI_MODELS[$provider], true)) {
            $result = ['ok' => false, 'code' => 'model_provider_mismatch', 'message' => 'Model AI tidak sesuai dengan provider yang dipilih.', 'http_status' => null];
        } else {
            $key = filled($data['api_key'] ?? null) ? trim($data['api_key']) : $this->settings->aiApiKey($provider);
            $result = $probe->inspect($provider, $model, $key);
        }
        $safeModel = in_array($model, self::AI_MODELS[$provider], true) ? $model : 'invalid';
        $this->auditLogService->log($request->user(), $result['ok'] ? 'settings.ai.test_success' : 'settings.ai.test_failed', null, $request, [], ['provider' => $provider, 'model' => $safeModel, 'status' => $result['http_status'], 'error' => $result['ok'] ? null : $result['code']], 'AI connection test; credentials and provider error bodies omitted');
        if ($request->expectsJson()) {
            return response()->json($result + ['provider' => $provider, 'model' => $safeModel], $result['ok'] ? 200 : 422);
        }

        return $result['ok'] ? back()->with('status', $result['message']) : back()->withErrors(['ai' => $result['message']]);
    }

    private function currentSettings(): array
    {
        return [
            'ai.provider' => $this->settings->get('ai.provider', config('ai.provider', 'local')) ?: 'local',
            'ai.model' => $this->settings->get('ai.model', config('ai.model', 'local-deterministic')) ?: 'local-deterministic',
            'ai.api_key_set' => filled($this->settings->aiApiKey((string) config('ai.provider', 'local'))),
            'ai.temperature' => $this->settings->get('ai.temperature', config('ai.temperature', 0.3)),
            'ai.max_tokens' => $this->settings->get('ai.max_tokens', config('ai.max_tokens', 800)),
            'waha.base_url' => $this->settings->get('waha.base_url', config('waha.base_url', 'http://localhost:3000')),
            'waha.api_key_set' => filled($this->settings->get('waha.api_key', config('waha.api_key'))),
            'waha.default_session' => $this->settings->get('waha.default_session', config('waha.default_session', 'default')),
            'waha.webhook_base_url' => $this->settings->get('waha.webhook_base_url', config('waha.webhook_base_url')),
            'waha.webhook_secret_set' => filled($this->settings->get('waha.webhook_secret', config('waha.webhook_secret'))),
            'waha.timeout' => $this->settings->get('waha.timeout', config('waha.timeout', 30)),
        ];
    }
}
