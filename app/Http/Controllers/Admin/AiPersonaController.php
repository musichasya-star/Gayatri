<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiPersona;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AiPersonaController extends Controller
{
    public function index(): View
    {
        return view('admin.ai.personas.index', [
            'personas' => AiPersona::latest()->paginate(15),
        ]);
    }

    public function create(): View
    {
        return view('admin.ai.personas.form', [
            'persona' => new AiPersona(['tone' => 'warm-professional', 'is_active' => false]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedData($request);
        $data['slug'] = $this->makeSlug($data['name']);
        $data['settings'] = $this->settingsFromRequest($request);

        DB::transaction(function () use ($data) {
            if ($data['is_active']) {
                AiPersona::query()->update(['is_active' => false]);
            }

            AiPersona::create($data);
        });

        return redirect()->route('admin.ai.personas.index')->with('status', 'Persona AI berhasil dibuat.');
    }

    public function edit(AiPersona $persona): View
    {
        return view('admin.ai.personas.form', ['persona' => $persona]);
    }

    public function update(Request $request, AiPersona $persona): RedirectResponse
    {
        $data = $this->validatedData($request, $persona);
        $data['slug'] = $this->makeSlug($data['name'], $persona->id);
        $data['settings'] = $this->settingsFromRequest($request);

        DB::transaction(function () use ($persona, $data) {
            if ($data['is_active']) {
                AiPersona::query()->whereKeyNot($persona->id)->update(['is_active' => false]);
            }

            $persona->update($data);
        });

        return redirect()->route('admin.ai.personas.index')->with('status', 'Persona AI berhasil diperbarui.');
    }

    public function activate(AiPersona $persona): RedirectResponse
    {
        DB::transaction(function () use ($persona) {
            AiPersona::query()->whereKeyNot($persona->id)->update(['is_active' => false]);
            $persona->update(['is_active' => true]);
        });

        return back()->with('status', 'Persona AI berhasil diaktifkan.');
    }

    private function validatedData(Request $request, ?AiPersona $persona = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'prompt' => ['required', 'string', 'max:12000'],
            'tone' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'language' => ['nullable', 'string', 'max:20'],
            'fallback_style' => ['nullable', 'string', 'max:100'],
        ]) + ['is_active' => false];
    }

    private function settingsFromRequest(Request $request): array
    {
        return [
            'language' => $request->input('language', 'id'),
            'fallback_style' => $request->input('fallback_style', 'human-handoff'),
        ];
    }

    private function makeSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'persona';
        $slug = $base;
        $counter = 2;

        while (AiPersona::where('slug', $slug)->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))->exists()) {
            $slug = $base.'-'.$counter++;
        }

        return $slug;
    }
}
