@extends('layouts.app')

@section('title', 'AI Logs - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="AI Management" title="AI Logs" description="Riwayat prompt, response, confidence, fallback, dan sumber knowledge AI.">
        <x-slot name="actions">
            <form method="POST" action="{{ route('admin.ai.logs.clear') }}" onsubmit="return confirm('Hapus semua AI logs? Aksi ini tidak menghapus conversation, customer, atau booking.');">
                @csrf
                <button class="button button-secondary" type="submit"><i data-lucide="trash-2"></i> Clear Log</button>
            </form>
        </x-slot>
    </x-ui.page-header>
    @if(session('status'))<div class="card" style="margin-bottom:1rem;"><div class="card-body" style="color:#2f6b4f;font-weight:700;">{{ session('status') }}</div></div>@endif
    <div class="card"><div class="card-body">
        <table class="table-card">
            <thead><tr><th>Waktu</th><th>Status</th><th>Persona</th><th>Prompt</th><th>Response</th><th>Confidence</th><th>Sources</th></tr></thead>
            <tbody>
                @forelse($logs as $log)
                    <tr>
                        <td data-label="Waktu">{{ $log->created_at->format('d M Y H:i') }}</td>
                        <td data-label="Status"><span class="badge {{ $log->status === 'success' ? 'badge-green' : 'badge-red' }}">{{ $log->status }}</span></td>
                        <td data-label="Persona">{{ $log->persona?->name ?: '-' }}</td>
                        <td data-label="Prompt">{{ Str::limit($log->prompt, 70) }}</td>
                        <td data-label="Response">{{ Str::limit($log->response, 90) }}</td>
                        <td data-label="Confidence">{{ $log->confidence !== null ? number_format((float) $log->confidence * 100, 0).'%' : '-' }}</td>
                        <td data-label="Sources">{{ implode(', ', $log->sources ?? []) ?: '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="text-align:center;padding:2rem;">Belum ada AI log.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-top:1rem;">
            <p class="muted" style="margin:0;">Menampilkan {{ $logs->firstItem() ?? 0 }}-{{ $logs->lastItem() ?? 0 }} dari {{ $logs->total() }} log.</p>
            <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
                @if($logs->onFirstPage())
                    <span class="button button-secondary" style="opacity:.45;pointer-events:none;">Sebelumnya</span>
                @else
                    <a class="button button-secondary" href="{{ $logs->previousPageUrl() }}">Sebelumnya</a>
                @endif
                <span class="badge badge-brown">Halaman {{ $logs->currentPage() }} / {{ $logs->lastPage() }}</span>
                @if($logs->hasMorePages())
                    <a class="button button-secondary" href="{{ $logs->nextPageUrl() }}">Berikutnya</a>
                @else
                    <span class="button button-secondary" style="opacity:.45;pointer-events:none;">Berikutnya</span>
                @endif
            </div>
        </div>
    </div></div>
@endsection
