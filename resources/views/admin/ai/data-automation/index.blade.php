@extends('layouts.app')

@section('title', 'AI Data Automation - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="AI Management" title="AI Data Automation" description="Pantau rule, data terekstrak, pending approval, dan log automation AI Gayatri.">
        <x-slot name="actions"><a class="button button-primary" href="{{ route('admin.ai.data-automation.rules.create') }}"><i data-lucide="plus"></i> Buat Rule</a></x-slot>
    </x-ui.page-header>

    <section class="grid grid-4">
        <x-ui.stat-card icon="workflow" label="Rule Aktif" value="{{ $activeRules }}" note="Automation rule aktif" />
        <x-ui.stat-card icon="database-zap" label="Data Terekstrak" value="{{ $extractedCount }}" note="Total hasil ekstraksi" />
        <x-ui.stat-card icon="clipboard-check" label="Pending Approval" value="{{ $pendingApprovals }}" note="Butuh review admin" />
        <x-ui.stat-card icon="shield-alert" label="Guardrail Hit" value="{{ $guardrailHits }}" note="Medis, refund, komplain" />
    </section>

    <section class="grid grid-4" style="margin-top:1rem;">
        <a class="card" href="{{ route('admin.ai.data-automation.rules.index') }}"><div class="card-body"><h2 class="section-title">Automation Rules</h2><p class="muted">Kelola trigger, mode, threshold, dan target action.</p></div></a>
        <a class="card" href="{{ route('admin.ai.data-automation.extracted.index') }}"><div class="card-body"><h2 class="section-title">Extracted Data</h2><p class="muted">Review intent, confidence, missing field, customer dan booking data.</p></div></a>
        <a class="card" href="{{ route('admin.ai.data-automation.approvals.index') }}"><div class="card-body"><h2 class="section-title">Pending Approval</h2><p class="muted">Approve atau reject data yang perlu konfirmasi manusia.</p></div></a>
        <a class="card" href="{{ route('admin.ai.data-automation.logs.index') }}"><div class="card-body"><h2 class="section-title">Automation Logs</h2><p class="muted">Lihat input, output, error, dan status automation.</p></div></a>
        <a class="card" href="{{ route('admin.ai.data-automation.test') }}"><div class="card-body"><h2 class="section-title">Test Automation</h2><p class="muted">Uji extraction dan rule matching tanpa menyimpan data final.</p></div></a>
    </section>

    <section class="grid grid-2" style="margin-top:1rem;">
        <div class="card"><div class="card-body"><h2 class="section-title">Approval Terbaru</h2><table class="table-card"><thead><tr><th>Customer</th><th>Action</th><th>Status</th></tr></thead><tbody>@forelse($latestApprovals as $approval)<tr><td data-label="Customer">{{ $approval->customer?->name ?: '-' }}</td><td data-label="Action">{{ $approval->action }}</td><td data-label="Status"><span class="badge badge-gold">{{ $approval->status }}</span></td></tr>@empty<tr><td colspan="3" style="text-align:center;padding:1.5rem;">Belum ada approval.</td></tr>@endforelse</tbody></table></div></div>
        <div class="card"><div class="card-body"><h2 class="section-title">Automation Log Terbaru</h2><table class="table-card"><thead><tr><th>Status</th><th>Target</th><th>Action</th></tr></thead><tbody>@forelse($latestLogs as $log)<tr><td data-label="Status"><span class="badge badge-brown">{{ $log->status }}</span></td><td data-label="Target">{{ $log->target_entity ?: '-' }}</td><td data-label="Action">{{ $log->action ?: '-' }}</td></tr>@empty<tr><td colspan="3" style="text-align:center;padding:1.5rem;">Belum ada log.</td></tr>@endforelse</tbody></table></div></div>
    </section>
@endsection
