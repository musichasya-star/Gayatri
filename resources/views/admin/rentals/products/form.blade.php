@extends('layouts.app')
@section('title', 'Kelola Alat Rental - Gayatri CRM')
@section('content')
    <x-ui.page-header eyebrow="Rental Perlengkapan" title="{{ $product->exists ? 'Edit Alat Rental' : 'Tambah Alat Rental' }}" description="Harga dalam rupiah per unit. Tarif kosong membuat pilihan periode tersebut tidak tersedia." />
    @include('admin.rentals.partials.navigation')
    <div class="card"><div class="card-body"><form class="rental-form rental-grid" method="POST" enctype="multipart/form-data" action="{{ $product->exists ? route('admin.rentals.products.update', $product) : route('admin.rentals.products.store') }}">
        @csrf @include('partials.admin-review-fields', ['reviewModule'=>'rental-products']) @if($product->exists) @method('PUT') @endif
        <label>Kode produk / SKU<input class="input" name="sku" maxlength="50" value="{{ old('sku', $product->sku) }}" required></label>
        <label>Nama alat<input class="input" name="name" maxlength="150" value="{{ old('name', $product->name) }}" required></label>
        <label>Kategori<input class="input" name="category" maxlength="100" placeholder="Stroller, kursi makan, tempat tidur…" value="{{ old('category', $product->category) }}" required></label>
        <label>Foto alat (JPG, PNG, WebP; maksimal 5 MB)<input class="input" type="file" name="photo" accept="image/jpeg,image/png,image/webp"></label>
        @foreach(['daily_rate' => 'Tarif harian (Rp; 0 bila tidak ditawarkan)', 'weekly_rate' => 'Tarif mingguan / 7 hari (Rp)', 'fortnightly_rate'=>'Tarif dua minggu / 14 hari (Rp)', 'monthly_rate' => 'Tarif bulanan / 30 hari (Rp)', 'deposit' => 'Deposit per unit (Rp; kosong bila belum dikonfirmasi)'] as $field => $label)
            <label>{{ $label }}<input class="input" name="{{ $field }}" type="number" min="0" step="1" value="{{ old($field, $product->$field === null ? '' : (int) $product->$field) }}" @required($field==='daily_rate')></label>
        @endforeach
        @if($product->requiresConfirmation())<p class="rental-wide rental-alert">Katalog ini masih memerlukan konfirmasi deposit dan jeda perawatan. Simpan angka yang sudah dikonfirmasi dan masukkan unit fisik sebelum menerima pesanan.</p>@endif
        <label>Jeda pembersihan setelah tanggal kembali (hari)<input class="input" name="turnaround_days" type="number" min="0" max="30" value="{{ old('turnaround_days', $product->turnaround_days) }}" required></label>
        @if(data_get($product->catalog_metadata,'turnaround_unconfirmed'))<label class="rental-check"><input type="hidden" name="confirm_turnaround" value="0"><input type="checkbox" name="confirm_turnaround" value="1" @checked(old('confirm_turnaround',false))> Saya sudah memastikan jeda pembersihan di atas. Menyimpan perubahan lain tidak mengonfirmasi angka ini.</label>@endif
        <label>Publikasi katalog<select class="input" name="is_active"><option value="1" @selected(old('is_active', $product->is_active))>Tayang</option><option value="0" @selected(!old('is_active', $product->is_active))>Diarsipkan</option></select></label>
        <label class="rental-wide">Deskripsi<textarea class="input" name="description" maxlength="5000" rows="4">{{ old('description', $product->description) }}</textarea></label>
        <label>Kelengkapan yang disertakan<textarea class="input" name="components" maxlength="3000" rows="4" placeholder="Contoh: kanopi, sabuk pengaman, tas penyimpanan">{{ old('components', $product->components) }}</textarea></label>
        <label>Ketentuan sewa & penggunaan<textarea class="input" name="terms" maxlength="5000" rows="4">{{ old('terms', $product->terms) }}</textarea></label>
        <label class="rental-wide">Checklist pemeriksaan (satu butir per baris)<textarea class="input" name="inspection_checklist_text" maxlength="6000" rows="5" placeholder="Kelengkapan sesuai daftar&#10;Rangka dan pengunci berfungsi&#10;Permukaan bersih dan kering">{{ old('inspection_checklist_text', implode("\n", $product->inspection_checklist ?? ($product->exists ? [] : ['Kelengkapan sesuai daftar', 'Rangka dan pengunci berfungsi', 'Permukaan bersih dan kering']))) }}</textarea><span class="muted rental-small">Jika diisi, semua butir wajib diperiksa sebelum keluar dan saat pengembalian. Checklist transaksi disimpan mengikuti produk saat pesanan dibuat. Maksimal 30 butir.</span></label>
        <div class="rental-wide rental-actions"><button class="button button-primary">Simpan Alat</button><a class="button button-secondary" href="{{ \App\Support\AdminReviewContext::back(request(),'rental-products') }}">Kembali</a></div>
    </form></div></div>
@endsection
