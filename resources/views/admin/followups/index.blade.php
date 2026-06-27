@extends('layouts.app')

@section('title', 'Follow-Up - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="Follow-Up" title="Follow-Up Customer" description="Kelola task follow-up lead, customer lama, dan reminder sales/admin.">
        <x-slot name="actions">
            <a class="button button-primary" href="{{ route('admin.followups.create') }}"><i data-lucide="plus"></i> Buat Follow-Up</a>
        </x-slot>
    </x-ui.page-header>

    @if(session('status'))<div class="card" style="margin-bottom:1rem;border-color:rgba(143,174,139,.55);"><div class="card-body" style="color:#3f6d3d;">{{ session('status') }}</div></div>@endif
    @if($errors->any())<div class="card" style="margin-bottom:1rem;border-color:rgba(201,122,106,.55);"><div class="card-body" style="color:#7a3228;">{{ $errors->first() }}</div></div>@endif

    <div class="card" style="margin-bottom:1rem;">
        <div class="card-body">
            <form method="GET" class="grid grid-4">
                <input class="input" name="q" placeholder="Cari customer, WA, judul, catatan" value="{{ request('q') }}">
                <select class="input" name="status"><option value="">Semua status</option>@foreach(['open','sent','completed'] as $status)<option value="{{ $status }}" @selected(request('status')===$status)>{{ $status }}</option>@endforeach</select>
                <select class="input" name="priority"><option value="">Semua priority</option>@foreach(['normal','high'] as $priority)<option value="{{ $priority }}" @selected(request('priority')===$priority)>{{ $priority }}</option>@endforeach</select>
                <button class="button button-secondary" type="submit"><i data-lucide="search"></i> Filter</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <table class="table-card">
                <thead><tr><th>Customer</th><th>Title</th><th>Due</th><th>Priority</th><th>Status</th><th>Assigned</th><th>Aksi</th></tr></thead>
                <tbody>
                    @forelse($followups as $followup)
                        <tr>
                            <td data-label="Customer"><strong>{{ $followup->customer?->name ?: '-' }}</strong><br><span class="muted">{{ $followup->customer?->whatsapp_number ?: '-' }}</span></td>
                            <td data-label="Title"><strong>{{ $followup->title }}</strong><p class="muted">{{ Str::limit($followup->notes ?: '-', 90) }}</p></td>
                            <td data-label="Due">{{ $followup->due_at?->format('d M Y H:i') ?: '-' }}</td>
                            <td data-label="Priority"><span class="badge {{ $followup->priority === 'high' ? 'badge-red' : 'badge-brown' }}">{{ $followup->priority }}</span></td>
                            <td data-label="Status"><span class="badge {{ $followup->status === 'completed' ? 'badge-green' : ($followup->status === 'sent' ? 'badge-gold' : 'badge-brown') }}">{{ $followup->status }}</span></td>
                            <td data-label="Assigned">{{ $followup->assignedUser?->name ?: '-' }}</td>
                            <td data-label="Aksi">
                                <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
                                    <a class="button button-secondary" href="{{ route('admin.followups.show', $followup) }}">Detail</a>
                                    <a class="button button-secondary" href="{{ route('admin.followups.edit', $followup) }}">Edit</a>
                                    @if($followup->status !== 'completed')
                                        <form method="POST" action="{{ route('admin.followups.send', $followup) }}">@csrf<button class="button button-secondary" type="submit">Kirim WA</button></form>
                                        <form method="POST" action="{{ route('admin.followups.complete', $followup) }}">@csrf<input type="hidden" name="result" value="Selesai dari list follow-up."><button class="button button-primary" type="submit">Complete</button></form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" style="text-align:center;padding:2rem;">Belum ada follow-up.</td></tr>
                    @endforelse
                </tbody>
            </table>

            <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-top:1rem;">
                <p class="muted" style="margin:0;">Menampilkan {{ $followups->firstItem() ?? 0 }}-{{ $followups->lastItem() ?? 0 }} dari {{ $followups->total() }} follow-up.</p>
                <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
                    @if($followups->onFirstPage())<span class="button button-secondary" style="opacity:.45;pointer-events:none;">Sebelumnya</span>@else<a class="button button-secondary" href="{{ $followups->previousPageUrl() }}">Sebelumnya</a>@endif
                    <span class="badge badge-brown">Halaman {{ $followups->currentPage() }} / {{ $followups->lastPage() }}</span>
                    @if($followups->hasMorePages())<a class="button button-secondary" href="{{ $followups->nextPageUrl() }}">Berikutnya</a>@else<span class="button button-secondary" style="opacity:.45;pointer-events:none;">Berikutnya</span>@endif
                </div>
            </div>
        </div>
    </div>
@endsection
