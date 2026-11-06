<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AI\AiInteractionPolicy;
use App\Services\AI\AiService;
use App\Services\AppSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AiLunaModelTest extends TestCase
{
    use RefreshDatabase;

    public static function lunaProviders(): array
    {
        return [
            'openai' => ['openai', 'gpt-6-luna', 'https://api.openai.com/v1/chat/completions'],
            'openrouter' => ['openrouter', 'openai/gpt-6-luna', 'https://openrouter.ai/api/v1/chat/completions'],
        ];
    }

    #[DataProvider('lunaProviders')]
    public function test_admin_can_choose_luna_without_replacing_the_existing_key(string $provider, string $model, string $url): void
    {
        $settings = app(AppSettingService::class);
        $settings->set('ai.api_key', 'existing-private-test-key');
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        $this->get(route('admin.settings.index'))->assertOk()->assertSee('GPT-6-Luna')->assertSee('value="'.$model.'"', false)->assertDontSee('existing-private-test-key');
        $this->post(route('admin.settings.ai.update'), ['provider' => $provider, 'model' => $model, 'api_key' => '', 'temperature' => 0.3, 'max_tokens' => 800])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($model, $settings->get('ai.model'));
        $this->assertSame($provider, $settings->get('ai.provider'));
        $this->assertSame('existing-private-test-key', $settings->get('ai.api_key'));
        $this->assertFalse(app(AiInteractionPolicy::class)->allowsLegacyAutomation());
        Http::assertNothingSent();
    }

    #[DataProvider('lunaProviders')]
    public function test_luna_transport_uses_supported_token_and_reasoning_parameters(string $provider, string $model, string $url): void
    {
        config(['ai.api_key' => 'test-key', 'ai.max_tokens' => 300, 'ai.temperature' => 0.4]);
        Http::fake([$url => Http::response(['choices' => [['message' => ['content' => 'OK']]]])]);
        // Exercise the isolated transport without reopening the legacy transaction AI path.
        $transport = new \ReflectionMethod(AiService::class, 'chatCompletions');
        $response = $transport->invoke(app(AiService::class), $url, $model, [['role' => 'user', 'content' => 'Balas OK untuk pengujian.']]);
        $this->assertSame('OK', $response['choices'][0]['message']['content']);
        Http::assertSent(fn ($request) => $request->url() === $url && $request['model'] === $model && $request['max_completion_tokens'] === 300 && $request['reasoning_effort'] === 'none' && ! array_key_exists('temperature', $request->data()) && ! array_key_exists('max_tokens', $request->data()));
        $this->assertFalse(app(AiInteractionPolicy::class)->allowsLegacyAutomation());
    }

    public function test_other_models_keep_their_existing_request_parameters(): void
    {
        $url = 'https://api.openai.com/v1/chat/completions';
        config(['ai.api_key' => 'test-key', 'ai.max_tokens' => 800, 'ai.temperature' => 0.3]);
        Http::fake([$url => Http::response(['choices' => [['message' => ['content' => 'OK']]]])]);
        (new \ReflectionMethod(AiService::class, 'chatCompletions'))->invoke(app(AiService::class), $url, 'gpt-4.1-mini', [['role' => 'user', 'content' => 'Uji']]);
        Http::assertSent(fn ($request) => $request['model'] === 'gpt-4.1-mini' && $request['max_tokens'] === 800 && $request['temperature'] === 0.3 && ! array_key_exists('max_completion_tokens', $request->data()) && ! array_key_exists('reasoning_effort', $request->data()));
    }

    public function test_provider_specific_luna_id_cannot_be_saved_for_the_other_provider(): void
    {
        app(AppSettingService::class)->setMany(['ai.provider' => 'openai', 'ai.model' => 'gpt-4.1-mini']);
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        foreach (['openai' => 'openai/gpt-6-luna', 'openrouter' => 'gpt-6-luna'] as $provider => $model) {
            $this->post(route('admin.settings.ai.update'), ['provider' => $provider, 'model' => $model, 'temperature' => 0.3, 'max_tokens' => 800])->assertSessionHasErrors('model');
        }
        $this->assertSame('gpt-4.1-mini', app(AppSettingService::class)->get('ai.model'));
        Http::assertNothingSent();
    }
}
