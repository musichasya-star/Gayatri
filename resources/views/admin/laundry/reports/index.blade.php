@extends('layouts.app')
@section('title', ($isDashboard ? 'Dashboard' : 'Laporan').' Laundry - Gayatri')
@section('content')
@include('partials.admin-workbench-assets')
@php($rp = fn($value) => $value === null ? '—' : 'Rp '.number_format($value,0,',','.'))
@php($demoFilter = ['include_demo'=>$includeDemo ? 1 : 0])
<x-ui.page-header eyebrow="Laundry Ibu & Bayi" title="{{ $isDashboard ? 'Dashboard Laundry' : 'Laporan Laundry' }}" description="Pantau operasional, tagihan yang disetujui, pembayaran manual yang berlaku, dan piutang Laundry."><x-slot name="actions"><a class="button button-secondary" href="{{ route($isDashboard ? 'admin.laundry.reports' : 'admin.laundry.dashboard',$filters) }}">{{ $isDashboard ? 'Buka Laporan Rinci' : 'Buka Dashboard' }}</a><a class="button button-primary" href="{{ route('admin.laundry.orders.create') }}">Penerimaan Baru</a></x-slot></x-ui.page-header>
@include('admin.laundry.nav', ['compactNavigation'=>true])
<link rel="stylesheet" href="{{ asset('css/laundry-reports.css').'?v='.config('gayatri_frontend.asset_version') }}">
<section class="card"><div class="card-body"><form method="GET" class="laundry-report-filter"><label>Dari tanggal<input class="input" type="date" name="start_date" value="{{ $start->toDateString() }}" max="{{ today()->toDateString() }}" required></label><label>Sampai tanggal<input class="input" type="date" name="end_date" value="{{ $end->toDateString() }}" max="{{ today()->toDateString() }}" required></label><input type="hidden" name="tab" value="{{ $tab }}"><label class="laundry-check"><input type="checkbox" name="include_demo" value="1" @checked($includeDemo)> Sertakan data demo</label><button class="button button-primary">Terapkan Periode</button><a class="button button-secondary" href="{{ route($isDashboard ? 'admin.laundry.dashboard' : 'admin.laundry.reports') }}">Bulan Ini</a></form><p class="muted laundry-report-meta">{{ $start->format('d M Y') }}–{{ $end->format('d M Y') }} · WIB · Diperbarui {{ now()->format('d M Y H:i') }} WIB. Maksimal 366 hari.</p></div></section>
@if($includeDemo)<div class="laundry-alert">Mode gabungan dengan DATA DEMO. Angka latihan ikut dihitung dan ditandai pada rincian serta ekspor.</div>@endif
@if($isDashboard)
@include('admin.laundry.reports.current')
@include('admin.laundry.reports.period')
@else
@include('admin.laundry.reports.period')
<details class="ops-section"><summary>Saldo & antrean saat ini · semua tanggal</summary><div class="ops-section-body">@include('admin.laundry.reports.current')</div></details>
@endif
@if($isDashboard)
<details class="ops-section"><summary>Tren periode & layanan teratas</summary><div class="ops-section-body"><div class="laundry-grid laundry-report-section"><section class="card"><div class="card-body"><h2>Tren tagihan & pembayaran</h2><p class="muted laundry-report-meta">{{ count($report['days']) > 31 ? 'Agregasi per bulan pada rentang terpilih.' : 'Agregasi harian pada rentang terpilih.' }} Tagihan mengikuti tanggal penerimaan; pembayaran mengikuti tanggal bayar.</p>
@if($report['period']['charges'] || $report['period']['paid'])
@php($peak=max(1,...array_column($trend,'charges'),...array_column($trend,'paid')))
<div class="laundry-chart-scroll"><div class="laundry-chart" role="img" aria-label="Grafik tagihan disetujui dan pembayaran berlaku. Nilai lengkap tersedia pada laporan ringkasan harian.">@foreach($trend as $point)<div class="laundry-chart-column" title="{{ $point['label'] }} · Tagihan {{ $rp($point['charges']) }} · Pembayaran {{ $rp($point['paid']) }}"><div class="laundry-chart-bars"><span class="laundry-chart-charge" style="height:{{ round($point['charges']/$peak*100,2) }}%"></span><span class="laundry-chart-paid" style="height:{{ round($point['paid']/$peak*100,2) }}%"></span></div><span class="laundry-chart-label">{{ substr($point['label'],-2) }}</span></div>@endforeach</div></div><div class="laundry-chart-legend"><span>■ Tagihan disetujui</span><span>■ Pembayaran berlaku</span></div>
@else<p class="laundry-report-empty">Belum ada tagihan disetujui atau pembayaran berlaku pada periode ini.</p>@endif
<a href="{{ route('admin.laundry.reports',$filters+['tab'=>'daily']) }}">Lihat angka harian dan ekspor →</a></div></section>
<section class="card"><div class="card-body"><h2>Layanan dengan nilai jasa tertinggi</h2><p class="muted laundry-report-meta">Dari pesanan diterima pada periode terpilih yang kini disetujui.</p>@forelse(array_slice($report['services'],0,5) as $line)<div class="laundry-report-service"><div><strong>{{ $line['name'] }}</strong><span>{{ $line['orders'] }} pesanan · {{ number_format($line['quantity_milli']/1000,$line['unit'] === 'kg' ? 3 : 0,',','.') }} {{ $line['unit'] === 'kg' ? 'kg aktual' : 'potong' }}</span></div><strong>{{ $rp($line['service_total']) }}</strong></div>@empty<p class="laundry-report-empty">Belum ada layanan disetujui pada periode ini.</p>@endforelse<a href="{{ route('admin.laundry.reports',$filters+['tab'=>'services']) }}">Lihat seluruh layanan →</a></div></section></div>
</div></details><details class="ops-section"><summary>Status pesanan & metode pembayaran</summary><div class="ops-section-body"><div class="laundry-grid"><section class="card"><div class="card-body"><h2>Status pesanan diterima</h2>@foreach(\App\Models\LaundryOrder::STATUSES as $key=>$label)<div class="laundry-report-row"><span>{{ $label }}</span><strong>{{ $report['status_counts'][$key] ?? 0 }}</strong></div>@endforeach</div></section><section class="card"><div class="card-body"><h2>Metode pembayaran berlaku</h2>@foreach(\App\Models\LaundryPayment::METHODS as $key=>$label)<div class="laundry-report-row"><span>{{ $label }}</span><strong>{{ $rp($report['methods'][$key] ?? 0) }}</strong></div>@endforeach<p class="muted laundry-report-meta">{{ $report['period']['corrections'] }} koreksi pencatatan pada periode ini, senilai {{ $rp($report['period']['correction_amount']) }}. Koreksi merupakan pembatalan pencatatan, bukan pengembalian uang.</p><a href="{{ route('admin.laundry.reports',$filters+['tab'=>'corrections']) }}">Periksa riwayat koreksi →</a></div></section></div>
</div></details>
@else
@include('admin.laundry.reports.details')
@endif
@endsection
