@extends('layouts.app')
@section('title', ($tariff->exists ? 'Edit' : 'Tambah').' Tarif Laundry - Gayatri')
@section('content')
<x-ui.page-header eyebrow="Laundry Ibu & Bayi" title="{{ $tariff->exists ? 'Edit Tarif Laundry' : 'Tambah Tarif Laundry' }}" description="Tarif disalin ke pesanan pada saat penerimaan agar riwayat harga tetap terjaga." />
@include('admin.laundry.nav')
<section class="card"><div class="card-body"><form class="laundry-form laundry-grid" method="POST" action="{{ $tariff->exists ? route('admin.laundry.tariffs.update', $tariff) : route('admin.laundry.tariffs.store') }}">@csrf @include('partials.admin-review-fields', ['reviewModule'=>'laundry-tariffs']) @if($tariff->exists) @method('PUT') @endif
    <label>Kode tarif<input class="input" name="code" maxlength="40" value="{{ old('code', $tariff->code) }}" placeholder="Contoh: BAYI-CUCI-LIPAT" required></label><label>Nama layanan<input class="input" name="name" maxlength="150" value="{{ old('name', $tariff->name) }}" required></label>
    <label>Layanan untuk<select class="input" name="audience" required>@foreach(\App\Models\LaundryTariff::AUDIENCES as $key => $label)<option value="{{ $key }}" @selected(old('audience', $tariff->audience) === $key)>{{ $label }}</option>@endforeach</select></label>
    <label>Satuan tarif<select class="input" name="unit" data-laundry-tariff-unit required>@foreach(\App\Models\LaundryTariff::UNITS as $key => $label)<option value="{{ $key }}" @selected(old('unit', $tariff->unit) === $key)>{{ $label }}</option>@endforeach</select></label>
    <label>Harga per satuan (Rp)<input class="input" name="price" type="number" min="1" max="10000000" step="1" value="{{ old('price', $tariff->price) }}" required></label>
    <label>Minimum yang ditagihkan<input class="input" name="minimum_quantity" type="number" min="0.001" max="1000" step="0.001" value="{{ old('minimum_quantity', $tariff->minimum_milli===null?'':$tariff->minimum_milli / 1000) }}"></label>
    <label>Kelipatan pembulatan ke atas<input class="input" name="rounding_increment" type="number" min="0.001" max="1000" step="0.001" value="{{ old('rounding_increment', $tariff->increment_milli===null?'':$tariff->increment_milli / 1000) }}"></label>
    <label>Estimasi pengerjaan (jam kalender)<input class="input" name="duration_hours" type="number" min="1" max="720" value="{{ old('duration_hours', $tariff->duration_hours) }}"></label>
    <input type="hidden" name="requires_confirmation" value="0"><label class="laundry-check"><input type="checkbox" name="requires_confirmation" value="1" @checked(old('requires_confirmation',$tariff->requires_confirmation))> Rincian operasional belum lengkap; konfirmasi admin sebelum penerimaan</label>
    <label class="laundry-wide">Deskripsi / cakupan layanan<textarea class="input" name="description" maxlength="3000">{{ old('description', $tariff->description) }}</textarea></label>
    <div class="laundry-wide laundry-notice">Perhitungan: ambil nilai terbesar antara jumlah/berat dan minimum, lalu bulatkan ke kelipatan di atas. Untuk tarif satuan, minimum wajib bilangan bulat dan kelipatan wajib 1 satuan. Minimum berlaku per kantong/baris layanan.</div>
    <input type="hidden" name="is_active" value="0"><label class="laundry-check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $tariff->is_active))> Tampilkan tarif di katalog. Penerimaan tetap mengikuti kesiapan rincian operasional.</label>
    <div class="laundry-wide laundry-actions"><button class="button button-primary">Simpan Tarif Laundry</button><a class="button button-secondary" href="{{ \App\Support\AdminReviewContext::back(request(),'laundry-tariffs') }}">Kembali ke Katalog</a></div>
</form></div></section>
<script src="{{ asset('js/laundry.js') }}" defer></script>
@endsection
