@extends('layouts.app')

@section('title', 'Automation Rules - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="AI Data Automation" title="Automation Rules" description="Atur kapan AI boleh membuat data otomatis, membuat approval, atau hanya eskalasi ke manusia.">
        <x-slot name="actions"><a class="button button-primary" href="{{ route('admin.ai.data-automation.rules.create') }}"><i data-lucide="plus"></i> Tambah Rule</a></x-slot>
    </x-ui.page-header>
    @if(session('status'))<div class="card" style="margin-bottom:1rem;border-color:rgba(143,174,139,.55);"><div class="card-body" style="color:#3f6d3d;">{{ session('status') }}</div></div>@endif
    <div class="card"><div class="card-body"><table class="table-card"><thead><tr><th>Rule</th><th>Trigger</th><th>Target</th><th>Mode</th><th>Threshold</th><th>Status</th><th>Aksi</th></tr></thead><tbody>@forelse($rules as $rule)<tr><td data-label="Rule">{{ $rule->name }}</td><td data-label="Trigger">{{ $rule->trigger_event }}</td><td data-label="Target">{{ $rule->target_entity }} / {{ $rule->action }}</td><td data-label="Mode"><span class="badge badge-brown">{{ $rule->mode }}</span></td><td data-label="Threshold">{{ number_format((float)$rule->confidence_threshold * 100, 0) }}%</td><td data-label="Status"><span class="badge {{ $rule->is_active ? 'badge-green' : 'badge-red' }}">{{ $rule->is_active ? 'Aktif' : 'Nonaktif' }}</span></td><td data-label="Aksi"><div style="display:flex;gap:.4rem;flex-wrap:wrap;"><a class="button button-secondary" href="{{ route('admin.ai.data-automation.rules.edit', $rule) }}">Edit</a><form method="POST" action="{{ route('admin.ai.data-automation.rules.toggle', $rule) }}">@csrf<button class="button button-ghost" type="submit">Toggle</button></form></div></td></tr>@empty<tr><td colspan="7" style="text-align:center;padding:2rem;">Belum ada automation rule.</td></tr>@endforelse</tbody></table><div style="margin-top:1rem;">{{ $rules->links() }}</div></div></div>
@endsection
