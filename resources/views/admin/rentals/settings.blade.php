@extends('layouts.app')
@section('title', 'Pengaturan Rental - Gayatri CRM')
@section('content')
    <x-ui.page-header eyebrow="Rental Perlengkapan" title="Pengaturan Rental" description="Atur pemesanan, lokasi pengambilan, serta tarif antar dan jemput rental." />
    @include('admin.rentals.partials.navigation')
    <form class="rental-form" method="POST" action="{{ route('admin.rentals.settings.update') }}">@csrf @method('PUT')
        <div class="card"><div class="card-body rental-grid">
            <label>Pemesanan rental<select class="input" name="enabled"><option value="1" @selected(old('enabled', $settings['enabled']))>Aktif</option><option value="0" @selected(!old('enabled', $settings['enabled']))>Nonaktif</option></select></label>
            <label>Antar-jemput rental<select class="input" name="delivery_enabled"><option value="0" @selected(!old('delivery_enabled', $settings['delivery_enabled']))>Nonaktif — ambil sendiri</option><option value="1" @selected(old('delivery_enabled', $settings['delivery_enabled']))>Aktif</option></select></label>
            <label>Batas konfirmasi permintaan (jam)<input class="input" name="hold_hours" type="number" min="1" max="72" value="{{ old('hold_hours', $settings['hold_hours']) }}" required></label>
            <label>Maksimal lama sewa (hari)<input class="input" name="max_rental_days" type="number" min="1" max="365" value="{{ old('max_rental_days', $settings['max_rental_days']) }}" required></label>
            <label>Pantau kesiapan pesanan untuk berapa hari ke depan<input class="input" name="ops_lookahead_days" type="number" min="1" max="60" value="{{ old('ops_lookahead_days', $settings['ops_lookahead_days']) }}" required></label>
            <label>Nama lokasi pengambilan<input class="input" name="office_name" maxlength="150" value="{{ old('office_name', $settings['office_name']) }}" required></label>
            <label>Alamat pengambilan<textarea class="input" name="office_address" maxlength="2000">{{ old('office_address', $settings['office_address']) }}</textarea></label>
            <div class="rental-wide" data-location-picker><button type="button" class="button button-secondary" data-locate>Gunakan lokasi saya untuk kantor</button>
                <div class="rental-grid"><label>Latitude kantor rental<input class="input" data-lat name="latitude" type="number" step="any" min="-90" max="90" value="{{ old('latitude', $settings['latitude']) }}"></label><label>Longitude kantor rental<input class="input" data-lng name="longitude" type="number" step="any" min="-180" max="180" value="{{ old('longitude', $settings['longitude']) }}"></label></div>
                <div data-location-map data-error-tile="{{ asset('images/map-unavailable.svg') }}" style="height:300px;z-index:0;border-radius:16px;" aria-label="Peta kantor rental"></div><p class="muted" data-location-status role="status"></p>
            </div>
        </div></div>
        <div class="card" style="margin-top:1rem;"><div class="card-body rental-grid">
            <h2 class="rental-wide">DP & Pelunasan</h2><p class="muted rental-wide">DP dihitung dari biaya sewa dan ongkir, di luar deposit. Aturan disimpan saat pemesanan; perubahan pengaturan berlaku untuk pesanan baru. Bukti transfer memerlukan verifikasi admin.</p>
            <label>Jenis DP<select class="input" name="dp_type">@foreach(['none'=>'Tanpa DP minimum','percent'=>'Persentase biaya jasa','fixed'=>'Nominal tetap'] as $key=>$label)<option value="{{ $key }}" @selected(old('dp_type', $settings['dp_type']) === $key)>{{ $label }}</option>@endforeach</select></label>
            <label>Nilai DP (% atau Rp sesuai jenis)<input class="input" type="number" name="dp_value" min="0" step="1" value="{{ old('dp_value', $settings['dp_value']) }}" required></label>
            <label>Batas DP setelah konfirmasi (jam)<input class="input" type="number" name="dp_due_hours" min="1" max="168" value="{{ old('dp_due_hours', $settings['dp_due_hours']) }}" required><span class="muted rental-small">Mengikuti batas pelunasan jika tenggat tersebut lebih awal.</span></label>
            <label>Pelunasan berapa hari sebelum mulai sewa<input class="input" type="number" name="settlement_days_before" min="0" max="30" value="{{ old('settlement_days_before', $settings['settlement_days_before']) }}" required><span class="muted rental-small">0 berarti tanggal mulai sewa. Tenggat sampai pukul 23:59 WIB.</span></label>
            <label>Wajib lunas sebelum serah-terima<select class="input" name="require_full_payment"><option value="0" @selected(!old('require_full_payment', $settings['require_full_payment']))>Tidak wajib</option><option value="1" @selected(old('require_full_payment', $settings['require_full_payment']))>Wajib diverifikasi lunas</option></select></label>
            <label class="rental-wide">Instruksi pembayaran untuk pelanggan<textarea class="input" name="payment_instructions" maxlength="3000" rows="4" placeholder="Nama bank, nomor rekening, nama pemilik rekening, dan petunjuk transfer">{{ old('payment_instructions', $settings['payment_instructions']) }}</textarea></label>
            <p class="muted rental-wide">Tenggat pembayaran memberi peringatan; pesanan tidak dibatalkan otomatis. Admin dapat meninjau bukti yang masih menunggu verifikasi sebelum mengambil keputusan.</p>
        </div></div>
        <div class="card" style="margin-top:1rem;"><div class="card-body rental-grid">
            <h2 class="rental-wide">Ketentuan sewa, pembatalan & denda</h2>
            <p class="muted rental-wide">Disimpan bersama pesanan baru dan disetujui pelanggan saat checkout atau melalui portal pribadi sebelum serah-terima. Perubahan di sini tidak mengganti ketentuan pesanan lama.</p>
            <label class="rental-wide">Ketentuan sewa untuk pelanggan<textarea class="input" name="rental_terms" rows="6" minlength="20" maxlength="10000" required>{{ old('rental_terms', $settings['rental_terms']) }}</textarea></label>
            <label>Denda per hari kalender per unit (Rp)<input class="input" name="late_fee_daily" type="number" min="0" max="100000000" step="1" value="{{ old('late_fee_daily', $settings['late_fee_daily']) }}" required></label>
            <label>Toleransi keterlambatan (hari)<input class="input" name="late_fee_grace_days" type="number" min="0" max="30" step="1" value="{{ old('late_fee_grace_days', $settings['late_fee_grace_days']) }}" required></label>
            <label>Batas total denda per unit (Rp)<input class="input" name="late_fee_cap" type="number" min="0" max="100000000" step="1" value="{{ old('late_fee_cap', $settings['late_fee_cap']) }}" required><span class="muted rental-small">0 = tanpa batas khusus, maksimal sistem Rp 100.000.000/unit.</span></label>
            <label>Jenis potongan pembatalan<select class="input" name="cancellation_fee_type">@foreach(['none'=>'Tanpa potongan','percent'=>'Persentase biaya jasa','fixed'=>'Nominal tetap per pesanan'] as $key=>$label)<option value="{{ $key }}" @selected(old('cancellation_fee_type', $settings['cancellation_fee_type']) === $key)>{{ $label }}</option>@endforeach</select></label>
            <label>Nilai potongan (% atau Rp sesuai jenis)<input class="input" name="cancellation_fee_value" type="number" min="0" max="100000000" step="1" value="{{ old('cancellation_fee_value', $settings['cancellation_fee_value']) }}" required></label>
            <label>Gratis jika diajukan minimal berapa hari sebelum mulai<input class="input" name="cancellation_free_days" type="number" min="0" max="365" step="1" value="{{ old('cancellation_free_days', $settings['cancellation_free_days']) }}" required><span class="muted rental-small">0 = tidak ada periode pembebasan khusus. Hari dihitung menurut tanggal WIB.</span></label>
            <p class="muted rental-wide">Potongan pembatalan dihitung dari biaya sewa dan ongkir, tanpa deposit sebagai dasar. Potongan maksimal sebesar biaya jasa dan dana diterima, sehingga pembatalan tidak menimbulkan tagihan baru. Denda hanya menjadi tagihan setelah hasil pemeriksaan disimpan admin; perubahan dari usulan perlu alasan.</p>
        </div></div>
        <div class="card" style="margin-top:1rem;"><div class="card-body rental-grid">
            <h2 class="rental-wide">Tarif antar & jemput</h2>
            @foreach(['delivery_rate_per_km' => 'Tarif antar per kilometer (Rp)', 'delivery_base_fee' => 'Biaya dasar antar (Rp)', 'collection_rate_per_km' => 'Tarif jemput per kilometer (Rp)', 'collection_base_fee' => 'Biaya dasar jemput (Rp)'] as $field => $label)<label>{{ $label }}<input class="input" name="{{ $field }}" type="number" min="0" step="1" value="{{ old($field, $settings[$field]) }}" required></label>@endforeach
            <label>Jarak gratis tiap layanan (km)<input class="input" type="number" name="free_km" step="0.1" min="0" max="500" value="{{ old('free_km', $settings['free_km']) }}" required></label>
            <label>Jangkauan antar-jemput maksimal (km)<input class="input" type="number" name="max_distance_km" step="0.1" min="0.1" max="500" value="{{ old('max_distance_km', $settings['max_distance_km']) }}" required></label>
            <label>Penyedia rute<select class="input" name="routing_provider"><option value="osrm" @selected(old('routing_provider', $settings['routing_provider']) === 'osrm')>OSRM publik — uji lokal</option><option value="osrm_private" @selected(old('routing_provider', $settings['routing_provider']) === 'osrm_private')>OSRM sendiri</option></select></label>
            <label>Base URL OSRM sendiri<input class="input" name="routing_base_url" type="url" placeholder="https://rute.example.com" value="{{ old('routing_base_url', $settings['routing_base_url']) }}"></label>
            <p class="muted rental-wide">Setiap ongkos = biaya dasar + pembulatan ke atas (jarak jalan satu arah − jarak gratis) × tarif/km. Antar dan jemput dihitung terpisah sesuai pilihan pelanggan. Pengaturan rental terpisah dari tarif Home Service terapis.</p>
            <button class="button button-primary rental-wide">Simpan Pengaturan Rental</button>
        </div></div>
    </form>
    @include('partials.home-service-assets')
@endsection
