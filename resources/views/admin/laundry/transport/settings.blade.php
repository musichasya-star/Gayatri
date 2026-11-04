@extends('layouts.app')
@section('title', 'Pengaturan Ongkir Laundry')
@section('content')
<x-ui.page-header eyebrow="Laundry Ibu & Bayi" title="Pengaturan Ongkir Laundry" description="Atur tarif jemput dan antar secara mandiri untuk laundry." />
@include('admin.laundry.nav')
<form class="laundry-form laundry-grid" method="POST" action="{{ route('admin.laundry.transport.settings.update') }}">@csrf @method('PUT')
<section class="card"><div class="card-body">
    <input type="hidden" name="enabled" value="0"><label class="laundry-check"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $settings['enabled']))> Aktifkan layanan antar-jemput laundry</label>
    <label>Metode ongkir<select class="input" name="mode"><option value="flat" @selected(old('mode', $settings['mode']) === 'flat')>Tarif tetap per kunjungan</option><option value="per_km" @selected(old('mode', $settings['mode']) === 'per_km')>Tarif per kilometer</option></select></label>
    <label>Batas jarak layanan (km)<input class="input" type="number" name="max_distance_km" min="0.001" max="500" step="0.001" value="{{ old('max_distance_km', $settings['max_distance_km']) }}" required></label>
    <div data-laundry-location>
        <label>Latitude outlet<input class="input" type="number" name="origin_latitude" data-latitude min="-90" max="90" step="any" value="{{ old('origin_latitude', $settings['origin_latitude']) }}"></label>
        <label>Longitude outlet<input class="input" type="number" name="origin_longitude" data-longitude min="-180" max="180" step="any" value="{{ old('origin_longitude', $settings['origin_longitude']) }}"></label>
        <button class="button button-secondary" type="button" data-laundry-locate>Gunakan Lokasi Perangkat sebagai Outlet</button><p class="muted" data-location-status role="status"></p>
    </div>
    <p class="laundry-notice">Perhitungan koordinat memakai jarak garis lurus dari outlet, bukan rute jalan. Admin dapat memilih jarak jalan manual saat membuat jadwal dan mencatat sumbernya. Jarak dibulatkan ke atas per 0,1 km; batas layanan berlaku pada jarak tersebut.</p>
</div></section>
<section class="card"><div class="card-body">
    @foreach(['pickup_flat_fee' => 'Tarif tetap jemput (Rp)', 'delivery_flat_fee' => 'Tarif tetap antar (Rp)', 'pickup_rate_per_km' => 'Tarif jemput per km (Rp)', 'delivery_rate_per_km' => 'Tarif antar per km (Rp)', 'minimum_fee' => 'Minimum ongkir per kunjungan untuk tarif/km (Rp)'] as $key => $label)
        <label>{{ $label }}<input class="input" type="number" name="{{ $key }}" min="0" max="10000000" step="1" value="{{ old($key, $settings[$key]) }}" required></label>
    @endforeach
    <p class="muted">Tarif tetap memakai nominal tetap dalam radius layanan. Tarif/km memakai maksimum antara minimum ongkir dan jarak × tarif, dibulatkan ke rupiah atas. Angka 0 berarti bebas biaya sesuai pengaturan.</p>
    <button class="button button-primary">Simpan Pengaturan Ongkir</button>
</div></section>
</form>
@endsection
@push('scripts')<script src="{{ asset('js/laundry.js') }}" defer></script>@endpush
