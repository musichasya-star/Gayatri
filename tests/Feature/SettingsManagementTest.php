<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use App\Services\AppSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SettingsManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_settings_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($admin)
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('AI Provider &amp; WAHA API', false)
            ->assertSee('Openrouter')
            ->assertSee('Deepseek')
            ->assertSee('Test Connection AI')
            ->assertSee('Test Connection');
    }

    public function test_admin_can_save_ai_provider_and_model_settings(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($admin)
            ->post(route('admin.settings.ai.update'), [
                'provider' => 'openrouter',
                'model' => 'deepseek/deepseek-chat',
                'api_key' => 'sk-openrouter-test',
                'temperature' => 0.4,
                'max_tokens' => 1200,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('app_settings', ['key' => 'ai.provider', 'value' => 'openrouter']);
        $this->assertDatabaseHas('app_settings', ['key' => 'ai.model', 'value' => 'deepseek/deepseek-chat']);
        $this->assertNotSame('sk-openrouter-test', AppSetting::where('key', 'ai.api_key')->value('value'));
        $this->assertSame('sk-openrouter-test', app(AppSettingService::class)->get('ai.api_key'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.ai.update']);
    }

    public function test_admin_can_test_local_ai_connection(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        app(AppSettingService::class)->setMany([
            'ai.provider' => 'local',
            'ai.model' => 'local-deterministic',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.settings.ai.test'))
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertNothingSent();
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.ai.test_success']);
    }

    public function test_admin_can_test_openrouter_ai_connection(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Http::fake([
            'https://openrouter.ai/api/v1/auth/key' => Http::response(['data' => ['label' => 'test-key']], 200),
        ]);
        app(AppSettingService::class)->setMany([
            'ai.provider' => 'openrouter',
            'ai.model' => 'deepseek/deepseek-chat',
        ]);
        app(AppSettingService::class)->set('ai.api_key', 'sk-openrouter-test');

        $this->actingAs($admin)
            ->post(route('admin.settings.ai.test'))
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertSent(fn ($request) => $request->url() === 'https://openrouter.ai/api/v1/auth/key'
            && $request->hasHeader('Authorization', 'Bearer sk-openrouter-test'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.ai.test_success']);
    }

    public function test_ai_connection_test_requires_api_key_for_external_provider(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        app(AppSettingService::class)->setMany([
            'ai.provider' => 'openai',
            'ai.model' => 'gpt-4o-mini',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.settings.ai.test'))
            ->assertRedirect()
            ->assertSessionHasErrors('ai');

        Http::assertNothingSent();
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.ai.test_failed']);
    }

    public function test_ai_connection_test_reports_provider_failure(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Http::fake([
            'https://api.openai.com/v1/models/gpt-4o-mini' => Http::response(['error' => ['message' => 'Invalid API key']], 401),
        ]);
        app(AppSettingService::class)->setMany([
            'ai.provider' => 'openai',
            'ai.model' => 'gpt-4o-mini',
        ]);
        app(AppSettingService::class)->set('ai.api_key', 'sk-invalid');

        $this->actingAs($admin)
            ->post(route('admin.settings.ai.test'))
            ->assertRedirect()
            ->assertSessionHasErrors('ai');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/models/gpt-4o-mini');
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.ai.test_failed']);
    }

    public function test_admin_can_save_waha_settings_and_test_connection(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Http::fake([
            'http://waha.test/api/sessions/' => Http::response([['name' => 'default']], 200),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.settings.waha.update'), [
                'base_url' => 'http://waha.test',
                'api_key' => 'waha-secret',
                'default_session' => 'default',
                'webhook_secret' => 'webhook-secret',
                'timeout' => 15,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('app_settings', ['key' => 'waha.base_url', 'value' => 'http://waha.test']);
        $this->assertNotSame('waha-secret', AppSetting::where('key', 'waha.api_key')->value('value'));
        $this->assertSame('waha-secret', app(AppSettingService::class)->get('waha.api_key'));

        $this->actingAs($admin)
            ->post(route('admin.settings.waha.test'))
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertSent(fn ($request) => $request->hasHeader('X-Api-Key', 'waha-secret'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.waha.update']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.waha.test_success']);
    }

    public function test_sales_cannot_access_settings(): void
    {
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active']);

        $this->actingAs($sales)
            ->get(route('admin.settings.index'))
            ->assertForbidden();

        $this->assertSame(0, AppSetting::count());
    }
}
