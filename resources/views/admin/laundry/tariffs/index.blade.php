@extends('layouts.app')
@section('title','Katalog Tarif Laundry - Gayatri CRM')
@push('styles')<link rel="stylesheet" href="{{ asset('css/admin-catalogue.css') }}">@endpush
@section('content')
<x-ui.page-header eyebrow="Laundry Ibu & Bayi" title="Katalog Tarif Laundry" description="Perjelas tarif kilogram dan satuan, aturan penagihan, serta kesiapan penerimaan."><x-slot name="actions"><a class="button button-primary" href="{{ route('admin.laundry.tariffs.create', \App\Support\AdminReviewContext::carry(request(),'laundry-tariffs')) }}">Tambah tarif</a></x-slot></x-ui.page-header>
@include('admin.laundry.nav')
<div class="card"><div class="card-body"><form method="GET" class="catalogue-filter-form" aria-label="Filter tarif laundry">
<div class="catalogue-filter-primary"><label>Cari tarif<input class="input" name="search" maxlength="100" value="{{ $filters['search'] }}" placeholder="Nama atau kode tarif"></label><div class="catalogue-actions"><button class="button button-secondary">Terapkan filter</button><a class="button button-ghost" href="{{ route('admin.laundry.tariffs.index') }}">Reset</a></div></div><details class="catalogue-filter-more" @if($filters['audience'] || $filters['unit'] || $filters['readiness'] || (string)$filters['active']!=='1') open @endif><summary>Filter lanjutan · {{ $filters['active']===null?'Semua publikasi':((string)$filters['active']==='1'?'Tayang':'Diarsipkan') }}</summary><div class="catalogue-filters">
<label>Untuk<select class="input" name="audience"><option value="">Ibu dan bayi</option>@foreach(\App\Models\LaundryTariff::AUDIENCES as $key=>$label)<option value="{{ $key }}" @selected($filters['audience']===$key)>{{ $label }}</option>@endforeach</select></label>
<label>Satuan tarif<select class="input" name="unit"><option value="">Semua satuan</option>@foreach(\App\Models\LaundryTariff::UNITS as $key=>$label)<option value="{{ $key }}" @selected($filters['unit']===$key)>{{ $label }}</option>@endforeach</select></label>
<x-ui.catalogue-publication-filter :value="$filters['active']"/>
<label>Kesiapan penerimaan<select class="input" name="readiness"><option value="">Semua kesiapan</option>@foreach(['ready'=>'Siap diterima','admin'=>'Konfirmasi admin','incomplete'=>'Perlu dilengkapi'] as $key=>$label)<option value="{{ $key }}" @selected($filters['readiness']===$key)>{{ $label }}</option>@endforeach</select></label>

</div></details></form></div></div>
<p class="catalogue-meta" role="status">{{ $tariffs->total() }} tarif sesuai filter. Publikasi dan kesiapan penerimaan ditampilkan terpisah.</p>
<div class="card"><div class="card-body"><table class="table-card"><thead><tr><th>Layanan</th><th class="table-price">Tarif</th><th>Aturan & estimasi</th><th>Kesiapan</th><th>Publikasi</th><th>Aksi</th></tr></thead><tbody>
@forelse($tariffs as $tariff)@php($state=$tariff->catalogueReadiness())@php($unit=$tariff->unit==='kg'?'kg':'satuan')
<tr><td data-label="Layanan"><div class="table-cell-content"><strong>{{ $tariff->name }}</strong><small class="muted">{{ $tariff->code }} · {{ \App\Models\LaundryTariff::AUDIENCES[$tariff->audience] }}</small></div></td>
<td data-label="Tarif" class="table-price"><strong>Rp {{ number_format($tariff->price,0,',','.') }}</strong><br><small class="muted">per {{ $unit }}</small></td>
<td data-label="Aturan & estimasi"><div class="table-cell-content"><small>Minimum: {{ $tariff->minimum_milli===null?'belum diisi':rtrim(rtrim(number_format($tariff->minimum_milli/1000,3,',','.'),'0'),',').' '.$unit }}</small><small>Pembulatan: {{ $tariff->increment_milli===null?'belum diisi':rtrim(rtrim(number_format($tariff->increment_milli/1000,3,',','.'),'0'),',').' '.$unit }}</small><small>Estimasi: {{ $tariff->duration_hours===null?'belum diisi':$tariff->duration_hours.' jam kalender' }}</small></div></td>
<td data-label="Kesiapan"><div class="table-cell-content"><span class="badge {{ ['ready'=>'badge-green','admin'=>'badge-gold','incomplete'=>'badge-red'][$state] }}">{{ ['ready'=>'Siap diterima','admin'=>'Konfirmasi admin','incomplete'=>'Perlu dilengkapi'][$state] }}</span><small class="muted">{{ $tariff->catalogueMissing() }}</small></div></td>
<td data-label="Publikasi"><span class="badge {{ $tariff->is_active?'badge-green':'badge-brown' }}">{{ $tariff->is_active?'Tayang':'Diarsipkan' }}</span></td>
<td data-label="Aksi"><div class="catalogue-actions"><a class="button button-secondary" href="{{ route('admin.laundry.tariffs.edit',array_merge(['tariff'=>$tariff], \App\Support\AdminReviewContext::carry(request(),'laundry-tariffs'))) }}">{{ $state==='incomplete'?'Lengkapi':'Edit tarif' }}</a>@if($tariff->is_active)<form method="POST" action="{{ route('admin.laundry.tariffs.archive',$tariff) }}">@csrf<button class="button button-ghost">Arsipkan</button></form>@endif</div></td></tr>
@empty<tr><td colspan="6">Belum ada tarif sesuai filter. Coba reset filter atau tambahkan tarif.</td></tr>@endforelse</tbody></table>{{ $tariffs->links() }}</div></div>
@endsection
