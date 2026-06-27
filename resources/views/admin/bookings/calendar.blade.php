@extends('layouts.app')

@section('title', 'Booking Calendar - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="Booking" title="Calendar Booking" description="Tampilan jadwal booking per hari untuk memantau slot treatment.">
        <x-slot name="actions"><a class="button button-secondary" href="{{ route('admin.bookings.index') }}"><i data-lucide="list"></i> List</a><a class="button button-primary" href="{{ route('admin.bookings.create') }}"><i data-lucide="plus"></i> Tambah Booking</a></x-slot>
    </x-ui.page-header>
    <div class="card" style="margin-bottom:1rem;"><div class="card-body"><form method="GET" class="grid grid-3"><input class="input" type="date" name="date" value="{{ $date->format('Y-m-d') }}"><button class="button button-secondary" type="submit"><i data-lucide="calendar"></i> Lihat Tanggal</button><span class="badge badge-gold" style="width:max-content;align-self:center;">{{ $bookings->count() }} booking</span></form></div></div>
    <div class="card"><div class="card-body"><h2 class="section-title">{{ $date->format('d M Y') }}</h2><div class="grid" style="gap:.75rem;">@forelse($bookings as $booking)<div class="soft-list-item"><div><strong>{{ substr($booking->start_time,0,5) }} - {{ substr($booking->end_time,0,5) }}</strong><p class="muted" style="margin:.25rem 0 0;">{{ $booking->customer?->name }} - {{ $booking->service?->name }} - {{ $booking->therapist?->name ?: 'Belum assign terapis' }}</p></div><span class="badge badge-brown">{{ $booking->status }}</span></div>@empty<p class="muted">Tidak ada booking pada tanggal ini.</p>@endforelse</div></div></div>
@endsection
