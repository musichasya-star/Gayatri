@extends('layouts.app')
@section('title', 'Pengaturan Outlet Laundry - Gayatri')
@section('content')
<x-ui.page-header eyebrow="Laundry Ibu & Bayi" title="Pengaturan Outlet Laundry" description="Identitas, kontak, jam operasional, dan ketentuan penerimaan laundry dikelola terpisah dari layanan lainnya." />
@include('admin.laundry.nav')
<div class="laundry-grid">
<section class="card"><div class="card-body"><form class="laundry-form" method="POST" action="{{ route('admin.laundry.settings.update') }}">@csrf @method('PUT')
    <input type="hidden" name="accepting_orders" value="0"><label class="laundry-check"><input type="checkbox" name="accepting_orders" value="1" @checked(old('accepting_orders', $settings['accepting_orders']))> Aktifkan penerimaan pesanan laundry</label>
    <label>Nama outlet<input class="input" name="outlet_name" maxlength="150" value="{{ old('outlet_name', $settings['outlet_name']) }}" required></label>
    <label>Alamat outlet<textarea class="input" name="address" maxlength="1500">{{ old('address', $settings['address']) }}</textarea></label>
    <label>WhatsApp outlet<input class="input" name="whatsapp_number" maxlength="20" inputmode="tel" placeholder="08… atau 628… tanpa spasi" value="{{ old('whatsapp_number', $settings['whatsapp_number']) }}"></label>
    <label>Jam operasional<textarea class="input" name="opening_hours" maxlength="500" placeholder="Contoh: Senin–Sabtu, 08.00–17.00 WIB">{{ old('opening_hours', $settings['opening_hours']) }}</textarea></label>
    <input type="hidden" name="allow_collection" value="0"><label class="laundry-check"><input type="checkbox" name="allow_collection" value="1" @checked(old('allow_collection', $settings['allow_collection']))> Izinkan pencatatan barang yang diterima melalui penjemputan</label>
    <p class="muted">Jadwal dan ongkos penjemputan dikonfirmasi admin kepada pelanggan. Nilai jasa laundry ditampilkan terpisah dari ongkos perjalanan.</p>
    <label>Ketentuan layanan<textarea class="input" name="terms" maxlength="5000" rows="6" placeholder="Isi ketentuan penerimaan, perawatan pakaian, pengecekan kondisi, dan konfirmasi perubahan biaya.">{{ old('terms', $settings['terms']) }}</textarea></label>
    <button class="button button-primary">Simpan Pengaturan Outlet</button>
</form></div></section>
<aside class="card"><div class="card-body"><h2 class="section-title">Mulai menerima laundry</h2><ol class="laundry-notes"><li>Lengkapi identitas, alamat, dan kontak outlet.</li><li>Tambahkan tarif per kilogram atau potong, lalu aktifkan tarif yang ditawarkan.</li><li>Aktifkan penerimaan dan catat barang pada menu Penerimaan & Penimbangan.</li><li>Timbang seluruh kantong, periksa selisih estimasi, lalu catat persetujuan nilai jasa dari pelanggan.</li></ol><p class="muted">Setiap pesanan menyimpan salinan identitas outlet, ketentuan, dan tarif saat diterima. Perubahan pengaturan tidak mengubah salinan pesanan sebelumnya.</p><p class="laundry-notice">Satuan berat menggunakan kilogram dengan ketelitian 0,001 kg. Minimum dan pembulatan berlaku per baris layanan/kantong. Nominal dibulatkan ke rupiah terdekat per baris.</p></div></aside>
</div>
@endsection
