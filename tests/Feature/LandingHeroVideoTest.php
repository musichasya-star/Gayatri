<?php

namespace Tests\Feature;

use App\Models\LandingPageSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingHeroVideoTest extends TestCase
{
    use RefreshDatabase;

    public function test_video_hero_keeps_text_and_destinations_available_before_video_loads(): void
    {
        $html = $this->get('/')->assertOk()->assertSee('Sentuhan hangat untuk Bunda dan si kecil.')
            ->assertSee('Temukan layanan')->assertSee(route('catalogue.index'))
            ->assertSee('spa-desktop-v2.mp4')->assertSee('spa-mobile-v1.mp4')
            ->assertSee('spa-mobile-poster-v1.webp')->assertSee('spa-desktop-poster-v2.webp')
            ->assertSee('muted loop playsinline preload="none"', false)
            ->assertSee('data-motion-toggle')->getContent();
        $this->assertSame(1, substr_count($html, '<video '));
        $this->assertSame(1, substr_count($html, '<h1 '));
        $this->assertSame(1, substr_count($html, 'id="home"'));
        foreach (config('gayatri_frontend.hero_video') as $asset) {
            $this->assertFileExists(public_path(ltrim($asset, '/')));
        }
    }

    public function test_admin_can_switch_background_mode_and_retains_custom_headline(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        $payload = [
            'business_name' => 'Gayatri', 'city' => 'Kediri', 'address' => 'Jl. Semeru 260',
            'whatsapp' => '08111223344', 'headline' => 'Judul pilihan Bunda',
            'intro' => 'Pendampingan untuk keluarga.', 'hero_background' => 'image',
        ];
        $this->put(route('admin.landing.content.update'), $payload)->assertSessionHasNoErrors();
        $this->get('/')->assertOk()->assertSee('Judul pilihan Bunda')
            ->assertSee('hero-natural-v2.webp')->assertDontSee('data-hero-motion');
        $this->put(route('admin.landing.content.update'), array_replace($payload, ['hero_background' => 'video']))->assertSessionHasNoErrors();
        $this->get('/')->assertOk()->assertSee('Judul pilihan Bunda')->assertSee('data-hero-motion');
        $this->get(route('admin.landing.index'))->assertOk()->assertSee('Latar pembuka')->assertSee('Video suasana spa');
        $this->put(route('admin.landing.content.update'), array_replace($payload, ['hero_background' => 'unknown']))->assertSessionHasErrors('hero_background');
        $this->assertSame('video', LandingPageSetting::where('slug', 'home')->firstOrFail()->sections()->where('type', 'hero')->firstOrFail()->content['background_mode']);
    }
}
