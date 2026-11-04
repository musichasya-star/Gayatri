@extends('layouts.app')

@section('title', 'AI Guardrail - Gayatri CRM')

@section('content')
@include('partials.admin-workbench-assets')
    <x-ui.page-header eyebrow="AI Management" title="Scope & Guardrail" description="Aturan keamanan AI agar tetap dalam scope layanan Gayatri." />
    <p class="wb-scope">AI menjelaskan layanan, mengarahkan pemesanan ke web, dan memeriksa status dengan verifikasi pemilik. AI tidak membuat atau mengubah transaksi melalui chat.</p><section class="grid grid-3">
        <div class="card"><div class="card-body"><h2 class="section-title">Informasi yang dapat dijawab</h2><ul class="soft-list"><li class="soft-list-item">Layanan dan harga</li><li class="soft-list-item">Informasi jadwal & tautan pemesanan web</li><li class="soft-list-item">Promo aktif</li><li class="soft-list-item">Lokasi dan jam operasional</li></ul></div></div>
        <div class="card"><div class="card-body"><h2 class="section-title">Perlu bantuan admin / tenaga kesehatan</h2><ul class="soft-list"><li class="soft-list-item">Diagnosis medis</li><li class="soft-list-item">Rekomendasi obat</li><li class="soft-list-item">Permintaan refund dan komplain ditangani admin</li><li class="soft-list-item">Diskon tidak resmi</li></ul></div></div>
        <div class="card"><div class="card-body"><h2 class="section-title">Fallback</h2><p class="muted">Pertanyaan medis dan sensitif dibalas dengan bahasa pelanggan yang aman, natural, dan tetap dicatat ke AI log untuk evaluasi.</p></div></div>
    </section>
@endsection
