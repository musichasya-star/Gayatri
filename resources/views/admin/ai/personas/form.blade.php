@extends('layouts.app')

@section('title', ($persona->exists ? 'Edit Persona' : 'Tambah Persona') . ' - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="AI Management" title="{{ $persona->exists ? 'Edit Persona' : 'Tambah Persona' }}" description="Pastikan prompt membatasi AI agar hanya menjawab layanan Gayatri.">
        <x-slot name="actions"><a class="button button-secondary" href="{{ route('admin.ai.personas.index') }}"><i data-lucide="arrow-left"></i> Kembali</a></x-slot>
    </x-ui.page-header>

    @if ($errors->any())
        <div class="card" style="margin-bottom: 1rem; border-color: rgba(201,122,106,.55);"><div class="card-body" style="color: #7a3228;">{{ $errors->first() }}</div></div>
    @endif

    <div class="card"><div class="card-body">
        <form method="POST" action="{{ $persona->exists ? route('admin.ai.personas.update', $persona) : route('admin.ai.personas.store') }}" class="grid grid-2">
            @csrf
            @if ($persona->exists) @method('PUT') @endif
            <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Nama</span><input class="input" name="name" value="{{ old('name', $persona->name) }}" required></label>
            <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Tone</span><input class="input" name="tone" value="{{ old('tone', $persona->tone) }}" placeholder="warm-professional"></label>
            <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Bahasa</span><input class="input" name="language" value="{{ old('language', $persona->settings['language'] ?? 'id') }}"></label>
            <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Fallback Style</span><input class="input" name="fallback_style" value="{{ old('fallback_style', $persona->settings['fallback_style'] ?? 'human-handoff') }}"></label>
            <label style="grid-column:1/-1;"><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Prompt</span><textarea class="input" name="prompt" rows="9" required>{{ old('prompt', $persona->prompt) }}</textarea></label>
            <label style="grid-column:1/-1;display:flex;align-items:center;gap:.5rem;"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $persona->is_active))> Jadikan persona aktif</label>
            <div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:.75rem;"><a class="button button-secondary" href="{{ route('admin.ai.personas.index') }}">Batal</a><button class="button button-primary" type="submit"><i data-lucide="save"></i> Simpan</button></div>
        </form>
    </div></div>
@endsection
