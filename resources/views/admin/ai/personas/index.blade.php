@extends('layouts.app')

@section('title', 'AI Persona - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="AI Management" title="AI Persona" description="Atur karakter, tone, dan prompt utama AI Gayatri.">
        <x-slot name="actions">
            <a class="button button-primary" href="{{ route('admin.ai.personas.create') }}"><i data-lucide="plus"></i> Tambah Persona</a>
        </x-slot>
    </x-ui.page-header>

    @if (session('status'))
        <div class="card" style="margin-bottom: 1rem; border-color: rgba(143,174,139,.55);"><div class="card-body" style="color: #3f6d3d;">{{ session('status') }}</div></div>
    @endif

    <div class="card">
        <div class="card-body">
            <table class="table-card">
                <thead><tr><th>Nama</th><th>Tone</th><th>Status</th><th>Prompt</th><th>Aksi</th></tr></thead>
                <tbody>
                    @forelse ($personas as $persona)
                        <tr>
                            <td data-label="Nama">{{ $persona->name }}</td>
                            <td data-label="Tone">{{ $persona->tone ?: '-' }}</td>
                            <td data-label="Status"><span class="badge {{ $persona->is_active ? 'badge-green' : 'badge-brown' }}">{{ $persona->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                            <td data-label="Prompt">{{ Str::limit($persona->prompt, 90) }}</td>
                            <td data-label="Aksi">
                                <div style="display: flex; gap: .4rem; flex-wrap: wrap;">
                                    <a class="button button-secondary" href="{{ route('admin.ai.personas.edit', $persona) }}">Edit</a>
                                    @unless ($persona->is_active)
                                        <form method="POST" action="{{ route('admin.ai.personas.activate', $persona) }}">@csrf<button class="button button-ghost" type="submit">Aktifkan</button></form>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" style="text-align: center; padding: 2rem;">Belum ada persona AI.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div style="margin-top: 1rem;">{{ $personas->links() }}</div>
        </div>
    </div>
@endsection
