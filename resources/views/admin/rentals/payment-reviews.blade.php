@extends('layouts.app')
@section('title', 'Verifikasi Pembayaran & Pengajuan Rental - Gayatri')
@section('content')
    <x-ui.page-header eyebrow="Rental Perlengkapan" title="Verifikasi Pembayaran" description="Tinjau bukti transfer dan pengajuan perpanjangan pelanggan." />
    @include('admin.rentals.partials.navigation')
    <form method="GET" class="rental-actions rental-form"><label>Status peninjauan<select class="input" name="status">@foreach(\App\Models\RentalPaymentProof::STATUSES as $key=>$label)<option value="{{ $key }}" @selected($statusFilter === $key)>{{ $label }}</option>@endforeach</select></label><button class="button button-secondary">Tampilkan</button></form>
    <h2>Bukti pembayaran</h2>@forelse($proofs as $proof)@include('admin.rentals.partials.proof-review')@empty<p class="muted">Tidak ada bukti pembayaran pada status ini.</p>@endforelse {{ $proofs->links() }}
    <h2>Pengajuan perpanjangan</h2>@forelse($requests as $entry)@include('admin.rentals.partials.extension-request-review')@empty<p class="muted">Tidak ada pengajuan pada status ini.</p>@endforelse {{ $requests->links() }}
@endsection
