@extends('layouts.app')
@section('title', 'Transaksi Sewa - Gayatri CRM')
@section('content')
@include('partials.admin-operations-assets')
    <x-ui.page-header eyebrow="Rental Perlengkapan" title="Transaksi Sewa" description="Konfirmasi pesanan, catat serah-terima, terima pengembalian, dan selesaikan pemeriksaan."><x-slot name="actions"><a class="button button-primary" href="{{ route('admin.rentals.orders.create') }}">Tambah Transaksi</a></x-slot></x-ui.page-header>
    @include('admin.rentals.partials.navigation')
    @include('admin.rentals.partials.operational-alerts')

    <div class="card"><div class="card-body"><form method="GET" class="ops-filters"><label>Cari kode / pelanggan<input class="input" name="search" maxlength="100" value="{{ request('search') }}" placeholder="Kode sewa atau nama"></label><label>Status sewa<select class="input" name="status"><option value="">Semua status</option>@foreach(\App\Models\RentalOrder::STATUSES as $key=>$label)<option value="{{ $key }}" @selected(request('status')===$key)>{{ $label }}</option>@endforeach</select></label>@if(request('customer_id'))<input type="hidden" name="customer_id" value="{{ request('customer_id') }}">@endif<div class="ops-actions"><button class="button button-secondary">Terapkan filter</button><a href="{{ route('admin.rentals.orders.index') }}">Reset</a></div></form></div></div>
    <div class="card" style="margin-top:1rem"><div class="card-body"><p class="muted">{{ $orders->total() }} transaksi sesuai filter. Deposit dan saldo pengembalian tetap dipisahkan dari biaya sewa.</p><table class="table-card"><thead><tr><th>Transaksi / pelanggan</th><th>Periode / unit</th><th>Status sewa</th><th>Tagihan & deposit</th><th>Tindak lanjut</th></tr></thead><tbody>
    @forelse($orders as $order)@php($next=\App\Support\OperationalGuidance::rental($order))<tr>
    <td data-label="Transaksi / pelanggan"><strong>{{ $order->code }}</strong><span class="ops-subtitle">{{ $order->customer?->name ?: 'Pelanggan tidak tersedia' }}</span>@if($order->is_demo)<span class="badge badge-gold">DEMO</span>@endif</td>
    <td data-label="Periode / unit">{{ $order->start_date->format('d M Y') }}–{{ $order->end_date->format('d M Y') }}<span class="ops-subtitle">{{ $order->items->pluck('unit_code')->join(', ') }} · {{ \App\Models\RentalOrder::FULFILMENTS[$order->fulfilment] }}</span></td>
    <td data-label="Status sewa"><span class="badge badge-gold">{{ $order->isExpired() ? 'Permintaan kedaluwarsa' : \App\Models\RentalOrder::STATUSES[$order->status] }}</span>@if($order->isOverdue())<p class="rental-error">Terlambat kembali</p>@endif</td>
    <td data-label="Tagihan & deposit" class="ops-money">Sisa Rp {{ number_format($order->balance(),0,',','.') }}<span class="ops-subtitle">Saldo dapat dikembalikan Rp {{ number_format($order->refundable(),0,',','.') }}</span><details><summary>Rincian nilai</summary><p>@if($order->originalTotal()!==null)Total saat pemesanan Rp {{ number_format($order->originalTotal(),0,',','.') }}@else Total awal belum tercatat pada data lama.@endif<br>Tagihan saat ini Rp {{ number_format($order->totalDue(),0,',','.') }}<br>Deposit awal Rp {{ number_format((float)$order->deposit_total,0,',','.') }}</p></details></td>
    <td data-label="Tindak lanjut"><a class="button button-secondary" href="{{ route('admin.rentals.orders.show',array_merge(['order'=>$order],['queue'=>request()->only(['search','status','customer_id','page'])])) }}#{{ $next['anchor'] }}">{{ $next['label'] }}</a><span class="ops-subtitle">{{ $next['hint'] }}</span></td>
    </tr>@empty<tr><td colspan="5">Belum ada transaksi sewa sesuai filter.</td></tr>@endforelse
    </tbody></table>{{ $orders->links() }}</div></div>
@endsection
