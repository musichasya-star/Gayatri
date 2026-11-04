@extends('layouts.app')
@section('title', 'Proses & QC Laundry - Gayatri')
@section('content')
@include('partials.admin-operations-assets')
<x-ui.page-header eyebrow="Laundry Ibu & Bayi" title="Proses & QC Laundry" description="Pantau seluruh pesanan terbuka, urutan pengerjaan, pemeriksaan kualitas, dan serah terima."></x-ui.page-header>
@include('admin.laundry.nav')
<nav class="ops-nav" aria-label="Antrean Laundry"><a class="button button-secondary" href="{{ route('admin.laundry.operations',request()->only(['search','include_demo'])) }}">Semua terbuka</a><a class="button button-secondary" href="{{ route('admin.laundry.operations',array_merge(request()->only(['search','include_demo']),['deadline'=>'overdue'])) }}">Lewat estimasi</a><a class="button button-secondary" href="{{ route('admin.laundry.operations',array_merge(request()->only(['search','include_demo']),['workflow'=>'quality_check'])) }}">Perlu QC</a><a class="button button-secondary" href="{{ route('admin.laundry.operations',array_merge(request()->only(['search','include_demo']),['workflow'=>'ready'])) }}">Siap diserahkan</a></nav>
<section class="laundry-stats">
    @foreach(['open' => 'Belum diserahkan', 'overdue' => 'Melewati estimasi selesai', 'soon' => 'Estimasi dalam 24 jam', 'ready' => 'Siap diambil'] as $key => $label)
        <div class="card"><div class="card-body"><p class="muted">{{ $label }}</p><strong>{{ number_format($counts[$key]) }}</strong></div></div>
    @endforeach
</section>
<section class="card"><div class="card-body">
    <form class="laundry-filter" method="GET">
        <label>Cari pesanan<input class="input" name="search" value="{{ $filters['search'] ?? '' }}" maxlength="100" placeholder="Kode atau nama pelanggan"></label>
        <label>Tahap pengerjaan<select class="input" name="workflow"><option value="">Semua yang belum diserahkan</option>@foreach(\App\Models\LaundryOrder::WORKFLOW as $key => $label) @if($key !== 'cancelled')<option value="{{ $key }}" @selected(($filters['workflow'] ?? '') === $key)>{{ $label }}</option> @endif @endforeach</select></label>
        <label>Estimasi selesai<select class="input" name="deadline"><option value="">Semua waktu</option><option value="overdue" @selected(($filters['deadline'] ?? '') === 'overdue')>Sudah terlewati</option><option value="soon" @selected(($filters['deadline'] ?? '') === 'soon')>Dalam 24 jam</option></select></label>
        <label class="laundry-check"><input type="checkbox" name="include_demo" value="1" @checked($filters['include_demo'] ?? false)> Sertakan data demo</label>
        <button class="button button-secondary">Terapkan Filter</button>
    </form>
    <p class="muted">Ringkasan mengikuti pencarian dan pilihan data demo, sebelum filter tahap/waktu. Pesanan lama tetap ditampilkan sampai diserahkan. Barang siap diambil tidak masuk peringatan keterlambatan pengerjaan.</p>
</div></section>
<section class="card" style="margin-top:1rem"><div class="card-body"><table class="table-card">
    <thead><tr><th>Pesanan / pelanggan</th><th>Estimasi selesai</th><th>Persetujuan / pengerjaan</th><th>Pembayaran</th><th>Aksi</th></tr></thead>
    <tbody>@forelse($orders as $order)@php($next=\App\Support\OperationalGuidance::laundry($order))<tr>
        <td data-label="Pesanan / pelanggan"><strong>{{ $order->code }}</strong><br>{{ $order->customer_snapshot['name'] }} @if($order->is_demo)<span class="badge badge-gold">DEMO</span> @endif</td>
        <td data-label="Estimasi selesai">{{ $order->estimated_ready_at->format('d M Y H:i') }} WIB @if($order->deadlineWarning())<p class="laundry-deadline">{{ $order->deadlineWarning() }}</p> @endif</td>
        <td data-label="Persetujuan / pengerjaan">{{ \App\Models\LaundryOrder::STATUSES[$order->status] }}<br><strong>{{ \App\Models\LaundryOrder::WORKFLOW[$order->workflow_status] }}</strong></td>
        <td data-label="Pembayaran">@if($order->balance() === null)Nilai belum tersedia @else Sisa Rp {{ number_format($order->balance(), 0, ',', '.') }}<br>{{ $order->balance() === 0 ? 'Lunas' : ($order->paidAmount() > 0 ? 'Dibayar sebagian' : 'Belum dibayar') }} @endif</td>
        <td data-label="Aksi"><a class="button button-secondary" href="{{ route('admin.laundry.orders.show', array_merge(['order'=>$order], ['queue'=>request()->only(['search','workflow','deadline','include_demo','page'])])) . '#'.$next['anchor'] }}">{{ $next['label'] }}</a><span class="ops-subtitle">{{ $next['hint'] }}</span></td>
    </tr>@empty<tr><td colspan="5">Tidak ada pesanan yang sesuai filter.</td></tr>@endforelse</tbody>
</table>{{ $orders->links() }}</div></section>
@endsection
