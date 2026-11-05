@extends('layouts.app')
@section('title', ($filters['service_group'] ? \App\Support\ServiceGroup::LABELS[$filters['service_group']] : 'Seluruh Layanan').' - Gayatri CRM')
@push('styles')<link rel="stylesheet" href="{{ asset('css/admin-catalogue.css').'?v='.config('gayatri_frontend.asset_version') }}">@endpush
@section('content')
<x-ui.page-header eyebrow="Katalog Layanan" :title="$filters['service_group'] ? \App\Support\ServiceGroup::LABELS[$filters['service_group']] : 'Seluruh Layanan'" description="Kelola keluarga layanan, variasi harga, kesiapan pemesanan, dan publikasi.">
    <x-slot name="actions"><a class="button button-secondary" href="{{ route('catalogue.index',['group'=>$filters['service_group']]) }}">Katalog pelanggan</a><a class="button button-primary" href="{{ route('admin.services.create',array_merge(['service_group'=>$filters['service_group']], \App\Support\AdminReviewContext::carry(request(),'services'))) }}">Tambah layanan</a></x-slot>
</x-ui.page-header>
<nav class="catalogue-nav" aria-label="Kelompok layanan">
    <a class="button {{ !$filters['service_group']?'button-primary':'button-secondary' }}" href="{{ route('admin.services.index') }}">Semua layanan</a>
    @foreach(\App\Support\ServiceGroup::LABELS as $key=>$label)<a class="button {{ $filters['service_group']===$key?'button-primary':'button-secondary' }}" href="{{ route('admin.services.index',['service_group'=>$key]) }}">{{ $label }}</a>@endforeach
</nav>
@if(session('status'))<p class="catalogue-notice" role="status">{{ session('status') }}</p>@endif
<div class="card"><div class="card-body">
<form method="GET" action="{{ route('admin.services.index') }}" class="catalogue-filter-form" aria-label="Filter katalog layanan">
    @if($filters['service_group'])<input type="hidden" name="service_group" value="{{ $filters['service_group'] }}">@endif
    @if($filters['family'])<input type="hidden" name="family" value="{{ $filters['family'] }}">@endif
    <div class="catalogue-filter-primary"><label>Cari layanan<input class="input" type="search" name="search" value="{{ $filters['search'] }}" maxlength="150" placeholder="Nama, keluarga, variasi, atau kode"></label><div class="catalogue-actions"><button class="button button-secondary" type="submit">Terapkan filter</button><a class="button button-ghost" href="{{ route('admin.services.index',['service_group'=>$filters['service_group']]) }}">Reset</a></div></div><details class="catalogue-filter-more" @if($filters['kind'] || $filters['readiness'] || $filters['category'] || $filters['branch_id'] || (string)$filters['active']!=='1') open @endif><summary>Filter lanjutan · {{ $filters['active']===null?'Semua publikasi':((string)$filters['active']==='1'?'Tayang':'Diarsipkan') }}</summary><div class="catalogue-filters">
    <label>Jenis layanan<select class="input" name="kind"><option value="">Semua jenis</option>@foreach(\App\Models\Service::KINDS as $key=>$label)<option value="{{ $key }}" @selected($filters['kind']===$key)>{{ $label }}</option>@endforeach</select></label>
    <label>Kesiapan pemesanan<select class="input" name="readiness"><option value="">Semua kesiapan</option>@foreach(\App\Models\Service::READINESS as $key=>$label)<option value="{{ $key }}" @selected($filters['readiness']===$key)>{{ $label }}</option>@endforeach</select></label>
    <label>Kategori / usia<select class="input" name="category"><option value="">Semua kategori</option>@foreach($categories as $category)<option value="{{ $category }}" @selected($filters['category']===$category)>{{ $category }}</option>@endforeach</select></label>
    <label>Cabang<select class="input" name="branch_id"><option value="">Semua cabang</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((string)$filters['branch_id']===(string)$branch->id)>{{ $branch->name }}</option>@endforeach</select></label>
    <label>Publikasi<select class="input" name="active"><option value="" @selected($filters['active']===null)>Tayang dan diarsipkan</option><option value="1" @selected((string)$filters['active']==='1')>Tayang</option><option value="0" @selected((string)$filters['active']==='0')>Diarsipkan</option></select></label>

</div></details></form>
</div></div>
<div class="catalogue-meta"><span role="status"><strong>{{ $families->total() }}</strong> keluarga · <strong>{{ $variantCount }}</strong> variasi sesuai filter @if($filters['family'])· {{ $filters['family'] }}@endif</span><span>Buka keluarga untuk melihat variasi dan mengeditnya.</span></div>
<div class="catalogue-families">
@forelse($families as $family)
    @php($first=$family['variants']->first())
    <details class="catalogue-family" id="{{ $family['key'] }}" @if($filters['family'] || $filters['search'] || $filters['readiness']) open @endif>
        <summary>
            <div class="catalogue-identity">@if($first->imageUrl())<img src="{{ $first->imageUrl() }}" alt="" loading="lazy">@else<span class="catalogue-avatar" aria-hidden="true">{{ mb_substr($family['name'],0,1) }}</span>@endif<div><strong>{{ $family['name'] }}</strong><small>{{ $first->groupLabel() }} · {{ $family['variants']->pluck('category')->filter()->unique()->join(' / ') }}</small></div></div>
            <div><strong>{{ $family['variants']->count() }} variasi</strong><small class="catalogue-summary-label">{{ $family['active_count'] }} tayang · {{ $family['variants']->count()-$family['active_count'] }} arsip</small></div>
            <div class="catalogue-money">@if($family['min_price']===null)Harga perlu dilengkapi @else<strong>Rp {{ number_format($family['min_price'],0,',','.') }}@if($family['max_price']!==$family['min_price'])–{{ number_format($family['max_price'],0,',','.') }}@endif</strong>@endif @if($family['unknown_prices'])<small class="catalogue-summary-label">{{ $family['unknown_prices'] }} harga belum lengkap</small>@endif</div>
            <div class="catalogue-summary-stack">@if($family['ready_count'])<span class="badge badge-green">{{ $family['ready_count'] }} siap dipesan</span>@endif @if($family['admin_count'])<span class="badge badge-gold">{{ $family['admin_count'] }} konfirmasi admin</span>@endif @if($family['incomplete_count'])<span class="badge badge-red">{{ $family['incomplete_count'] }} perlu dilengkapi</span>@endif</div>
            <span class="catalogue-expand"><span class="catalogue-open-hint">Lihat variasi ↓</span><span class="catalogue-close-hint">Tutup variasi ↑</span></span>
        </summary>
        <div class="catalogue-family-body">
            <div class="catalogue-actions"><a class="button button-secondary" href="{{ route('admin.services.create',array_merge(['variant_of'=>$first->id], \App\Support\AdminReviewContext::carry(request(),'services'))) }}">Tambah variasi</a><a class="button button-ghost" href="{{ route('admin.services.index',['service_group'=>$family['group'],'family'=>$family['name'],'active'=>'']) }}">Semua variasi keluarga</a></div>
            <table class="table-card"><caption class="sr-only">Variasi {{ $family['name'] }} sesuai filter</caption><thead><tr><th>Variasi</th><th class="table-price">Harga / durasi</th><th>Kesiapan</th><th>Tambahan</th><th>Publikasi</th><th>Aksi</th></tr></thead><tbody>
            @foreach($family['variants'] as $service)
                @php($pending=$service->addOns->where('is_active',true)->where('requires_confirmation',true)->count())
                <tr><td data-label="Variasi"><div class="table-cell-content"><strong>{{ $service->variant_label ?: $service->name }}</strong><small class="muted">{{ \App\Models\Service::KINDS[$service->catalogueKind()] }} · {{ $service->branch?->name ?: 'Semua cabang' }}</small>@if($service->catalog_code)<small class="muted">{{ $service->catalog_code }}</small>@endif</div></td>
                    <td data-label="Harga / durasi"><div class="table-cell-content"><strong>{{ $service->priceLabel() }}</strong><small class="muted">{{ $service->durationLabel() }}</small></div></td>
                    <td data-label="Kesiapan"><div class="table-cell-content"><span class="badge {{ ['ready'=>'badge-green','admin'=>'badge-gold','incomplete'=>'badge-red'][$service->readiness()] }}">{{ \App\Models\Service::READINESS[$service->readiness()] }}</span><small class="muted">{{ $service->readinessReason() }}</small></div></td>
                    <td data-label="Tambahan"><div class="table-cell-content"><span>{{ $service->activeAddOns->count() }} siap ditawarkan</span>@if($pending)<small class="catalogue-error">{{ $pending }} perlu konfirmasi</small>@endif</div></td>
                    <td data-label="Publikasi"><span class="badge {{ $service->is_active?'badge-green':'badge-brown' }}">{{ $service->is_active?'Tayang':'Diarsipkan' }}</span></td>
                    <td data-label="Aksi"><div class="catalogue-actions"><a class="button button-secondary" href="{{ route('admin.services.edit',array_merge(['service'=>$service], \App\Support\AdminReviewContext::carry(request(),'services'))) }}">{{ $service->readiness()==='incomplete'?'Lengkapi':'Edit variasi' }}</a>@if($service->is_active)<form method="POST" action="{{ route('admin.services.archive',$service) }}">@csrf @include('partials.admin-review-fields', ['reviewModule'=>'services'])<input type="hidden" name="catalogue_form" value="1"><button class="button button-ghost" type="submit">Arsipkan</button></form>@endif</div></td></tr>
            @endforeach
            </tbody></table>
        </div>
    </details>
@empty
    <div class="card"><div class="card-body"><h2 class="section-title">Belum ada layanan sesuai filter.</h2><p class="muted">Coba kata kunci lain atau reset filter. Layanan yang diarsipkan dapat dilihat melalui filter Publikasi.</p></div></div>
@endforelse
</div>
{{ $families->links() }}
@endsection
