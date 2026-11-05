@extends('layouts.app')
@section('title','Produk Pendukung - Gayatri CRM')
@push('styles')<link rel="stylesheet" href="{{ asset('css/admin-catalogue.css').'?v='.config('gayatri_frontend.asset_version') }}">@endpush
@section('content')
<x-ui.page-header eyebrow="Katalog Produk" title="Produk Pendukung" description="Kelola produk fisik, foto, kategori, dan harga. Ketersediaan serta pemesanan melalui admin."><x-slot name="actions"><a class="button button-secondary" href="{{ route('catalogue.index',['type'=>'retail']) }}">Katalog pelanggan</a><a class="button button-primary" href="{{ route('admin.retail.create', \App\Support\AdminReviewContext::carry(request(),'retail')) }}">Tambah produk</a></x-slot></x-ui.page-header>
@if(session('status'))<p class="catalogue-notice" role="status">{{ session('status') }}</p>@endif
<div class="card"><div class="card-body"><form method="GET" class="catalogue-filter-form" aria-label="Filter produk pendukung">
<div class="catalogue-filter-primary"><label>Cari produk<input class="input" name="search" maxlength="150" value="{{ $filters['search'] }}" placeholder="Nama, SKU, atau kategori"></label><div class="catalogue-actions"><button class="button button-secondary">Terapkan filter</button><a class="button button-ghost" href="{{ route('admin.retail.index') }}">Reset</a></div></div><details class="catalogue-filter-more" @if($filters['category'] || (string)$filters['active']!=='1') open @endif><summary>Filter lanjutan · {{ $filters['active']===null?'Semua publikasi':((string)$filters['active']==='1'?'Tayang':'Diarsipkan') }}</summary><div class="catalogue-filters">
<label>Kategori<select class="input" name="category"><option value="">Semua kategori</option>@foreach($categories as $category)<option value="{{ $category }}" @selected($filters['category']===$category)>{{ $category }}</option>@endforeach</select></label>
<x-ui.catalogue-publication-filter :value="$filters['active']"/>

</div></details></form></div></div>
<p class="catalogue-meta" role="status">{{ $products->total() }} produk sesuai filter.</p>
<div class="card"><div class="card-body"><table class="table-card"><thead><tr><th>Produk</th><th class="table-price">Harga</th><th>Pemesanan</th><th>Publikasi</th><th>Aksi</th></tr></thead><tbody>
@forelse($products as $product)<tr><td data-label="Produk"><div class="table-product">@if($product->imageUrl())<img src="{{ $product->imageUrl() }}" alt="" loading="lazy">@else<span class="catalogue-avatar" aria-hidden="true">{{ mb_substr($product->name,0,1) }}</span>@endif<div class="table-cell-content"><strong>{{ $product->name }}</strong><small class="muted">{{ $product->sku }} · {{ $product->category }}</small></div></div></td>
<td data-label="Harga" class="table-price">@if($product->compare_at_price)<del class="muted">Rp {{ number_format($product->compare_at_price,0,',','.') }}</del><br>@endif<strong>Rp {{ number_format($product->price,0,',','.') }}</strong></td>
<td data-label="Pemesanan"><div class="table-cell-content"><span class="badge badge-gold">Konfirmasi admin</span><small class="muted">Stok dan detail produk dipastikan sebelum pemesanan.</small></div></td>
<td data-label="Publikasi"><span class="badge {{ $product->is_active?'badge-green':'badge-brown' }}">{{ $product->is_active?'Tayang':'Diarsipkan' }}</span></td>
<td data-label="Aksi"><a class="button button-secondary" href="{{ route('admin.retail.edit',array_merge(['product'=>$product], \App\Support\AdminReviewContext::carry(request(),'retail'))) }}">Edit produk</a></td></tr>
@empty<tr><td colspan="5">Belum ada produk sesuai filter. Coba kata kunci lain atau reset filter.</td></tr>@endforelse</tbody></table>{{ $products->links() }}</div></div>
@endsection
