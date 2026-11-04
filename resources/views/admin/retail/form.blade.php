@extends('layouts.app')
@section('title','Kelola Produk Pendukung - Gayatri')
@section('content')
<x-ui.page-header eyebrow="Katalog Produk" title="{{ $product->exists?'Edit':'Tambah' }} Produk Pendukung" description="Harga mengikuti produk fisik. Pemesanan dan ketersediaan tetap melalui admin." />
@if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
<div class="card"><div class="card-body"><form class="grid grid-2" method="POST" enctype="multipart/form-data" action="{{ $product->exists?route('admin.retail.update',$product):route('admin.retail.store') }}">@csrf @include('partials.admin-review-fields', ['reviewModule'=>'retail']) @if($product->exists) @method('PUT') @endif
@foreach(['sku'=>'Kode / SKU','name'=>'Nama produk','category'=>'Kategori'] as $field=>$label)<label>{{ $label }}<input class="input" name="{{ $field }}" value="{{ old($field,$product->$field) }}" required></label>@endforeach
<label>Harga (Rp)<input class="input" type="number" name="price" min="0" value="{{ old('price',$product->price) }}" required></label><label>Harga sebelum diskon (opsional)<input class="input" type="number" name="compare_at_price" min="0" value="{{ old('compare_at_price',$product->compare_at_price) }}"></label><label>Publikasi<select class="input" name="is_active"><option value="1" @selected(old('is_active',$product->is_active))>Tayang</option><option value="0" @selected(!old('is_active',$product->is_active))>Diarsipkan</option></select></label><label>Foto produk<input class="input" type="file" name="photo" accept="image/jpeg,image/png,image/webp"></label><label style="grid-column:1/-1;">Deskripsi<textarea class="input" name="description" rows="4">{{ old('description',$product->description) }}</textarea></label><div><button class="button button-primary">Simpan Produk</button><a class="button button-secondary" href="{{ \App\Support\AdminReviewContext::back(request(),'retail') }}">Kembali</a></div>
</form></div></div>
@endsection
