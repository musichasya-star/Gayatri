@extends('layouts.app')

@section('title', 'Booking Calendar - Gayatri CRM')

@section('content')
@include('partials.admin-operations-assets')
    <x-ui.page-header eyebrow="Booking" title="Calendar Booking" description="Tampilan jadwal booking per hari untuk memantau slot treatment.">
        <x-slot name="actions"><a class="button button-secondary" href="{{ route('admin.bookings.index',request()->only(['date','service_group'])) }}"><i data-lucide="list"></i> List</a><a class="button button-primary" href="{{ route('admin.bookings.create') }}"><i data-lucide="plus"></i> Tambah Booking</a></x-slot>
    </x-ui.page-header>
    <div class="card" style="margin-bottom:1rem;"><div class="card-body"><form method="GET" class="ops-filters"><label>Tanggal booking<input class="input" type="date" name="date" value="{{ $date->format('Y-m-d') }}"></label><label>Kelompok layanan<select class="input" name="service_group" aria-label="Kelompok layanan"><option value="">Semua kelompok layanan</option>@foreach(\App\Support\ServiceGroup::LABELS as $key => $label)<option value="{{ $key }}" @selected(request('service_group') === $key)>{{ $label }}</option>@endforeach</select></label><button class="button button-secondary" type="submit"><i data-lucide="calendar"></i> Lihat Tanggal</button><span class="badge badge-gold" style="width:max-content;align-self:center;">{{ $bookings->count() }} booking</span></form></div></div>
    <div class="card"><div class="card-body"><h2 class="section-title">{{ $date->format('d M Y') }}</h2><div class="grid" style="gap:.75rem;">@forelse($bookings as $booking)<div class="soft-list-item"><div><strong>{{ substr($booking->start_time,0,5) }} - {{ substr($booking->end_time,0,5) }}</strong><p class="muted" style="margin:.25rem 0 0;">{{ $booking->customer?->name }} - {{ $booking->service?->name }} - {{ $booking->therapist?->name ?: 'Belum assign terapis' }}</p></div><span class="badge badge-brown">{{ \App\Support\BookingStatus::label($booking->status) }}</span><a class="button button-secondary" href="{{ route('admin.bookings.edit',['booking'=>$booking,'return_to'=>'booking-calendar','return_filters'=>['date'=>$date->format('Y-m-d'),'service_group'=>request('service_group')]]) }}">Kelola jadwal</a></div>@empty<p class="muted">Tidak ada booking pada tanggal ini.</p>@endforelse</div></div></div>
@endsection
