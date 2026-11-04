@extends('layouts.app')
@section('title', 'Tambah Transaksi Sewa - Gayatri CRM')
@section('content')
    <x-ui.page-header eyebrow="Rental Perlengkapan" title="Tambah Transaksi Sewa" description="Pilih pelanggan, alat, dan tanggal. Unit fisik dialokasikan otomatis setelah ketersediaan diperiksa." />
    @include('admin.rentals.partials.navigation')
    <div class="card"><div class="card-body"><form class="rental-form" data-rental-checkout method="POST" action="{{ route('admin.rentals.orders.store') }}" data-quote-url="{{ route('rental.quote') }}">@csrf
        <label>Pelanggan<select class="input" name="customer_id" required><option value="">Pilih pelanggan</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected(old('customer_id') == $customer->id)>{{ $customer->name }} · {{ $customer->whatsapp_number }}</option>@endforeach</select></label>
        @include('rental.partials.checkout-fields', ['fixedProduct' => null])
        <label class="rental-check"><input type="checkbox" name="is_demo" value="1" @checked(old('is_demo'))> Transaksi demo — dikecualikan dari laporan operasional secara default.</label>
        <button class="button button-primary" type="submit">Simpan Transaksi Sewa</button>
    </form></div></div>
@endsection
