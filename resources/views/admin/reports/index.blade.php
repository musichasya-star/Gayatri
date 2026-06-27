@extends('layouts.app')

@section('title', 'Reports - Gayatri CRM')

@php($labels = ['customers' => 'Customer', 'bookings' => 'Booking', 'revenue' => 'Revenue', 'campaigns' => 'Campaign', 'followups' => 'Follow-Up', 'ai' => 'AI Performance'])

@section('content')
    <x-ui.page-header eyebrow="Reports" title="Reporting & Analytics" description="Lihat ringkasan operasional dan ekspor CSV untuk analisis lanjutan.">
        <x-slot name="actions"><a class="button button-secondary" href="{{ route('admin.dashboard') }}"><i data-lucide="layout-dashboard"></i> Dashboard</a></x-slot>
    </x-ui.page-header>
    <div class="card" style="margin-bottom:1rem;"><div class="card-body"><form method="GET" class="grid grid-3"><input class="input" type="date" name="start_date" value="{{ $startDate }}"><input class="input" type="date" name="end_date" value="{{ $endDate }}"><button class="button button-secondary" type="submit"><i data-lucide="filter"></i> Terapkan</button></form></div></div>
    <section class="grid grid-3">
        @foreach($types as $type)
            <div class="card"><div class="card-body"><h2 class="section-title">{{ $labels[$type] }}</h2><p class="muted">{{ $type === 'revenue' ? 'Rp '.number_format($summary[$type],0,',','.') : number_format($summary[$type]) }} data periode ini.</p><div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:1rem;"><a class="button button-secondary" href="{{ route('admin.reports.show', ['type' => $type, 'start_date' => $startDate, 'end_date' => $endDate]) }}">Lihat</a><a class="button button-primary" href="{{ route('admin.reports.export', ['type' => $type, 'start_date' => $startDate, 'end_date' => $endDate]) }}"><i data-lucide="download"></i> CSV</a></div></div></div>
        @endforeach
    </section>
@endsection
