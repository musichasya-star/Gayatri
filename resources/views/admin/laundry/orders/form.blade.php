@extends('layouts.app')
@section('title', 'Penerimaan Laundry Baru - Gayatri')
@section('content')
<x-ui.page-header eyebrow="Laundry Ibu & Bayi" title="Penerimaan Laundry Baru" description="Setiap baris mewakili satu kantong/kelompok pakaian. Periksa estimasi sebelum menyimpan penerimaan." />
@include('admin.laundry.nav')
@php($initial = old('items', $draft['items'] ?? [['laundry_tariff_id' => '', 'bag_label' => '', 'received_pieces' => 1, 'estimated_quantity' => '', 'condition_notes' => '']]))
@if(!$settings['accepting_orders'] || $tariffs->isEmpty())<div class="laundry-alert">Aktifkan penerimaan pada Pengaturan Outlet dan sediakan minimal satu tarif aktif sebelum mencatat barang masuk.</div>@endif
<section class="card"><div class="card-body"><form method="POST" action="{{ route('admin.laundry.orders.preview') }}" class="laundry-form" data-laundry-intake>@csrf
<input type="hidden" name="entry_key" value="{{ old('entry_key', $draft['entry_key'] ?? (string) \Illuminate\Support\Str::uuid()) }}">
@if(old('public_request_id',$draft['public_request_id'] ?? null))<input type="hidden" name="public_request_id" value="{{ old('public_request_id',$draft['public_request_id'] ?? '') }}"><p class="laundry-alert">Penerimaan dari permintaan online terverifikasi. Hitung potong dan periksa kondisi setiap kantong secara langsung.</p>@endif
<div class="laundry-grid">
    <label class="laundry-wide">Pelanggan CRM<select class="input" name="customer_id"><option value="">Daftarkan pelanggan baru</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected((string) old('customer_id', $draft['customer_id'] ?? '') === (string) $customer->id)>{{ $customer->name }} · {{ $customer->whatsapp_number ?: 'tanpa WhatsApp' }}{{ $customer->status === 'inactive' ? ' (arsip)' : '' }}</option>@endforeach</select></label>
    <label data-laundry-new-customer>Nama pelanggan baru<input class="input" name="new_name" maxlength="150" value="{{ old('new_name', $draft['new_name'] ?? '') }}"></label><label data-laundry-new-customer>WhatsApp pelanggan baru<input class="input" name="new_whatsapp" inputmode="tel" maxlength="30" placeholder="08… atau 628…" value="{{ old('new_whatsapp', $draft['new_whatsapp'] ?? '') }}"></label>
    <label>Waktu penerimaan (WIB)<input class="input" type="datetime-local" name="received_at" max="{{ now()->format('Y-m-d\TH:i') }}" value="{{ old('received_at', $draft['received_at'] ?? now()->format('Y-m-d\TH:i')) }}" required></label>
    <label>Cara penerimaan<select class="input" name="intake_mode">@foreach(\App\Models\LaundryOrder::MODES as $key => $label)@continue($key === 'collection' && !$settings['allow_collection'])<option value="{{ $key }}" @selected(old('intake_mode', $draft['intake_mode'] ?? 'walkin') === $key)>{{ $label }}</option>@endforeach</select></label>
    <label class="laundry-wide" data-laundry-collection>Alamat penjemputan<textarea class="input" name="collection_address" minlength="10" maxlength="1500">{{ old('collection_address', $draft['collection_address'] ?? '') }}</textarea><span class="muted">Jadwal dan ongkos dikonfirmasi kepada pelanggan; estimasi di halaman ini hanya untuk jasa laundry.</span></label>
</div>
<h2 class="section-title">Kantong dan pakaian diterima</h2><p class="muted">Jumlah potong wajib dicatat pada setiap kantong. Estimasi kilogram boleh dikosongkan jika belum diketahui. Untuk tarif satuan, jumlah yang dihitung mengikuti jumlah potong.</p>
<div data-laundry-lines>@foreach($initial as $index => $item)@include('admin.laundry.orders.intake-line', ['rowIndex' => $index, 'line' => $item])@endforeach</div>
<button class="button button-secondary" type="button" data-laundry-add>Tambah Kantong / Layanan</button>
<label>Instruksi khusus pelanggan<textarea class="input" name="instructions" maxlength="3000" placeholder="Contoh: tanpa pewangi, pisahkan warna, atau instruksi perawatan pada label pakaian.">{{ old('instructions', $draft['instructions'] ?? '') }}</textarea></label>
<label class="laundry-check"><input type="checkbox" name="is_demo" value="1" @checked(old('is_demo', $draft['is_demo'] ?? false))> Tandai sebagai data demo / latihan</label>
<div class="laundry-actions"><button class="button button-primary" @disabled(!$settings['accepting_orders'] || $tariffs->isEmpty())>Periksa Estimasi Penerimaan</button><a class="button button-secondary" href="{{ route('admin.laundry.orders.index') }}">Kembali</a></div>
</form></div></section>
<template data-laundry-template>@include('admin.laundry.orders.intake-line', ['rowIndex' => '__INDEX__', 'line' => []])</template>
<script src="{{ asset('js/laundry.js') }}" defer></script>
@endsection
