@extends('layouts.app')
@section('title', 'Kesiapan Operasional - Gayatri')
@section('content')
@include('partials.admin-workbench-assets')
@push('styles')<link rel="stylesheet" href="{{ asset('css/operational-readiness.css').'?v='.config('gayatri_frontend.asset_version') }}">@endpush
<x-ui.page-header eyebrow="Operasional" title="Kesiapan Operasional" description="Periksa kesiapan setiap layanan dan lengkapi bagian yang menghambat pemesanan.">
    <x-slot name="actions"><a class="button button-secondary" href="{{ route('admin.readiness.export') }}">Unduh daftar kelengkapan</a><a class="button button-primary" href="{{ route('admin.readiness.index') }}">Periksa kembali</a></x-slot>
</x-ui.page-header>
<p class="muted">Diperiksa {{ \Carbon\Carbon::parse($report['checked_at'])->format('d M Y H:i') }} WIB. Status dihitung dari pengaturan saat ini; hasil pengujian dicatat terpisah.</p>
<div class="wb-tasks readiness-summary" aria-label="Ringkasan kesiapan">
@foreach(\App\Services\CRM\OperationalReadiness::LABELS as $key => $label)<div class="wb-task"><span>{{ $label }}</span><strong>{{ $report['counts'][$key] ?? 0 }}</strong></div>@endforeach
</div>
<div class="readiness-grid">
@foreach($report['checks'] as $check)
<section class="card readiness-card" data-readiness="{{ $check['status'] }}"><div class="card-body">
<span class="badge {{ ['ready'=>'badge-green', 'attention'=>'badge-gold', 'blocked'=>'badge-red', 'unverified'=>'badge-gold'][$check['status']] }}">{{ \App\Services\CRM\OperationalReadiness::LABELS[$check['status']] }}</span>
<h2>{{ $check['title'] }}</h2><p>{{ $check['detail'] }}</p>
<a class="button button-secondary" href="{{ route($check['route'], $check['params']) }}">{{ $check['status'] === 'ready' ? 'Lihat pengaturan' : 'Tinjau & lengkapi' }}</a>
</div></section>
@endforeach
</div>
<section class="card readiness-section"><div class="card-body"><h2 class="section-title">Cakupan panduan AI</h2><p class="muted">Panduan terbit perlu sesuai kebijakan usaha. Contoh demo tidak digunakan untuk menjawab pelanggan.</p><div class="readiness-grid">
@foreach($report['knowledge'] as $domain)<div class="readiness-knowledge"><h3>{{ $domain['label'] }}</h3><p>{{ $domain['published_usable'] }} panduan terbit dapat digunakan · {{ $domain['review_required'] }} perlu ditinjau</p><p>{{ $domain['topic_gaps'] ? 'Topik yang perlu dilengkapi: '.implode(', ', $domain['topic_gaps']).'.' : 'Cakupan topik terpenuhi; periksa kebenaran isinya secara berkala.' }}</p></div>@endforeach
</div><a class="button button-secondary" href="{{ route('admin.ai.knowledge.index') }}">Tinjau Knowledge Base</a></div></section>
<section class="card readiness-section"><div class="card-body"><h2 class="section-title">Bukti pengujian terakhir</h2><p class="muted">Hasil berlaku pada waktu pengujian. Uji transaksi terisolasi memeriksa alur aplikasi; kesiapan data usaha tetap mengikuti daftar di atas.</p>
@forelse($report['evidence'] as $evidence)<div class="wb-row"><div><strong>{{ $evidence['label'] }}</strong><small>{{ $evidence['checked_at'] }} WIB{{ $evidence['current'] ? '' : ' · Perlu diuji kembali: hasil lama atau konfigurasi berubah.' }}</small></div><span class="badge {{ $evidence['passed'] && $evidence['current'] ? 'badge-green' : 'badge-gold' }}">{{ $evidence['passed'] ? 'Lulus saat diuji' : 'Perlu ditindaklanjuti' }}</span></div>@empty<p>Belum ada hasil pengujian yang tercatat untuk lingkungan ini.</p>@endforelse
</div></section>
<p class="wb-scope">Lengkapi jadwal, harga, durasi, stok fisik, deposit, dan ketentuan menggunakan data usaha yang sudah dikonfirmasi. Pemeriksaan ini tidak mengaktifkan layanan atau membuat transaksi.</p>
@endsection
