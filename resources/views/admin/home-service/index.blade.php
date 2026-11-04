@extends('layouts.app')
@section('title', 'Home Service - Gayatri CRM')
@section('content')
@include('partials.admin-operations-assets')
    <x-ui.page-header eyebrow="Pelayanan ke rumah" title="Home Service" description="Atur lokasi kantor, ongkos perjalanan, dan jangkauan layanan terapis.">
        <x-slot name="actions"><a class="button button-secondary" href="{{ route('landing.booking-page') }}" target="_blank" rel="noopener">Lihat form booking</a></x-slot>
    </x-ui.page-header>
    @if(session('status'))<div class="alert alert-success" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="card" role="alert"><div class="card-body">{{ $errors->first() }}</div></div>@endif
    <nav class="ops-nav" data-record-nav aria-label="Bagian pengaturan Home Service"><a href="#lokasi">Lokasi</a><a href="#tarif">Tarif & jangkauan</a><a href="#layanan">Layanan</a><a href="#terapis">Terapis</a><a href="#rute">Penyedia rute</a></nav>
    <form method="POST" action="{{ route('admin.home-service.update') }}" class="grid" style="gap:1rem;">
        @csrf @method('PUT')<input type="hidden" name="selection_submitted" value="1">
        <details class="ops-section" open id="lokasi"><summary>1. Status & lokasi kantor</summary><div class="ops-section-body ops-fields">
            <label><strong>Status Home Service</strong><select class="input" name="enabled"><option value="0" @selected(!old('enabled', $settings['enabled']))>Nonaktif</option><option value="1" @selected(old('enabled', $settings['enabled']))>Aktif</option></select></label>
            <p class="muted">Aktifkan setelah lokasi, tarif, layanan, dan terapis terisi. Booking masuk sebagai permintaan; admin mengonfirmasi dan menentukan terapis.</p>
            <label>Nama kantor<input class="input" name="office_name" maxlength="150" value="{{ old('office_name', $settings['office_name']) }}" required></label>
            <label>Alamat kantor<textarea class="input" name="office_address" maxlength="2000">{{ old('office_address', $settings['office_address']) }}</textarea></label>
            <div style="grid-column:1/-1;" data-location-picker>
                <p class="muted">Klik peta atau geser pin ke titik kantor. Anda juga dapat memasukkan koordinat secara manual.</p>
                <button type="button" class="button button-secondary" data-locate>Gunakan lokasi saya untuk kantor</button>
                <div class="grid grid-2" style="margin:1rem 0;"><label>Latitude kantor<input class="input" type="number" step="any" min="-90" max="90" name="latitude" data-lat value="{{ old('latitude', $settings['latitude']) }}"></label><label>Longitude kantor<input class="input" type="number" step="any" min="-180" max="180" name="longitude" data-lng value="{{ old('longitude', $settings['longitude']) }}"></label></div>
                <div data-location-map data-error-tile="{{ asset('images/map-unavailable.svg') }}" style="height:300px;border-radius:16px;z-index:0;" aria-label="Peta lokasi kantor"></div>
                <p class="muted" data-location-status role="status"></p>
            </div>
        </div></details>
        <details class="ops-section" open id="tarif"><summary>2. Tarif & jangkauan</summary><div class="ops-section-body ops-fields">

            <label>Biaya per kilometer (Rp)<input class="input" name="rate_per_km" type="number" min="0" max="10000000" step="1" placeholder="Isi tarif Anda" value="{{ old('rate_per_km', $settings['rate_per_km']) }}"></label>
            <label>Jarak layanan maksimal (km)<input class="input" name="max_distance_km" type="number" min="0.1" max="500" step="0.1" placeholder="Isi batas jarak layanan" value="{{ old('max_distance_km', $settings['max_distance_km']) }}"></label>
            <label>Biaya dasar kunjungan (Rp)<input class="input" name="base_fee" type="number" min="0" max="10000000" value="{{ old('base_fee', $settings['base_fee']) }}" required></label>
            <label>Jarak gratis (km)<input class="input" name="free_km" type="number" min="0" max="500" step="0.1" value="{{ old('free_km', $settings['free_km']) }}" required></label>
            <label>Waktu persiapan tambahan per arah (menit)<input class="input" name="buffer_minutes" type="number" min="0" max="180" value="{{ old('buffer_minutes', $settings['buffer_minutes']) }}" required></label>
            <p class="muted">Ongkos = biaya dasar + pembulatan ke atas (jarak jalan satu arah − jarak gratis) × tarif/km. Batas layanan memakai jarak jalan satu arah. Jadwal terapis mencadangkan waktu pergi dan pulang, masing-masing ditambah waktu persiapan.</p>
        </div></details>
        <details class="ops-section" open id="layanan"><summary>3. Layanan ke rumah</summary><div class="ops-section-body">
            <p class="muted">Cari keluarga layanan atau variasinya. Pilihan yang disaring tetap tersimpan. Paket beberapa kunjungan dan layanan yang belum lengkap ditangani melalui konfirmasi admin.</p>
            @if($staleServices->isNotEmpty())<div class="ops-notice"><strong>Pilihan lama perlu ditinjau</strong><p>Layanan berikut sudah tidak tersedia untuk booking langsung. Lepaskan centangnya sebelum menyimpan, atau lengkapi katalog dahulu.</p>@foreach($staleServices as $id=>$name)<label class="ops-choice-row"><input type="checkbox" name="service_ids[]" value="{{ $id }}" checked>{{ $name }} · belum siap / tidak aktif</label>@endforeach</div>@endif
            <div data-home-selector>
                <div class="ops-choice-tools"><input class="input" type="search" data-choice-search aria-label="Cari layanan ke rumah" placeholder="Cari layanan atau variasi…"><select class="input" data-choice-group-filter aria-label="Kelompok layanan ke rumah"><option value="">Semua kelompok</option>@foreach(\App\Support\ServiceGroup::LABELS as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></div>
                <div class="ops-choice-tools"><label><input type="checkbox" data-choice-selected> Hanya yang dipilih</label><button class="button button-secondary" type="button" data-choice-bulk="select">Pilih hasil terlihat</button><button class="button button-ghost" type="button" data-choice-bulk="clear">Lepaskan hasil terlihat</button><span role="status" data-choice-count></span></div>
                <div class="ops-choice-list" aria-label="Pilihan layanan ke rumah" tabindex="0">
                @foreach($services->groupBy(fn($service)=>$service->service_group.'|'.$service->familyName()) as $family)
                    <details class="ops-choice-group" data-choice-family><summary>{{ $family->first()->familyName() }} · {{ $family->count() }} variasi</summary>
                    @foreach($family as $service)<label class="ops-choice-row" data-choice-row data-search="{{ $service->name.' '.$service->familyName() }}" data-group="{{ $service->service_group }}"><input type="checkbox" name="service_ids[]" value="{{ $service->id }}" @checked(in_array($service->id,$selectedServices))><span>{{ $service->name }}<small>{{ \App\Support\ServiceGroup::LABELS[$service->service_group] ?? $service->service_group }} · {{ $service->durationLabel() }} · Rp {{ number_format((float)$service->price,0,',','.') }}</small></span></label>@endforeach
                    </details>
                @endforeach
                @if($services->isEmpty())<p class="muted">Belum ada layanan aktif yang siap dibooking.</p>@endif
                </div>
            </div>
        </div></details>
        <details class="ops-section" open id="terapis"><summary>4. Terapis ke rumah</summary><div class="ops-section-body">
            @if($staleTherapists->isNotEmpty())<div class="ops-notice"><strong>Terapis lama tidak aktif. Tinjau dan lepaskan pilihan sebelum menyimpan.</strong>@foreach($staleTherapists as $id=>$name)<label class="ops-choice-row"><input type="checkbox" name="therapist_ids[]" value="{{ $id }}" checked>{{ $name }}</label>@endforeach</div>@endif
            <div data-home-selector><div class="ops-choice-tools"><input class="input" type="search" data-choice-search aria-label="Cari terapis ke rumah" placeholder="Cari nama terapis…"><span data-choice-count role="status"></span></div><div class="ops-choice-list">@forelse($therapists as $therapist)<label class="ops-choice-row" data-choice-row data-search="{{ $therapist->name }}"><input type="checkbox" name="therapist_ids[]" value="{{ $therapist->id }}" @checked(in_array($therapist->id,$selectedTherapists))>{{ $therapist->name }}</label>@empty<p>Belum ada terapis aktif.</p>@endforelse</div></div>
        </div></details>
        <details class="ops-section" id="rute"><summary>Pengaturan lanjutan · penyedia rute</summary><div class="ops-section-body ops-fields">
            <label>Penyedia perhitungan rute<select class="input" name="routing_provider"><option value="osrm" @selected(old('routing_provider', $settings['routing_provider']) === 'osrm')>OSRM publik — uji coba lokal</option><option value="osrm_private" @selected(old('routing_provider', $settings['routing_provider']) === 'osrm_private')>OSRM sendiri — operasional</option></select></label>
            <label>Base URL OSRM sendiri (opsional)<input class="input" type="url" name="routing_base_url" placeholder="https://rute.example.com" value="{{ old('routing_base_url', $settings['routing_base_url']) }}"></label>
            <p class="muted" style="grid-column:1/-1;">Pengujian lokal memakai OSRM publik tanpa API key. Untuk operasional, admin dapat memilih endpoint OSRM sendiri. Koordinat kantor dan tujuan dikirim ke penyedia rute saat pelanggan menghitung ongkos; alamat lengkap tidak dikirim.</p>
        </div></details>
        <div class="ops-save"><small>Tarif baru berlaku untuk penawaran baru. Ongkos booking lama tetap tersimpan.</small><button class="button button-primary" type="submit">Simpan Home Service</button></div>
    </form>
    @include('partials.home-service-assets')
@endsection
