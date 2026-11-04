@extends('layouts.app')
@section('title', 'Pembatalan & Refund Rental - Gayatri')
@section('content')
    <x-ui.page-header eyebrow="Rental Perlengkapan" title="Pembatalan & Refund" description="Tinjau pengajuan pelanggan dan catat bukti pengembalian dana yang sudah dilakukan." />
    @include('admin.rentals.partials.navigation')
    <form method="GET" class="rental-actions rental-form"><label>Status pengajuan<select class="input" name="status">@foreach(\App\Models\RentalCustomerRequest::STATUSES as $key=>$label)<option value="{{ $key }}" @selected($statusFilter === $key)>{{ $label }}</option>@endforeach</select></label><button class="button button-secondary">Tampilkan Pengajuan</button></form>
    @forelse($entries as $entry)@include('admin.rentals.partials.customer-request-review')@empty<div class="card"><div class="card-body"><p>Tidak ada pengajuan pada status ini.</p></div></div>@endforelse
    {{ $entries->links() }}
@endsection
