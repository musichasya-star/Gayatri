@extends('layouts.app')
@section('title', 'Pratinjau Jadwal Laundry')
@section('content')
<x-ui.page-header eyebrow="Laundry Ibu & Bayi" title="Pratinjau Jadwal & Ongkir" description="Periksa alamat, petugas, waktu, dan kesepakatan biaya sebelum menyimpan." />
@include('admin.laundry.nav')
<section class="card"><div class="card-body"><h2>{{ \App\Models\LaundryTransportTask::KINDS[$preview['data']['kind']] }} · {{ $preview['customer']['name'] }}</h2>
<p>{{ $courier->name }} · {{ \Carbon\Carbon::parse($preview['data']['scheduled_start'])->format('d M Y H:i') }}–{{ \Carbon\Carbon::parse($preview['data']['scheduled_end'])->format('H:i') }} WIB</p><p class="laundry-preserve">{{ $preview['data']['address'] }}</p>
<p>Jarak {{ number_format($preview['quote']['distance_milli']/1000,1,',','.') }} km · {{ $preview['quote']['distance_source'] === 'manual' ? 'Jarak jalan manual' : 'Garis lurus dari koordinat' }}</p>
<div class="laundry-values"><div>Ongkir kunjungan ini<strong>Rp {{ number_format($preview['quote']['fee'],0,',','.') }}</strong></div></div>
<p>Disetujui {{ $preview['quote']['approved_name'] }} · {{ $preview['quote']['approval_reference'] }}</p>
<p>{{ empty($preview['data']['laundry_order_id']) ? 'Biaya masuk ke tagihan ketika penjemputan selesai dihubungkan ke penerimaan pelanggan yang sama.' : 'Ongkir akan masuk ke total tagihan pesanan terkait. Penjemputan dan pengantaran ditampilkan terpisah di invoice.' }}</p>
<form class="laundry-form" method="POST" action="{{ route('admin.laundry.transport.store') }}">@csrf<input type="hidden" name="token" value="{{ $preview['token'] }}"><label class="laundry-check"><input type="checkbox" name="confirmed" value="1" required> Saya telah memeriksa jadwal dan alamat, serta mencatat persetujuan ongkir pelanggan.</label><button class="button button-primary">Simpan Jadwal & Kesepakatan Ongkir</button></form>
</div></section>
@endsection
