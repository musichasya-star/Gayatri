@extends('layouts.app')

@section('title', 'Detail Automation Log - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="AI Data Automation" title="Detail Automation Log" description="Audit input, output, dan error automation.">
        <x-slot name="actions"><a class="button button-secondary" href="{{ route('admin.ai.data-automation.logs.index') }}"><i data-lucide="arrow-left"></i> Kembali</a></x-slot>
    </x-ui.page-header>
    <section class="grid grid-2"><div class="card"><div class="card-body"><h2 class="section-title">Ringkasan</h2><ul class="soft-list"><li class="soft-list-item"><span>Status</span><span class="badge badge-gold">{{ $log->status }}</span></li><li class="soft-list-item"><span>Target</span><strong>{{ $log->target_entity ?: '-' }}</strong></li><li class="soft-list-item"><span>Action</span><strong>{{ $log->action ?: '-' }}</strong></li><li class="soft-list-item"><span>Rule</span><strong>{{ $log->rule?->name ?: '-' }}</strong></li></ul></div></div><div class="card"><div class="card-body"><h2 class="section-title">Error</h2><p class="muted">{{ $log->error_message ?: 'Tidak ada error.' }}</p></div></div></section>
    <section class="grid grid-2" style="margin-top:1rem;"><div class="card"><div class="card-body"><h2 class="section-title">Input Payload</h2><pre style="white-space:pre-wrap;">{{ json_encode($log->input_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></div></div><div class="card"><div class="card-body"><h2 class="section-title">Output Payload</h2><pre style="white-space:pre-wrap;">{{ json_encode($log->output_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></div></div></section>
@endsection
