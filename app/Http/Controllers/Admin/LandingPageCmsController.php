<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\LandingPageController;
use App\Models\LandingMedia;
use App\Models\LandingPageSetting;
use App\Models\LandingSection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LandingPageCmsController extends Controller
{
    public function index(): View
    {
        $page = $this->page()->load(['sections', 'media' => fn ($query) => $query->latest()]);

        return view('admin.landing.index', compact('page'));
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'site_name' => ['required', 'string', 'max:150'],
            'page_settings' => ['nullable', 'array'],
            'seo_settings' => ['nullable', 'array'],
            'theme_settings' => ['nullable', 'array'],
        ]);

        $this->page()->update($data);

        return back()->with('status', 'Page, SEO, dan theme settings berhasil disimpan.');
    }

    public function storeSection(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:150'],
            'subtitle' => ['nullable', 'string', 'max:200'],
            'icon' => ['nullable', 'string', 'max:80'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ]);

        $this->page()->sections()->create($data + ['is_active' => true, 'content' => [], 'items' => []]);

        return back()->with('status', 'Section baru berhasil ditambahkan.');
    }

    public function updateSection(Request $request, LandingSection $section): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'subtitle' => ['nullable', 'string', 'max:200'],
            'icon' => ['nullable', 'string', 'max:80'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'content' => ['nullable', 'array'],
            'items' => ['nullable', 'array'],
        ]);

        $section->update($data + ['is_active' => false]);

        return back()->with('status', 'Section berhasil diperbarui.');
    }

    public function destroySection(LandingSection $section): RedirectResponse
    {
        $section->delete();

        return back()->with('status', 'Section berhasil dihapus.');
    }

    public function storeMedia(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', 'string', 'max:40'],
            'url' => ['required', 'string', 'max:1000'],
            'alt_text' => ['nullable', 'string', 'max:200'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $this->page()->media()->create($data + ['is_active' => false]);

        return back()->with('status', 'Media berhasil ditambahkan.');
    }

    public function destroyMedia(LandingMedia $media): RedirectResponse
    {
        $media->delete();

        return back()->with('status', 'Media berhasil dihapus.');
    }

    public function publish(): RedirectResponse
    {
        $this->page()->update(['is_published' => true, 'published_at' => now()]);

        return back()->with('status', 'Landing page berhasil dipublish.');
    }

    public function preview(Request $request, LandingPageController $landingPageController): View
    {
        $device = $request->string('device', 'desktop')->toString();

        return view('admin.landing.preview', [
            'device' => in_array($device, ['desktop', 'tablet', 'mobile'], true) ? $device : 'desktop',
            'landing' => $landingPageController->renderLanding(true),
        ]);
    }

    private function page(): LandingPageSetting
    {
        return LandingPageSetting::query()->firstOrCreate(
            ['slug' => 'home'],
            ['site_name' => 'Gayatri Mom & Baby SPA', 'is_published' => false]
        );
    }
}
