@extends('layouts.app')
@section('title', 'Peringatan Operasional Rental - Gayatri')
@section('content')
@include('partials.admin-operations-assets')
    <x-ui.page-header eyebrow="Rental Perlengkapan" title="Peringatan Operasional" description="Pantau keterlambatan, pemeriksaan yang tertunda, dan kesiapan unit untuk penyewa berikutnya." />
    @include('admin.rentals.partials.navigation')
    <div class="card"><div class="card-body"><p><strong>{{ $allAlerts->where('severity','critical')->count() }} perlu tindakan</strong> · {{ $allAlerts->where('severity','!=','critical')->count() }} perlu dipantau</p><form method="GET" class="ops-filters"><label>Prioritas<select class="input" name="severity"><option value="">Semua prioritas</option><option value="critical" @selected(request('severity')==='critical')>Perlu tindakan</option><option value="warning" @selected(request('severity')==='warning')>Perlu dipantau</option></select></label><label>Jenis peringatan<select class="input" name="type"><option value="">Semua jenis</option>@foreach($allAlerts->pluck('type')->unique() as $type)<option value="{{ $type }}" @selected(request('type')===$type)>{{ $type }}</option>@endforeach</select></label><div class="ops-actions"><button class="button button-secondary">Terapkan filter</button><a href="{{ route('admin.rentals.operations') }}">Reset</a></div></form></div></div>
    @include('admin.rentals.partials.operational-alerts' , ['alertLimit' => $alerts->count()])
    @if($alerts->isEmpty())<div class="card"><div class="card-body"><h2>Tidak ada peringatan sesuai filter</h2><p class="muted">Pilih prioritas atau jenis lain untuk memeriksa antrean lainnya. Rentang pemantauan dapat diatur di Pengaturan Rental.</p></div></div>@endif
@endsection
