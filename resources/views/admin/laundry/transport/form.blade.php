@extends('layouts.app')
@section('title', 'Jadwal Baru Laundry')
@section('content')
@php($draft = session('laundry.transport_draft', []))
@php($field = fn ($key, $default = '') => old($key, data_get($draft, $key, $default)))
<x-ui.page-header eyebrow="Laundry Ibu & Bayi" title="Buat Jadwal Antar-Jemput" description="Pratinjau ongkir dan jadwal sebelum menyimpan. Petugas yang sama tidak dapat memiliki jadwal bertabrakan." />
@include('admin.laundry.nav')
<form class="laundry-form laundry-grid" method="POST" action="{{ route('admin.laundry.transport.preview') }}">@csrf<input type="hidden" name="entry_key" value="{{ $field('entry_key', (string)\Illuminate\Support\Str::uuid()) }}">
<section class="card"><div class="card-body">
@if($field('public_request_id'))<input type="hidden" name="public_request_id" value="{{ $field('public_request_id') }}"><p class="laundry-alert">Jadwal dari permintaan Laundry online yang kontaknya telah diverifikasi.</p>@endif
    <label>Jenis kunjungan<select class="input" name="kind"><option value="pickup" @selected($field('kind','pickup') === 'pickup')>Penjemputan</option><option value="delivery" @selected($field('kind') === 'delivery')>Pengantaran</option></select></label>
    <label>Pesanan penerimaan terkait<select class="input" name="laundry_order_id"><option value="">Penjemputan sebelum penerimaan</option>@foreach($orders as $order)<option value="{{ $order->id }}" @selected((string)$field('laundry_order_id', $selectedOrder) === (string)$order->id)>{{ $order->code }} · {{ $order->customer_snapshot['name'] }}{{ $order->is_demo ? ' [DEMO]' : '' }}</option>@endforeach</select></label>
    <label>Pelanggan CRM (untuk jemput sebelum penerimaan)<select class="input" name="customer_id"><option value="">Pilih pelanggan</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected((string)$field('customer_id') === (string)$customer->id)>{{ $customer->name }} · {{ $customer->whatsapp_number }}</option>@endforeach</select></label>
    <p class="muted">Pesanan terpilih menentukan pelanggan. Pengantaran memerlukan pesanan. <a href="{{ route('admin.customers.create') }}" target="_blank" rel="noopener">Tambah pelanggan CRM</a> jika belum terdaftar.</p>
    <label>Petugas<select class="input" name="laundry_courier_id" required><option value="">Pilih petugas aktif</option>@foreach($couriers as $courier)<option value="{{ $courier->id }}" @selected((string)$field('laundry_courier_id') === (string)$courier->id)>{{ $courier->name }}</option>@endforeach</select></label>
    <label>Jadwal mulai<input class="input" name="scheduled_start" value="{{ $field('scheduled_start') }}" type="datetime-local" min="{{ now()->format('Y-m-d\TH:i') }}" required></label><label>Jadwal selesai<input class="input" name="scheduled_end" value="{{ $field('scheduled_end') }}" type="datetime-local" min="{{ now()->format('Y-m-d\TH:i') }}" required></label>
    <label>Alamat lengkap<textarea class="input" name="address" minlength="10" maxlength="1500" required>{{ $field('address') }}</textarea></label>
    <div data-laundry-location><div class="laundry-grid"><label>Latitude tujuan<input class="input" data-latitude name="latitude" value="{{ $field('latitude') }}" type="number" min="-90" max="90" step="any"></label><label>Longitude tujuan<input class="input" data-longitude name="longitude" value="{{ $field('longitude') }}" type="number" min="-180" max="180" step="any"></label></div><button class="button button-secondary" type="button" data-laundry-locate>Ambil Lokasi Perangkat Ini</button><p class="muted" data-location-status role="status"></p><p class="muted">Gunakan tombol lokasi ketika berada di alamat tujuan. Admin di outlet dapat memasukkan titik pelanggan secara manual.</p></div>
</div></section>
<section class="card"><div class="card-body">
    <label>Sumber jarak<select class="input" name="distance_source"><option value="coordinates" @selected($field('distance_source','coordinates') === 'coordinates')>Garis lurus dari koordinat outlet</option><option value="manual" @selected($field('distance_source') === 'manual')>Jarak jalan yang dikonfirmasi admin</option></select></label>
    <label>Jarak jalan manual (km)<input class="input" name="distance_km" value="{{ $field('distance_km') }}" type="number" min="0" max="500" step="0.001"></label><label>Sumber / alasan jarak manual<input class="input" name="distance_note" value="{{ $field('distance_note') }}" minlength="5" maxlength="500" placeholder="Contoh: jarak rute jalan diperiksa bersama pelanggan."></label>
    <p class="laundry-notice">Jarak ditagihkan dengan pembulatan ke atas per 0,1 km. Tarif mengikuti Pengaturan Ongkir Laundry. Jemput dan antar masing-masing satu kunjungan. Tetapkan ongkir sebelum menerbitkan invoice atau mencatat DP.</p>
    <label>Nama pelanggan yang menyetujui ongkir<input class="input" name="approved_name" value="{{ $field('approved_name') }}" maxlength="150" required></label><label>Referensi persetujuan ongkir<input class="input" name="approval_reference" value="{{ $field('approval_reference') }}" minlength="5" maxlength="500" required placeholder="Catat cara dan kesepakatan ongkir dengan pelanggan."></label>
    <label>Catatan kunjungan<textarea class="input" name="notes" maxlength="1500">{{ $field('notes') }}</textarea></label>
    <label class="laundry-check"><input type="checkbox" name="is_demo" value="1" @checked($field('is_demo',false))> Data demo (hanya untuk jemput yang belum terkait pesanan)</label>
    <button class="button button-primary">Pratinjau Jadwal & Ongkir</button>
</div></section></form>
@endsection
@push('scripts')<script src="{{ asset('js/laundry.js') }}" defer></script>@endpush
