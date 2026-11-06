<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AppSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AiProviderCredentialsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
    }

    private function save(string $provider, string $model, string $key = '')
    {
        return $this->post(route('admin.settings.ai.update'), ['provider' => $provider, 'model' => $model, 'api_key' => $key, 'temperature' => 0.3, 'max_tokens' => 800]);
    }

    public function test_keys_stay_encrypted_and_are_restored_for_each_provider(): void
    {
        $settings = app(AppSettingService::class);
        $this->save('openai', 'gpt-6-luna', 'sk-proj-private-openai-test')->assertSessionHasNoErrors();
        $this->save('openrouter', 'openai/gpt-6-luna', 'sk-or-v1-private-openrouter-test')->assertSessionHasNoErrors();
        foreach (['openai' => 'sk-proj-private-openai-test', 'openrouter' => 'sk-or-v1-private-openrouter-test'] as $provider => $key) {
            $stored = AppSetting::where('key', 'ai.providers.'.$provider.'.api_key')->value('value');
            $this->assertNotSame($key, $stored);
            $this->assertSame($key, Crypt::decryptString($stored));
            $this->assertSame($key, $settings->aiApiKey($provider));
        }
        $this->save('openai', 'gpt-6-luna')->assertSessionHasNoErrors();
        $this->assertSame('sk-proj-private-openai-test', config('ai.api_key'));
        $this->save('openrouter', 'openai/gpt-6-luna')->assertSessionHasNoErrors();
        $this->assertSame('sk-or-v1-private-openrouter-test', config('ai.api_key'));
        $this->get(route('admin.settings.index'))->assertOk()->assertDontSee('sk-proj-private-openai-test')->assertDontSee('sk-or-v1-private-openrouter-test')->assertSee('data-ai-provider-settings', false);
        Http::assertNothingSent();
    }

    public function test_switching_to_an_unconfigured_provider_never_borrows_another_key(): void
    {
        $this->save('openrouter', 'openai/gpt-6-luna', 'sk-or-v1-test-one')->assertSessionHasNoErrors();
        $this->save('openai', 'gpt-6-luna')->assertSessionHasNoErrors();
        $this->assertSame('', config('ai.api_key'));
        $this->postJson(route('admin.settings.ai.test'))->assertUnprocessable()->assertJsonPath('code', 'missing_api_key');
        $this->save('openrouter', 'openai/gpt-6-luna')->assertSessionHasNoErrors();
        $this->assertSame('sk-or-v1-test-one', config('ai.api_key'));
        $this->save('local', 'local-deterministic')->assertSessionHasNoErrors();
        $this->assertSame('', config('ai.api_key'));
        $this->assertSame('sk-or-v1-test-one', app(AppSettingService::class)->aiApiKey('openrouter'));
        Http::assertNothingSent();
    }

    public function test_wrong_provider_key_is_rejected_before_save_or_api_request(): void
    {
        $this->save('openai', 'gpt-6-luna', 'sk-proj-working-key')->assertSessionHasNoErrors();
        $this->save('openai', 'gpt-6-luna', 'sk-or-v1-wrong-provider')->assertSessionHasErrors('api_key');
        $this->assertSame('sk-proj-working-key', app(AppSettingService::class)->aiApiKey('openai'));
        $this->assertArrayNotHasKey('api_key', session()->getOldInput());
        $this->postJson(route('admin.settings.ai.test'), ['provider' => 'openai', 'model' => 'gpt-6-luna', 'api_key' => 'sk-or-v1-wrong-provider'])->assertUnprocessable()->assertJsonPath('code', 'provider_key_mismatch')->assertDontSee('sk-or-v1-wrong-provider');
        Http::assertNothingSent();
    }

    public function test_test_connection_uses_unsaved_form_values_without_persisting_them(): void
    {
        $settings = app(AppSettingService::class);
        $this->save('openrouter', 'openai/gpt-6-luna', 'sk-or-v1-original')->assertSessionHasNoErrors();
        $before = AppSetting::orderBy('key')->pluck('value', 'key')->all();
        Http::fake(['https://api.openai.com/v1/models/gpt-6-luna' => Http::response(['id' => 'gpt-6-luna'], 200)]);
        $this->postJson(route('admin.settings.ai.test'), ['provider' => 'openai', 'model' => 'gpt-6-luna', 'api_key' => 'sk-proj-unsaved-key'])->assertOk()->assertJsonPath('ok', true)->assertJsonPath('provider', 'openai')->assertJsonPath('model', 'gpt-6-luna')->assertDontSee('sk-proj-unsaved-key');
        Http::assertSent(fn ($r) => $r->url() === 'https://api.openai.com/v1/models/gpt-6-luna' && $r->hasHeader('Authorization', 'Bearer sk-proj-unsaved-key'));
        $this->assertSame($before, AppSetting::orderBy('key')->pluck('value', 'key')->all());
        $this->assertSame('openrouter', $settings->get('ai.provider'));
        $this->assertSame('', $settings->aiApiKey('openai'));
    }

    public function test_provider_error_and_transport_errors_never_reveal_secrets_in_response_or_audit(): void
    {
        $key = 'sk-proj-never-expose-this';
        Http::fake(['https://api.openai.com/*' => Http::response(['error' => ['message' => 'Incorrect API key provided: '.$key, 'code' => 'invalid_api_key']], 401)]);
        $this->postJson(route('admin.settings.ai.test'), ['provider' => 'openai', 'model' => 'gpt-6-luna', 'api_key' => $key])->assertUnprocessable()->assertJsonPath('code', 'invalid_api_key')->assertDontSee($key)->assertDontSee('Incorrect API key');
        $this->assertStringNotContainsString($key, AuditLog::get()->toJson());
        Http::fake(fn () => throw new ConnectionException('Request URL included secret='.$key));
        $this->postJson(route('admin.settings.ai.test'), ['provider' => 'openai', 'model' => 'gpt-6-luna', 'api_key' => $key])->assertUnprocessable()->assertJsonPath('code', 'network_error')->assertDontSee($key);
        $this->assertStringNotContainsString($key, AuditLog::get()->toJson());
    }

    public static function failures(): array
    {
        return [
            [429, ['code' => 'insufficient_quota'], 'insufficient_quota'],
            [429, ['code' => 'rate_limit_exceeded'], 'rate_limited'],
            [403, ['code' => 'access_denied'], 'access_denied'],
            [404, ['code' => 'model_not_found'], 'model_not_found'],
        ];
    }

    #[DataProvider('failures')]
    public function test_failure_categories_are_distinct(int $status, array $error, string $code): void
    {
        Http::fake(['https://api.openai.com/*' => Http::response(['error' => $error], $status)]);
        $this->postJson(route('admin.settings.ai.test'), ['provider' => 'openai', 'model' => 'gpt-6-luna', 'api_key' => 'sk-proj-valid-format'])->assertUnprocessable()->assertJsonPath('code', $code);
    }

    public function test_legacy_mismatched_key_and_scoped_mismatched_key_are_both_fail_closed(): void
    {
        $settings = app(AppSettingService::class);
        $settings->setMany(['ai.provider' => 'openai', 'ai.api_key' => 'sk-or-v1-legacy-wrong']);
        $settings->applyToConfig();
        $this->assertSame('', config('ai.api_key'));
        $settings->set('ai.providers.openai.api_key', 'sk-or-v1-scoped-wrong');
        $settings->applyToConfig();
        $this->assertSame('', config('ai.api_key'));
        $this->postJson(route('admin.settings.ai.test'))->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_unknown_model_cannot_echo_sensitive_input_in_json_or_audit(): void
    {
        $secret = 'sk-proj-secret-in-wrong-field';
        $this->postJson(route('admin.settings.ai.test'), ['provider' => 'openai', 'model' => $secret, 'api_key' => $secret])->assertUnprocessable()->assertJsonPath('code', 'model_provider_mismatch')->assertJsonPath('model', 'invalid')->assertDontSee($secret);
        $this->assertStringNotContainsString($secret, AuditLog::get()->toJson());
        Http::assertNothingSent();
    }
}
