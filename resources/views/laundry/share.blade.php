@extends('layouts.laundry-customer')
@section('content')
<section class="card accent access-card"><p class="eyebrow">TAUTAN PRIBADI PELANGGAN</p><h1>Bagikan dengan aman.</h1><p>Tautan memberikan akses pesanan dan bukti pembayaran. Kirim hanya ke kontak pelanggan yang sudah Anda verifikasi.</p><a class="private-link" href="{{ $privateLink }}">{{ $privateLink }}</a><p class="note">Salin tautan di atas. Mengganti atau mencabut tautan di menu admin juga menutup sesi portal lama.</p><a href="{{ $backUrl }}">← Kembali ke permintaan</a></section>
@endsection
