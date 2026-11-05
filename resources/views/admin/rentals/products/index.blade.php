@extends('layouts.app')
@section('title','Katalog Rental - Gayatri CRM')
@push('styles')<link rel="stylesheet" href="{{ asset('css/admin-catalogue.css').'?v='.config('gayatri_frontend.asset_version') }}">@endpush
@section('content')
<x-ui.page-header eyebrow="Rental Perlengkapan" title="Katalog Rental" description="Bandingkan tarif per periode, deposit, dan status unit sebelum menerima pesanan."><x-slot name="actions"><a class="button button-secondary" href="{{ route('rental.index') }}">Katalog pelanggan</a><a class="button button-primary" href="{{ route('admin.rentals.products.create', \App\Support\AdminReviewContext::carry(request(),'rental-products')) }}">Tambah alat</a></x-slot></x-ui.page-header>
@include('admin.rentals.partials.navigation')
<div class="card"><div class="card-body"><form class="catalogue-filter-form" method="GET" aria-label="Filter katalog rental">
<div class="catalogue-filter-primary"><label>Cari alat<input class="input" name="search" value="{{ $filters['search'] }}" maxlength="150" placeholder="Nama alat, SKU, atau kategori"></label><div class="catalogue-actions"><button class="button button-secondary">Terapkan filter</button><a class="button button-ghost" href="{{ route('admin.rentals.products.index') }}">Reset</a></div></div><details class="catalogue-filter-more" @if($filters['category'] || $filters['readiness'] || (string)$filters['active']!=='1') open @endif><summary>Filter lanjutan · {{ $filters['active']===null?'Semua publikasi':((string)$filters['active']==='1'?'Tayang':'Diarsipkan') }}</summary><div class="catalogue-filters">
<label>Kategori<select class="input" name="category"><option value="">Semua kategori</option>@foreach($categories as $category)<option value="{{ $category }}" @selected($filters['category']===$category)>{{ $category }}</option>@endforeach</select></label>
<x-ui.catalogue-publication-filter :value="$filters['active']"/>
<label>Kesiapan katalog<select class="input" name="readiness"><option value="">Semua kesiapan</option>@foreach(['ready'=>'Data lengkap + unit berstatus siap','incomplete'=>'Perlu dilengkapi','empty'=>'Belum ada unit fisik','busy'=>'Tanpa unit berstatus siap'] as $key=>$label)<option value="{{ $key }}" @selected($filters['readiness']===$key)>{{ $label }}</option>@endforeach</select></label>

</div></details></form></div></div>
<p class="catalogue-meta" role="status">{{ $products->total() }} alat sesuai filter. Status unit bukan jaminan ketersediaan pada tanggal sewa; cek Kalender Sewa sebelum konfirmasi.</p>
<div class="card"><div class="card-body"><table class="table-card"><thead><tr><th>Alat</th><th>Tarif per unit</th><th>Deposit</th><th>Unit & kesiapan</th><th>Publikasi</th><th>Aksi</th></tr></thead><tbody>
@forelse($products as $product)
@php($readiness=$product->catalogueReadiness())
<tr><td data-label="Alat"><div class="table-product"><img src="{{ $product->imageUrl() }}" alt="" loading="lazy"><div class="table-cell-content"><strong>{{ $product->name }}</strong><small class="muted">{{ $product->sku }} · {{ $product->category }}</small></div></div></td>
<td data-label="Tarif per unit"><dl class="catalogue-rates">@foreach(['daily_rate'=>'Harian','weekly_rate'=>'7 hari','fortnightly_rate'=>'14 hari','monthly_rate'=>'30 hari'] as $field=>$label)<div><dt>{{ $label }}</dt><dd>{{ $product->$field && (float)$product->$field>0?'Rp '.number_format((float)$product->$field,0,',','.'):'Tidak ditawarkan' }}</dd></div>@endforeach</dl></td>
<td data-label="Deposit"><div class="table-cell-content"><strong>{{ $product->depositLabel() }}</strong><small class="muted">{{ data_get($product->catalog_metadata,'turnaround_unconfirmed')?'Jeda pembersihan belum dikonfirmasi':'Jeda pembersihan '.$product->turnaround_days.' hari' }}</small></div></td>
<td data-label="Unit & kesiapan"><div class="table-cell-content"><strong>{{ $product->ready_units_count }} siap / {{ $product->units_count }} unit fisik</strong><span class="badge {{ $readiness==='ready'?'badge-green':($readiness==='incomplete'?'badge-red':'badge-gold') }}">{{ ['ready'=>'Data lengkap','incomplete'=>'Perlu dilengkapi','empty'=>'Belum ada unit','busy'=>'Unit belum siap'][$readiness] }}</span><small class="muted">{{ $product->catalogueMissing() }}</small></div></td>
<td data-label="Publikasi"><span class="badge {{ $product->is_active?'badge-green':'badge-brown' }}">{{ $product->is_active?'Tayang':'Diarsipkan' }}</span></td>
<td data-label="Aksi"><div class="catalogue-actions"><a class="button button-secondary" href="{{ route('admin.rentals.products.edit',array_merge(['product'=>$product], \App\Support\AdminReviewContext::carry(request(),'rental-products'))) }}">{{ $readiness==='incomplete'?'Lengkapi':'Edit alat' }}</a><a class="button button-ghost" href="{{ route('admin.rentals.units.index',['product_id'=>$product->id]) }}">Kelola unit</a></div></td></tr>
@empty<tr><td colspan="6"><strong>Belum ada alat sesuai filter.</strong><p>Coba reset filter atau tambahkan alat dan unit fisiknya.</p></td></tr>@endforelse
</tbody></table>{{ $products->links() }}</div></div>
@endsection
