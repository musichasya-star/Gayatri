@php
    $isHome = old('service_mode', $booking?->service_mode ?? 'clinic') === 'home';
    $canPreserve = $booking?->service_mode === 'home';
    $priceOptions = $services->mapWithKeys(fn ($item) => [$item->id => (float) $item->price]);
    $addonPrices = $services->flatMap(fn ($item) => $item->activeAddOns)->mapWithKeys(fn ($item) => [$item->id => (float) $item->price_adjustment]);
    if ($canPreserve) {
        foreach ($booking->addOns as $addon) {
            $addonPrices[$addon->service_addon_id] = (float) $addon->price;
        }
    }
@endphp
<div class="home-service-fields" style="grid-column:1/-1;" data-home-service data-quote-url="{{ route('landing.home-service.quote') }}" data-prices='@json($priceOptions)' data-addon-prices='@json($addonPrices)' data-services='@json($homeSettings["service_ids"])' data-therapists='@json($homeSettings["therapist_ids"])' data-preserved="{{ $canPreserve ? '1' : '0' }}" data-saved-service="{{ $booking?->service_id }}" data-saved-lat="{{ $booking?->visit_latitude }}" data-saved-lng="{{ $booking?->visit_longitude }}" data-saved-fee="{{ $booking?->travel_fee ?? 0 }}" data-saved-price="{{ $booking?->service_price_snapshot }}">
    <label class="home-label">Tempat pelayanan
        <select class="input" name="service_mode">
            <option value="clinic" @selected(!$isHome)>Datang ke klinik</option>
            @if($homeSettings['enabled'] || $canPreserve)<option value="home" @selected($isHome)>Home Service — Terapis ke rumah</option>@endif
        </select>
    </label>
    @if(!$homeSettings['enabled'] && !$canPreserve)<p class="muted">Home Service belum diaktifkan admin.</p>@endif
    <div data-home-panel @if(!$isHome) hidden @endif>
        <p class="muted">Isi alamat lengkap dan tentukan pin lokasi kunjungan. Ongkos dihitung dari {{ $homeSettings['office_name'] }} melalui jalan, maksimal {{ $homeSettings['max_distance_km'] }} km satu arah.</p>
        <div class="grid grid-2">
            <label class="home-label">Alamat lengkap kunjungan<textarea class="input" name="visit_address" minlength="10" maxlength="2000" placeholder="Jalan, nomor rumah, RT/RW, kelurahan, kecamatan, kota">{{ old('visit_address', $booking?->visit_address) }}</textarea></label>
            <label class="home-label">Patokan / detail akses<textarea class="input" name="visit_landmark" maxlength="500" placeholder="Warna pagar, lantai, gang masuk, atau patokan">{{ old('visit_landmark', $booking?->visit_landmark) }}</textarea></label>
        </div>
        <div data-location-picker data-center-lat="{{ $homeSettings['latitude'] }}" data-center-lng="{{ $homeSettings['longitude'] }}">
            <button type="button" class="button button-secondary btn btn-soft" data-locate>Gunakan lokasi saya</button>
            <p class="muted">Lokasi saat ini hanya diambil setelah Anda mengizinkan. Klik peta atau geser pin jika alamat kunjungan berbeda. Koordinat juga bisa diisi manual.</p>
            <div class="grid grid-2"><label class="home-label">Latitude tujuan<input class="input" type="number" step="any" min="-90" max="90" name="visit_latitude" data-lat value="{{ old('visit_latitude', $booking?->visit_latitude) }}"></label><label class="home-label">Longitude tujuan<input class="input" type="number" step="any" min="-180" max="180" name="visit_longitude" data-lng value="{{ old('visit_longitude', $booking?->visit_longitude) }}"></label></div>
            <div data-location-map data-error-tile="{{ asset('images/map-unavailable.svg') }}" style="height:290px;border-radius:16px;z-index:0;margin-top:1rem;" aria-label="Peta tujuan kunjungan"></div>
            <p data-location-status role="status" class="muted"></p>
        </div>
        <p class="muted">Saat menghitung ongkos, koordinat tujuan dikirim ke penyedia rute. Alamat lengkap tetap disimpan di Gayatri.</p>
        <input type="hidden" name="home_quote_token" value="">
        <button type="button" class="button button-secondary btn btn-soft" data-calculate>Hitung ongkos perjalanan</button>
        <div data-home-summary class="home-summary" aria-live="polite">{{ $canPreserve ? 'Ongkos tersimpan: Rp '.number_format((float) $booking->travel_fee, 0, ',', '.').'. Tetap berlaku jika titik tujuan dan layanan tidak berubah.' : 'Pilih layanan dan pin tujuan, lalu hitung ongkos sebelum mengirim booking.' }}</div>
        @if($canPreserve)<a class="button button-secondary btn btn-soft" href="{{ $booking->navigationUrl() }}" target="_blank" rel="noopener">Buka navigasi lokasi tersimpan</a>@endif
    </div>
</div>
@include('partials.home-service-assets')
