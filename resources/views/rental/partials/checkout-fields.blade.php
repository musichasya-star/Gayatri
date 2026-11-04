@php($itemRows = old('items', $initialItems ?? [['product_id' => $fixedProduct?->id, 'quantity' => 1]]))
@unless(request()->routeIs('admin.*'))
    <div class="rental-alert"><strong>Pembayaran manual setelah konfirmasi admin.</strong> Mengirim permintaan ini belum melakukan pembayaran. Rincian DP/pelunasan dan instruksi transfer dapat dilihat di portal pribadi setelah pesanan dibuat. Unggah bukti setelah transfer; pembayaran tercatat setelah diverifikasi admin.</div>
@endunless
<div data-rental-items>
    @foreach($itemRows as $index => $row)<div class="rental-item-row" data-rental-row>
        <label>Alat sewa<select class="input" name="items[{{ $index }}][product_id]" required><option value="">Pilih alat</option>@foreach($products as $option)<option value="{{ $option->id }}" data-periods="{{ implode(',',array_keys($option->rateOptions())) }}" @selected((int) ($row['product_id'] ?? 0) === $option->id)>{{ $option->name }}</option>@endforeach</select></label>
        <label>Jumlah<input class="input" name="items[{{ $index }}][quantity]" type="number" min="1" max="20" value="{{ $row['quantity'] ?? 1 }}" required></label>
        @if(!$fixedProduct)<button type="button" class="button button-secondary" data-remove-rental-item>Hapus baris</button>@endif
    </div>@endforeach
</div>
@if(!$fixedProduct)<button class="button button-secondary" type="button" data-add-rental-item style="margin-top:.7rem;">Tambah Alat Lain</button>@endif
<div class="rental-grid">
    <label>Tanggal mulai sewa<input class="input" name="start_date" type="date" min="{{ today()->toDateString() }}" value="{{ old('start_date', request('start_date', today()->toDateString())) }}" required></label>
    <label>Tanggal kembali<input class="input" name="end_date" type="date" min="{{ today()->toDateString() }}" value="{{ old('end_date', request('end_date', today()->toDateString())) }}" required></label>
</div>
<p class="muted rental-small">Tanggal mulai dan kembali sama dihitung 1 hari. Tarif mingguan dibulatkan per 7 hari, dua mingguan per 14 hari, bulanan per 30 hari. Stok belum ditahan saat melihat estimasi.</p>
@php($periodChoices = $fixedProduct ? array_intersect_key(\App\Models\RentalOrder::PERIODS,$fixedProduct->rateOptions()) : \App\Models\RentalOrder::PERIODS)
<label>Pilihan tarif<select class="input" name="period">@foreach($periodChoices as $key => $label)<option value="{{ $key }}" @selected(old('period',array_key_first($periodChoices)) === $key)>{{ $label }}</option>@endforeach</select></label>
<label>Pengambilan & pengembalian<select class="input" name="fulfilment">@foreach(\App\Models\RentalOrder::FULFILMENTS as $key => $label)@continue($key !== 'self' && !$settings['delivery_enabled'])<option value="{{ $key }}" @selected(old('fulfilment', 'self') === $key)>{{ $label }}</option>@endforeach</select></label>
<p class="muted rental-small">Lokasi pengambilan: {{ $settings['office_name'] }} · {{ $settings['office_address'] ?: 'Alamat akan dikonfirmasi admin.' }}</p>
<div data-rental-address hidden>
    <label>Alamat lengkap antar / jemput<textarea class="input" name="address" minlength="10" maxlength="2000">{{ old('address') }}</textarea></label>
    <label>Patokan / detail akses<input class="input" name="landmark" maxlength="500" value="{{ old('landmark') }}"></label>
    <div data-location-picker data-center-lat="{{ $settings['latitude'] }}" data-center-lng="{{ $settings['longitude'] }}">
        <button class="button button-secondary" type="button" data-locate>Gunakan lokasi saya</button>
        <div class="rental-grid"><label>Latitude tujuan<input class="input" data-lat type="number" step="any" min="-90" max="90" name="latitude" value="{{ old('latitude') }}"></label><label>Longitude tujuan<input class="input" data-lng type="number" step="any" min="-180" max="180" name="longitude" value="{{ old('longitude') }}"></label></div>
        <div data-location-map data-error-tile="{{ asset('images/map-unavailable.svg') }}" style="height:280px;z-index:0;border-radius:16px;" aria-label="Peta tujuan antar-jemput rental"></div><p class="muted" data-location-status role="status"></p>
    </div>
    <p class="muted rental-small">Klik peta, geser pin, atau isi koordinat manual. Koordinat akan dikirim ke penyedia rute ketika menghitung ongkos; alamat lengkap tersimpan di Gayatri.</p>
</div>
@if($customerCheckout ?? false)
    <fieldset class="transaction-fields">
        <legend>Kontak penyewa</legend>
        <label>Nama penyewa<input class="input" name="name" maxlength="150" value="{{ old('name') }}" autocomplete="name" required></label>
        <label>Nomor WhatsApp<input class="input" name="whatsapp_number" maxlength="30" value="{{ old('whatsapp_number') }}" inputmode="tel" autocomplete="tel" required></label>
        <label>Email (opsional)<input class="input" type="email" name="email" maxlength="150" value="{{ old('email') }}" autocomplete="email"></label>
    </fieldset>
@endif
<label>Catatan pesanan (opsional)<textarea class="input" name="notes" maxlength="3000">{{ old('notes') }}</textarea></label>
<input type="hidden" name="quote_token" value="">
<button class="button button-secondary" type="button" data-rental-calculate>Cek Stok & Hitung Total</button>
<div class="rental-summary" data-rental-summary role="status" aria-live="polite">Pilih alat dan tanggal, lalu cek ketersediaan untuk melihat harga sewa, ongkos, serta deposit.</div>
@if($customerCheckout ?? false)
    <p data-rental-recovery class="muted" hidden>Periksa tanggal, jumlah, atau alat pada formulir ini, lalu hitung ulang. Isian kontak tetap tersedia.</p>
@endif
<details open><summary>Ketentuan sewa untuk pesanan ini</summary><div class="rental-summary" data-rental-terms>Cek stok dan hitung total untuk membaca ketentuan yang akan disimpan bersama pesanan.</div></details>
@if(!request()->routeIs('admin.*'))<label class="rental-check"><input type="checkbox" name="terms_accepted" value="1" data-terms-accept required disabled> Saya telah membaca dan menyetujui ketentuan sewa, aturan pembatalan/refund, dan denda yang ditampilkan di atas.</label>@else<p class="muted">Setelah pesanan dibuat, berikan tautan portal pribadi agar pelanggan membaca dan menyetujui ketentuan sebelum serah-terima.</p>@endif
@include('partials.home-service-assets')
@once<script src="{{ asset('js/rental.js') }}" defer></script>@endonce
