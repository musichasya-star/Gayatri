@extends('layouts.app')
@section('title', 'Jadwal Antar & Jemput - Rental Gayatri')
@section('content')
    <x-ui.page-header eyebrow="Rental Perlengkapan" title="Jadwal Antar & Jemput" description="Jendela waktu dan penugasan petugas dalam WIB." />
    @include('admin.rentals.partials.navigation')
    <p><a class="button button-secondary" href="{{ route('admin.rentals.orders.index') }}">Pilih transaksi untuk membuat jadwal</a></p>
    <div class="card"><div class="card-body">
        <p role="status"><strong>{{ $overdueCount }} tugas aktif melewati jendela jadwal.</strong> Jumlah mengikuti filter petugas untuk seluruh tanggal dan status aktif.</p>
        <form method="GET" action="{{ route('admin.rentals.transport.index') }}" class="ops-filters">
            <label>Tanggal (WIB)<input class="input" type="date" name="date" value="{{ $date }}" required></label>
            <label>Status<select class="input" name="status"><option value="">Semua status</option>
                @foreach(\App\Models\RentalTransportTask::STATUSES as $value => $label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach
            </select></label>
            <label>Petugas<select class="input" name="staff"><option value="">Semua petugas</option>
                @foreach($staff as $person)<option value="{{ $person->id }}" @selected((string) request('staff') === (string) $person->id)>{{ $person->name }}{{ $person->status !== 'active' ? ' (nonaktif)' : '' }}</option>@endforeach
            </select></label>
            <button class="button button-primary">Tampilkan Jadwal</button>
        </form>
    </div></div>
    @include('admin.rentals.partials.operational-alerts')
    <div class="card"><div class="card-body"><table class="table-card"><thead><tr><th>Jadwal / jenis</th><th>Transaksi / pelanggan</th><th>Petugas / status</th><th>Tindak lanjut</th></tr></thead><tbody>
    @forelse($tasks as $task)<tr>
    <td data-label="Jadwal / jenis"><strong>{{ $task->scheduled_start->setTimezone('Asia/Jakarta')->format('d M Y H:i') }}–{{ $task->scheduled_end->setTimezone('Asia/Jakarta')->format('H:i') }} WIB</strong><span class="ops-subtitle">{{ \App\Models\RentalTransportTask::TYPES[$task->type] }}</span></td>
    <td data-label="Transaksi / pelanggan"><a href="{{ route('admin.rentals.orders.show',$task->order) }}#transport">{{ $task->order->code }}</a><span class="ops-subtitle">{{ $task->order->customer->name }}</span><details><summary>Alamat & catatan</summary><p>{{ $task->order->address ?: 'Alamat belum tersedia' }}</p><p>{{ $task->notes ?: 'Tidak ada catatan.' }}</p></details></td>
    <td data-label="Petugas / status">{{ $task->assignedUser?->name ?? 'Belum tersedia' }}<span class="ops-subtitle">{{ \App\Models\RentalTransportTask::STATUSES[$task->status] }}</span>@if($task->isOverdue())<p class="rental-error">Melewati jendela jadwal.</p>@endif @if($task->isActive() && $task->hasStaleSchedule())<p class="rental-error">Kontrak berubah. Jadwalkan ulang penjemputan.</p>@endif</td>
    <td data-label="Tindak lanjut"><a class="button button-secondary" href="{{ route('admin.rentals.transport.edit',[$task->order,$task]) }}">{{ $task->isActive() && $task->hasStaleSchedule() ? 'Jadwalkan ulang' : ($task->isActive() ? 'Kelola perjalanan' : 'Lihat riwayat tugas') }}</a></td>
    </tr>@empty<tr><td colspan="4">Tidak ada tugas pada filter ini.</td></tr>@endforelse</tbody></table></div></div>
    {{ $tasks->links() }}
@endsection
