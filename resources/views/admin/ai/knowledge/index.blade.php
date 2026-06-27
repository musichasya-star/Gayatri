@extends('layouts.app')

@section('title', 'Knowledge Base AI - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="AI Management" title="Knowledge Base" description="Sumber informasi resmi yang boleh digunakan AI Gayatri.">
        <x-slot name="actions"><a class="button button-primary" href="{{ route('admin.ai.knowledge.create') }}"><i data-lucide="plus"></i> Tambah Knowledge</a></x-slot>
    </x-ui.page-header>

    @if (session('status'))<div class="card" style="margin-bottom:1rem;border-color:rgba(143,174,139,.55);"><div class="card-body" style="color:#3f6d3d;">{{ session('status') }}</div></div>@endif

    <div class="card" style="margin-bottom:1rem;"><div class="card-body">
        <form method="GET" class="grid grid-3">
            <input class="input" type="search" name="search" value="{{ request('search') }}" placeholder="Cari knowledge">
            <select class="input" name="status"><option value="">Semua status</option><option value="active" @selected(request('status')==='active')>Active</option><option value="inactive" @selected(request('status')==='inactive')>Inactive</option></select>
            <button class="button button-secondary" type="submit"><i data-lucide="search"></i> Filter</button>
        </form>
    </div></div>

    <div class="card"><div class="card-body">
        <table class="table-card">
            <thead><tr><th>Judul</th><th>Tipe</th><th>Status</th><th>Valid</th><th>Chunks</th><th>Aksi</th></tr></thead>
            <tbody>
                @forelse ($knowledgeBases as $knowledge)
                    <tr>
                        <td data-label="Judul">{{ $knowledge->title }}</td>
                        <td data-label="Tipe">{{ $knowledge->type }}</td>
                        <td data-label="Status"><span class="badge {{ $knowledge->status === 'active' ? 'badge-green' : 'badge-red' }}">{{ ucfirst($knowledge->status) }}</span></td>
                        <td data-label="Valid">{{ $knowledge->valid_from?->format('d M Y') ?: '-' }} - {{ $knowledge->valid_until?->format('d M Y') ?: 'seterusnya' }}</td>
                        <td data-label="Chunks">{{ $knowledge->chunks_count }}</td>
                        <td data-label="Aksi"><div style="display:flex;gap:.4rem;flex-wrap:wrap;"><a class="button button-secondary" href="{{ route('admin.ai.knowledge.edit', $knowledge) }}">Edit</a><form method="POST" action="{{ route('admin.ai.knowledge.toggle', $knowledge) }}">@csrf<button class="button button-ghost" type="submit">Toggle</button></form></div></td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="text-align:center;padding:2rem;">Belum ada knowledge base.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div style="margin-top:1rem;">{{ $knowledgeBases->links() }}</div>
    </div></div>
@endsection
