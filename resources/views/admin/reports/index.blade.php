@extends('layouts.app')

@section('title', 'Reports - Gayatri CRM')

@php($labels = ['customers' => 'Customer', 'bookings' => 'Booking', 'revenue' => 'Tagihan Booking (Estimasi)', 'payments'=>'Pembayaran Booking Bersih', 'balances'=>'Piutang Booking dari Jurnal', 'campaigns' => 'Campaign', 'followups' => 'Follow-Up', 'ai' => 'AI Performance'])

@section('content')
@include('partials.admin-workbench-assets')
    <x-ui.page-header eyebrow="Reports" title="Reporting & Analytics" description="Lihat ringkasan operasional dan ekspor CSV untuk analisis lanjutan.">
        <x-slot name="actions"><a class="button button-secondary" href="{{ route('admin.dashboard') }}"><i data-lucide="layout-dashboard"></i> Dashboard</a></x-slot>
    </x-ui.page-header>
    @if(auth()->user()->hasRole(['owner','manager','admin']))<nav class="wb-module-links" aria-label="Laporan per modul"><a href="{{ route('admin.laundry.reports',['start_date'=>min(today()->toDateString(),$startDate),'end_date'=>min(today()->toDateString(),$endDate)]) }}">Laporan Laundry</a><a href="{{ route('admin.rentals.reports',['start_date'=>min(today()->toDateString(),$startDate),'end_date'=>min(today()->toDateString(),$endDate)]) }}">Laporan Rental</a><a href="{{ route('admin.therapist-fees.index',['start_date'=>min(today()->toDateString(),$startDate),'end_date'=>min(today()->toDateString(),$endDate)]) }}">Fee Terapis</a></nav>@endif
    <div class="card" style="margin-bottom:1rem;"><div class="card-body"><form method="GET" class="ops-filters"><label>Dari tanggal<input class="input" type="date" name="start_date" value="{{ $startDate }}"></label><label>Sampai tanggal<input class="input" type="date" name="end_date" value="{{ $endDate }}"></label><button class="button button-secondary" type="submit"><i data-lucide="filter"></i> Terapkan</button></form></div></div>
    <details class="ops-section"><summary>Arti angka & dasar periode</summary><div class="ops-section-body"><p class="muted">Tagihan mengikuti tanggal layanan; pembayaran mengikuti tanggal transaksi yang masih berlaku. Pembayaran bersih adalah penerimaan dikurangi refund. Piutang hanya dari jurnal aktif, merupakan kondisi saat ini dari semua tanggal. Status lunas historis tanpa jurnal tidak dianggap sebagai penerimaan baru. Data demo dikecualikan.</p></div></details>
    <section class="grid grid-3">
        @foreach($types as $type)
            <div class="card"><div class="card-body"><h2 class="section-title">{{ $labels[$type] }}</h2><p class="muted">{{ in_array($type,['revenue','payments','balances']) ? \App\Services\CRM\BookingFinance::money((int) round($summary[$type]*100)) : number_format($summary[$type]) }} {{ $type === 'balances' ? 'saldo saat ini' : 'pada periode ini' }}.</p><div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:1rem;"><a class="button button-secondary" href="{{ route('admin.reports.show', ['type' => $type, 'start_date' => $startDate, 'end_date' => $endDate]) }}">Lihat</a><a class="button button-primary" href="{{ route('admin.reports.export', ['type' => $type, 'start_date' => $startDate, 'end_date' => $endDate]) }}"><i data-lucide="download"></i> CSV</a></div></div></div>
        @endforeach
    </section>
@endsection
