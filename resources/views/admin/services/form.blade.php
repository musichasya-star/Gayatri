@extends('layouts.app')
@section('title', ($service->exists?'Edit Variasi':'Tambah Layanan').' - Gayatri CRM')
@push('styles')<link rel="stylesheet" href="{{ asset('css/admin-catalogue.css') }}">@endpush
@section('content')
<x-ui.page-header :eyebrow="$service->groupLabel()" title="{{ $service->exists?'Edit variasi layanan':'Tambah layanan / variasi' }}" description="Kelola informasi, harga, tambahan, dan publikasi secara terpisah.">
<x-slot name="actions"><a class="button button-secondary" href="{{ \App\Support\AdminReviewContext::back(request(),'services',['service_group'=>$service->service_group]) }}">Kembali ke katalog</a></x-slot></x-ui.page-header>
@if($errors->any())<div class="catalogue-notice catalogue-error" role="alert"><strong>Periksa kembali formulir.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@if($service->exists)<p class="catalogue-notice"><strong>{{ \App\Models\Service::READINESS[$service->readiness()] }}</strong> · {{ $service->readinessReason() }} Publikasi: {{ $service->is_active?'tayang':'diarsipkan' }}.</p>@endif
<nav class="catalogue-nav" aria-label="Bagian formulir layanan"><a class="button button-ghost" href="#service-information">Informasi utama</a><a class="button button-ghost" href="#service-variation">Variasi & harga</a><a class="button button-ghost" href="#service-addons">Tambahan</a><a class="button button-ghost" href="#service-publication">Publikasi</a></nav>
@php
    $addonRows=old('addons');
    if($addonRows===null) $addonRows=$service->exists?$service->addOns->map(fn($addon)=>[
        'id'=>$addon->id,'addon_service_id'=>$addon->addon_service_id,'addon_name'=>$addon->addon_name,
        'label'=>$addon->displayName(),'duration_minutes'=>$addon->requires_confirmation && !$addon->duration_minutes ? '' : $addon->duration_minutes,
        'price_adjustment'=>$addon->price_adjustment,'requires_confirmation'=>$addon->requires_confirmation,'is_active'=>(int)$addon->is_active,
    ])->values()->all():[];
    $addonChoices=$addonServices->map(fn($choice)=>['id'=>$choice->id,'name'=>$choice->name,'group'=>$choice->service_group,'code'=>$choice->catalog_code,
        'duration'=>$choice->durationLabel(),'price'=>$choice->priceLabel(),'kind'=>\App\Models\Service::KINDS[$choice->catalogueKind()]])->values()->all();
@endphp
<form method="POST" enctype="multipart/form-data" action="{{ $service->exists?route('admin.services.update',$service):route('admin.services.store') }}" class="catalogue-form" data-service-form>
@csrf @include('partials.admin-review-fields', ['reviewModule'=>'services']) @if($service->exists) @method('PUT') @endif
<input type="hidden" name="catalogue_form" value="1"><input type="hidden" name="addons_present" value="1">
<section class="card catalogue-section" id="service-information"><div class="card-body"><h2>Informasi utama</h2><p class="muted">Keluarga mengelompokkan variasi pada katalog. Setiap variasi tetap mempunyai pencatatan transaksi sendiri.</p>
<div class="catalogue-fields">
<label>Nama lengkap layanan<input class="input" name="name" value="{{ old('name',$service->name) }}" maxlength="255" required placeholder="Contoh: Pijat Laktasi Premium — 1 sesi"></label>
<label>Keluarga layanan<input class="input" name="catalog_family" value="{{ old('catalog_family',$service->catalog_family) }}" maxlength="255" placeholder="Contoh: Pijat Laktasi Premium"><small>Gunakan nama keluarga yang sama untuk pilihan dari layanan yang sama.</small></label>
<label>Kelompok layanan<select class="input" name="service_group" required>@foreach(\App\Support\ServiceGroup::LABELS as $key=>$label)<option value="{{ $key }}" @selected(old('service_group',$service->service_group)===$key)>{{ $label }}</option>@endforeach</select></label>
<label>Kategori / usia<input class="input" name="category" value="{{ old('category',$service->category) }}" maxlength="255"></label>
<label>Cabang<select class="input" name="branch_id"><option value="">Semua cabang</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((string)old('branch_id',$service->branch_id)===(string)$branch->id)>{{ $branch->name }}</option>@endforeach</select></label>
<label>Foto layanan<input class="input" type="file" name="photo" accept="image/jpeg,image/png,image/webp"><small>JPG, PNG, atau WebP; maksimal 5 MB.</small></label>
<label class="wide">Deskripsi / cakupan<textarea class="input" name="description" rows="4">{{ old('description',$service->description) }}</textarea></label>
</div></div></section>
<section class="card catalogue-section" id="service-variation"><div class="card-body"><h2>Variasi & harga</h2>
<div class="catalogue-fields">
<label>Nama variasi<input class="input" name="variant_label" value="{{ old('variant_label',$service->variant_label) }}" maxlength="255" placeholder="Contoh: 1 sesi · 45 menit"></label>
<label>Jenis layanan<select class="input" name="catalog_kind">@foreach(\App\Models\Service::KINDS as $key=>$label)<option value="{{ $key }}" @selected(old('catalog_kind',$service->catalogueKind())===$key)>{{ $label }}</option>@endforeach</select><small>Paket dan tambahan tetap melalui konfirmasi admin.</small></label>
<label>Jumlah sesi / hari / kunjungan<input class="input" name="package_quantity" type="number" min="1" max="100" value="{{ old('package_quantity',data_get($service->catalog_metadata,'package_quantity',1)) }}" required></label>
<label>Satuan paket<select class="input" name="package_unit">@foreach(['sesi'=>'Sesi','hari'=>'Hari','kunjungan'=>'Kunjungan','paket'=>'Paket'] as $key=>$label)<option value="{{ $key }}" @selected(old('package_unit',data_get($service->catalog_metadata,'package_unit','sesi'))===$key)>{{ $label }}</option>@endforeach</select></label>
<label>Harga variasi (Rp)<input class="input" name="price" type="number" min="0" step="0.01" value="{{ old('price',$service->price) }}"><small>Harga untuk seluruh pilihan ini. Kosong berarti belum dikonfirmasi; angka 0 berarti gratis.</small></label>
<label>Durasi per sesi (menit)<input class="input" name="duration_minutes" type="number" min="15" max="600" value="{{ old('duration_minutes',$service->duration_minutes) }}"><small>Kosongkan bila belum diketahui. Wajib diisi untuk memilih jadwal web.</small></label>
<label class="wide">Alur pemesanan<select class="input" name="booking_mode"><option value="online" @selected(old('booking_mode',$service->booking_mode ?? 'admin')==='online')>Pilih jadwal melalui web</option><option value="admin" @selected(old('booking_mode',$service->booking_mode ?? 'admin')==='admin')>Konfirmasi admin terlebih dahulu</option></select><small data-mode-help>Siap dipesan berarti harga dan durasi sudah tersedia. Jadwal tetap diatur pada menu Jadwal Tersedia.</small></label>
</div></div></section>
<section class="card catalogue-section" id="service-addons"><div class="card-body">
<div class="catalogue-addon-heading"><div><h2>Tambahan layanan</h2><p class="muted">Pilih dari katalog pada kelompok yang sama atau isi tambahan khusus. Pastikan harga dan durasinya sebelum ditawarkan.</p></div><button class="button button-secondary" type="button" data-add-addon>Tambah tambahan</button></div>
<p class="catalogue-notice">Tambahan yang perlu konfirmasi belum ditawarkan pada pilihan booking. Riwayat tambahan tetap tersimpan ketika dilepas.</p>
<div class="catalogue-addon-list" data-addon-list>@foreach($addonRows as $index=>$addon)@include('admin.services.partials.addon-row')@endforeach</div>
<p class="muted" data-addon-empty @if(count($addonRows)) hidden @endif>Belum ada tambahan untuk layanan ini.</p>
</div></section>
<section class="card catalogue-section" id="service-publication"><div class="card-body"><h2>Publikasi</h2><div class="catalogue-fields">
<label>Status katalog<select class="input" name="is_active" required><option value="1" @selected((string)old('is_active',(int)$service->is_active)==='1')>Tayang</option><option value="0" @selected((string)old('is_active',(int)$service->is_active)==='0')>Diarsipkan</option></select><small>Publikasi menentukan apakah pelanggan melihat variasi ini. Kesiapan pemesanan tetap mengikuti data dan alurnya.</small></label>
<div class="catalogue-notice">Perubahan berlaku pada katalog. Nilai harga yang sudah tersimpan pada transaksi tidak dihitung ulang.</div>
</div></div></section>
<div class="catalogue-save"><a class="button button-ghost" href="{{ \App\Support\AdminReviewContext::back(request(),'services',['service_group'=>$service->service_group]) }}">Batal</a><button class="button button-primary" type="submit">Simpan variasi</button></div>
</form>
<template data-addon-template>@include('admin.services.partials.addon-row',['index'=>'__INDEX__','addon'=>['requires_confirmation'=>true,'is_active'=>1]])</template>
<dialog class="catalogue-picker" data-addon-picker aria-labelledby="addon-picker-title">
<div class="catalogue-addon-heading"><h2 id="addon-picker-title">Pilih tambahan dari katalog</h2><button class="button button-ghost" type="button" data-close-picker aria-label="Tutup pencarian tambahan">Tutup</button></div>
<label>Cari tambahan<input class="input" type="search" data-picker-search placeholder="Nama, kode, atau jenis layanan"></label><small class="muted">Pilihan yang dipasang pada baris lain tidak ditampilkan.</small><p class="muted" data-picker-count></p><div class="catalogue-picker-results" data-picker-results></div>
</dialog>
<script type="application/json" id="addon-catalogue-data">@json($addonChoices)</script>
@endsection
@push('scripts')<script src="{{ asset('js/admin-services.js') }}" defer></script>@endpush
