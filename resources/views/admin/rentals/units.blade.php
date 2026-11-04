@extends('layouts.app')
@section('title', 'Stok Unit Rental - Gayatri CRM')
@section('content')
    <x-ui.page-header eyebrow="Rental Perlengkapan" title="Stok Unit" description="Satu kode untuk setiap unit fisik. Unit yang kembali harus melewati pemeriksaan sebelum tersedia." />
    @include('admin.rentals.partials.navigation')
    <div class="card"><div class="card-body"><h2>Tambah Unit Fisik</h2><form class="rental-form rental-grid" method="POST" action="{{ route('admin.rentals.units.store') }}">@csrf
        <label>Produk<select class="input" name="rental_product_id" required><option value="">Pilih alat</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected(old('rental_product_id', request('product_id')) == $product->id)>{{ $product->name }}</option>@endforeach</select></label>
        <label>Kode unit<input class="input" name="code" maxlength="60" value="{{ old('code') }}" placeholder="STROLLER-001" required></label>
        <label>Nomor seri<input class="input" name="serial_number" maxlength="100" value="{{ old('serial_number') }}"></label>
        <label>Status awal<select class="input" name="status">@foreach(\App\Models\RentalUnit::STATUSES as $key => $label)@continue(in_array($key, ['rented', 'inspection']))<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
        <label class="rental-wide">Catatan kondisi & kelengkapan<textarea class="input" name="condition_notes" maxlength="3000" required>{{ old('condition_notes') }}</textarea></label>
        <button class="button button-primary">Tambah Unit</button>
        <label class="rental-check"><input type="checkbox" name="is_demo" value="1" @checked(old('is_demo'))> Unit demo — dikecualikan dari laporan operasional secara default.</label>
    </form></div></div>
    <form method="GET" class="rental-actions" style="margin:1rem 0;"><select class="input" name="product_id" aria-label="Filter produk"><option value="">Semua alat</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected(request('product_id') == $product->id)>{{ $product->name }}</option>@endforeach</select><button class="button button-secondary">Filter</button></form>
    <div class="rental-grid">@forelse($units as $unit)<div class="card"><div class="card-body">
        <h2>{{ $unit->code }}</h2><p>{{ $unit->product->name }}</p><span class="badge badge-gold">{{ \App\Models\RentalUnit::STATUSES[$unit->status] }}</span>
        @if($unit->is_demo)<span class="badge badge-gold">DEMO</span>@endif
        <p class="muted">{{ $unit->condition_notes }}<br>Seri: {{ $unit->serial_number ?: '-' }}</p>
        <p><a class="button button-secondary" href="{{ route('admin.rentals.care.show', $unit) }}">Perawatan & Riwayat</a></p>
        @if(in_array($unit->status, ['rented', 'inspection']))<p class="muted">Kelola melalui transaksi sewa untuk pengembalian dan pemeriksaan.</p>
        @else<details><summary>Edit kondisi / status</summary><form class="rental-form" method="POST" action="{{ route('admin.rentals.units.update', $unit) }}">@csrf @method('PUT')
            <input type="hidden" name="rental_product_id" value="{{ $unit->rental_product_id }}"><input type="hidden" name="code" value="{{ $unit->code }}">
            <input type="hidden" name="is_demo" value="0"><label class="rental-check"><input type="checkbox" name="is_demo" value="1" @checked($unit->is_demo)> Unit demo</label>
            <label>Nomor seri {{ $unit->code }}<input class="input" name="serial_number" maxlength="100" value="{{ $unit->serial_number }}"></label>
            <label>Status {{ $unit->code }}<select class="input" name="status">@foreach(\App\Models\RentalUnit::STATUSES as $key => $label)@continue(in_array($key, ['rented', 'inspection']))<option value="{{ $key }}" @selected($unit->status === $key)>{{ $label }}</option>@endforeach</select></label>
            <label>Kondisi {{ $unit->code }}<textarea class="input" name="condition_notes" required>{{ $unit->condition_notes }}</textarea></label><button class="button button-primary">Simpan Unit</button>
        </form></details>@endif
    </div></div>@empty<p>Belum ada unit. Tambahkan produk di katalog terlebih dahulu.</p>@endforelse</div>{{ $units->links() }}
@endsection
