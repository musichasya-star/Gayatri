@extends('layouts.app')

@section('title', 'Bookings - Gayatri CRM')

@section('content')
@include('partials.admin-operations-assets')
    <x-ui.page-header eyebrow="Booking" title="Booking Layanan" description="Kelola jadwal pijat & spa atau laktasi & konsultasi ASI sesuai kelompok layanan.">
        <x-slot name="actions"><a class="button button-secondary" href="{{ route('admin.bookings.calendar',request()->only(['date','service_group'])) }}"><i data-lucide="calendar-days"></i> Calendar</a><a class="button button-primary" href="{{ route('admin.bookings.create') }}"><i data-lucide="plus"></i> Tambah Booking</a></x-slot>
    </x-ui.page-header>
    @if(session('status'))<div class="card" style="margin-bottom:1rem;border-color:rgba(143,174,139,.55);"><div class="card-body" style="color:#3f6d3d;">{{ session('status') }}</div></div>@endif
    <div class="card" style="margin-bottom:1rem;"><div class="card-body">
    <form method="GET" class="ops-filters">
        <label>Cari kode / pelanggan<input class="input" name="search" maxlength="100" value="{{ request('search') }}" placeholder="Kode booking atau nama"></label>
        <label>Tanggal booking<input class="input" type="date" name="date" value="{{ request('date') }}"></label>
        <label>Kelompok layanan<select class="input" name="service_group"><option value="">Semua kelompok layanan</option>@foreach(\App\Support\ServiceGroup::LABELS as $key=>$label)<option value="{{ $key }}" @selected(request('service_group')===$key)>{{ $label }}</option>@endforeach</select></label>
        <label>Status jadwal<select class="input" name="status"><option value="">Semua status</option>@foreach($statuses as $status)<option value="{{ $status }}" @selected(request('status')===$status)>{{ \App\Support\BookingStatus::label($status) }}</option>@endforeach</select></label>
<label><input type="checkbox" name="attention" value="pending" @checked(request('attention')==='pending')> Draf / menunggu konfirmasi · tanpa demo</label>
        <div class="ops-actions"><button class="button button-secondary">Terapkan filter</button><a href="{{ route('admin.bookings.index') }}">Reset</a></div>
    </form></div></div>
    @php($context=['return_to'=>'bookings','return_filters'=>request()->only(['date','status','service_group','page','search','attention'])])
    <div class="card"><div class="card-body"><p class="muted">{{ $bookings->total() }} booking sesuai filter. Status jadwal dan pembayaran ditampilkan terpisah.</p>
    <table class="table-card"><thead><tr><th>Booking / pelanggan</th><th>Layanan</th><th>Jadwal / terapis</th><th>Status jadwal</th><th>Pembayaran</th><th>Tindak lanjut</th></tr></thead><tbody>
    @forelse($bookings as $booking)
    @php($next=\App\Support\OperationalGuidance::booking($booking))
    @php($approval=($bookingApprovalMap[(string)$booking->id] ?? null) ?: ($bookingApprovalMap[$booking->booking_code] ?? null))
    <tr>
        <td data-label="Booking / pelanggan"><strong>{{ $booking->booking_code }}</strong><span class="ops-subtitle">{{ $booking->customer?->name ?: 'Pelanggan belum tersedia' }}</span></td>
        <td data-label="Layanan">{{ $booking->service?->name ?: '—' }}<span class="ops-subtitle">{{ $booking->service_mode==='home' ? 'Kunjungan ke rumah' : 'Di outlet' }}</span>
        @if($booking->service_mode==='home')<details><summary>Alamat & perjalanan</summary><p>{{ $booking->visit_address }}<br>Patokan: {{ $booking->visit_landmark ?: '—' }}</p><p>Ongkos tersimpan Rp {{ number_format((float)$booking->travel_fee,0,',','.') }}<br>Waktu terapis termasuk perjalanan {{ $booking->busy_from?->format('H:i') }}–{{ $booking->busy_until?->format('H:i') }}</p>@if($booking->navigationUrl())<a href="{{ $booking->navigationUrl() }}" target="_blank" rel="noopener">Buka navigasi</a>@endif</details>@endif</td>
        <td data-label="Jadwal / terapis">{{ $booking->booking_date?->format('d M Y') }}<br>{{ substr($booking->start_time,0,5) }}–{{ substr($booking->end_time,0,5) }}<span class="ops-subtitle">{{ $booking->therapist?->name ?: 'Terapis belum ditentukan' }}</span></td>
        <td data-label="Status jadwal"><span class="badge {{ $booking->status==='completed' ? 'badge-green' : 'badge-gold' }}">{{ \App\Support\BookingStatus::label($booking->status) }}</span></td>
        <td data-label="Pembayaran" class="ops-money">{{ ['paid'=>'Lunas','partial'=>'Dibayar sebagian','unpaid'=>'Belum dibayar','refunded'=>'Dikembalikan'][$booking->payment_status] ?? $booking->payment_status }}
        @if($booking->finance_managed && $booking->service_price_snapshot!==null)@php($figures=app(\App\Services\CRM\BookingFinance::class)->figures($booking))<span class="ops-subtitle">Sisa {{ \App\Services\CRM\BookingFinance::money($figures['balance']) }}</span>@else<span class="ops-subtitle">{{ $booking->finance_managed ? 'Harga historis perlu ditinjau' : 'Status historis · jurnal belum aktif' }}</span>@endif</td>
        <td data-label="Tindak lanjut"><a class="button button-secondary" href="{{ route($next['route'], array_merge(['booking'=>$booking],$context)) }}">{{ $next['label'] }}</a><details style="margin-top:.6rem"><summary>Pilihan lainnya</summary><div class="ops-actions" style="margin:.6rem 0"><a href="{{ route('admin.bookings.edit',array_merge(['booking'=>$booking],$context)) }}">Edit jadwal</a><a href="{{ route('admin.bookings.finance',array_merge(['booking'=>$booking],$context)) }}">Pembayaran & kuitansi</a>@if($approval)<a href="{{ route('admin.ai.data-automation.approvals.show',$approval) }}">Preview Approval</a>@endif</div><form method="POST" action="{{ route('admin.bookings.destroy',$booking) }}" onsubmit="return confirm('Hapus booking {{ $booking->booking_code }}?');">@csrf @method('DELETE') <input type="hidden" name="return_to" value="bookings">@foreach($context['return_filters'] as $key=>$value)<input type="hidden" name="return_filters[{{ $key }}]" value="{{ $value }}">@endforeach<button class="button button-ghost">Hapus booking</button></form></details></td>
    </tr>
    @empty<tr><td colspan="6">Tidak ada booking sesuai filter. Coba tanggal atau status lainnya.</td></tr>@endforelse
    </tbody></table>{{ $bookings->links() }}</div></div>
@endsection
