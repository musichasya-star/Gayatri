@extends('layouts.app')

@section('title', 'Detail Extracted Data - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="AI Data Automation" title="Detail Extracted Data" description="Review raw output ekstraksi AI sebelum approval atau eksekusi.">
        <x-slot name="actions"><a class="button button-secondary" href="{{ route('admin.ai.data-automation.extracted.index') }}"><i data-lucide="arrow-left"></i> Kembali</a></x-slot>
    </x-ui.page-header>
    <section class="grid grid-2"><div class="card"><div class="card-body"><h2 class="section-title">Ringkasan</h2><ul class="soft-list"><li class="soft-list-item"><span>Customer</span><strong>{{ $extractedData->customer?->name ?: '-' }}</strong></li><li class="soft-list-item"><span>Intent</span><span class="badge badge-brown">{{ $extractedData->intent }}</span></li><li class="soft-list-item"><span>Confidence</span><strong>{{ number_format((float)$extractedData->confidence_score * 100, 0) }}%</strong></li><li class="soft-list-item"><span>Status</span><span class="badge badge-gold">{{ $extractedData->status }}</span></li></ul></div></div><div class="card"><div class="card-body"><h2 class="section-title">Pesan Customer</h2><p class="muted">{{ $extractedData->message?->content ?: '-' }}</p></div></div></section>
    <section class="grid grid-3" style="margin-top:1rem;"><div class="card"><div class="card-body"><h2 class="section-title">Customer Data</h2><pre style="white-space:pre-wrap;">{{ json_encode($extractedData->extracted_customer_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></div></div><div class="card"><div class="card-body"><h2 class="section-title">Booking Data</h2><pre style="white-space:pre-wrap;">{{ json_encode($extractedData->extracted_booking_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></div></div><div class="card"><div class="card-body"><h2 class="section-title">Follow-Up Data</h2><pre style="white-space:pre-wrap;">{{ json_encode($extractedData->extracted_followup_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></div></div></section>
@endsection
