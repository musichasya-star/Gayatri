@extends('layouts.app')
@section('title', 'Antar-Jemput Laundry')
@section('content')
<x-ui.page-header eyebrow="Laundry Ibu & Bayi" title="Jadwal Antar-Jemput Laundry" description="Kelola permintaan jemput sebelum penerimaan, pengantaran, petugas, dan riwayat kunjungan."><x-slot name="actions"><a class="button button-primary" href="{{ route('admin.laundry.transport.create') }}">Jadwal Baru</a><a class="button button-secondary" href="{{ route('admin.laundry.transport.settings') }}">Pengaturan Ongkir</a></x-slot></x-ui.page-header>
@include('admin.laundry.nav')
<section class="card"><div class="card-body"><form class="laundry-filter" method="GET">
    <label>Dari tanggal<input class="input" type="date" name="start_date" value="{{ $filters['start_date'] }}" required></label><label>Sampai tanggal<input class="input" type="date" name="end_date" value="{{ $filters['end_date'] }}" required></label>
    <label>Jenis<select class="input" name="kind"><option value="">Semua jenis</option>@foreach(\App\Models\LaundryTransportTask::KINDS as $key => $label)<option value="{{ $key }}" @selected(($filters['kind'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></label>
    <label>Status<select class="input" name="status"><option value="">Semua status</option>@foreach(\App\Models\LaundryTransportTask::STATUSES as $key => $label)<option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></label>
    <label>Petugas<select class="input" name="courier"><option value="">Semua petugas</option>@foreach($couriers as $courier)<option value="{{ $courier->id }}" @selected((string)($filters['courier'] ?? '') === (string)$courier->id)>{{ $courier->name }}</option>@endforeach</select></label>
    <label class="laundry-check"><input type="checkbox" name="include_demo" value="1" @checked($filters['include_demo'] ?? false)> Sertakan data demo</label><button class="button button-secondary">Terapkan Filter</button>
</form></div></section>
<section class="card" style="margin-top:1rem"><div class="card-body"><table class="table-card"><thead><tr><th>Jadwal / jenis</th><th>Pelanggan / alamat</th><th>Petugas / status</th><th>Kesepakatan ongkir</th><th>Aksi</th></tr></thead><tbody>
@forelse($tasks as $task)<tr><td data-label="Jadwal / jenis">{{ $task->scheduled_start->format('d M Y H:i') }}–{{ $task->scheduled_end->format('H:i') }} WIB<br>{{ \App\Models\LaundryTransportTask::KINDS[$task->kind] }} #{{ $task->id }}</td>
<td data-label="Pelanggan / alamat">{{ $task->customer_snapshot['name'] }}<p class="laundry-preserve">{{ $task->address }}</p>{{ $task->order?->code ?: 'Belum terhubung ke penerimaan' }} @if($task->is_demo)<span class="badge badge-gold">DEMO</span> @endif</td>
<td data-label="Petugas / status">{{ $task->courier->name }}<br><strong>{{ \App\Models\LaundryTransportTask::STATUSES[$task->status] }}</strong>@if($task->scheduled_end->isPast() && in_array($task->status, ['planned','in_transit']))<p class="laundry-deadline">Melewati jadwal kunjungan</p> @endif</td>
<td data-label="Kesepakatan ongkir">Rp {{ number_format($task->quote_snapshot['fee'],0,',','.') }}<br>{{ number_format($task->quote_snapshot['distance_milli']/1000,1,',','.') }} km</td>
<td data-label="Aksi"><a class="button button-secondary" href="{{ route('admin.laundry.transport.show', $task) }}">Buka Kunjungan</a></td></tr>
@empty<tr><td colspan="5">Belum ada kunjungan sesuai filter.</td></tr>@endforelse</tbody></table>{{ $tasks->links() }}</div></section>
<section class="card" style="margin-top:1rem"><div class="card-body"><details><summary>Kelola petugas antar-jemput</summary><form class="laundry-form laundry-grid" method="POST" action="{{ route('admin.laundry.transport.couriers.store') }}">@csrf<label>Nama petugas<input class="input" name="name" maxlength="150" required></label><label>Nomor telepon petugas<input class="input" name="phone" maxlength="30"></label><button class="button button-secondary">Tambah Petugas</button></form>
@foreach($couriers as $courier)<div class="laundry-history"><strong>{{ $courier->name }}</strong> · {{ $courier->phone }} · {{ $courier->is_active ? 'Aktif' : 'Nonaktif' }}<form method="POST" action="{{ route('admin.laundry.transport.couriers.update', $courier) }}">@csrf<input type="hidden" name="is_active" value="{{ $courier->is_active ? 0 : 1 }}"><button class="button button-ghost">{{ $courier->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button></form></div>@endforeach
</details></div></section>
@endsection
