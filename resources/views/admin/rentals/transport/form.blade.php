@extends('layouts.app')
@section('title', 'Angkutan '.$order->code.' - Rental Gayatri')
@section('content')
    <x-ui.page-header eyebrow="Rental Perlengkapan" title="{{ \App\Models\RentalTransportTask::TYPES[$task->type] }} · {{ $order->code }}" description="{{ $order->customer->name }} · Semua jendela waktu menggunakan WIB." />
    @include('admin.rentals.partials.navigation')
    <p><a class="button button-secondary" href="{{ route('admin.rentals.orders.show', $order) }}">Detail Transaksi</a> <a class="button button-secondary" href="{{ route('admin.rentals.transport.index') }}">Semua Jadwal</a></p>
    @include('admin.rentals.partials.operational-alerts')
    @if($errors->any())<p class="muted">Muat ulang halaman bila versi jadwal telah berubah.</p>@endif
    <div class="card"><div class="card-body">
        <h2>Jendela jadwal</h2>
        <p>Kontrak {{ $order->start_date->format('d M Y') }} — {{ $order->end_date->format('d M Y') }} · {{ \App\Models\RentalOrder::FULFILMENTS[$order->fulfilment] }}</p>
        <p>{{ $order->address ?: '-' }}<br>Patokan: {{ $order->landmark ?: '-' }}</p>
        @if($order->navigationUrl())<p><a class="button button-secondary" href="{{ $order->navigationUrl() }}" target="_blank" rel="noopener">Navigasi</a></p>@endif
        @if($task->exists)
            <p>Status: <strong>{{ \App\Models\RentalTransportTask::STATUSES[$task->status] }}</strong> · Versi {{ $task->version }}<br>Petugas: {{ $task->assignedUser?->name ?? 'Belum tersedia' }}</p>
            <p>{{ $task->scheduled_start->setTimezone('Asia/Jakarta')->format('d M Y H:i') }} — {{ $task->scheduled_end->setTimezone('Asia/Jakarta')->format('d M Y H:i') }} WIB</p>
            @if($task->isActive() && $task->hasStaleSchedule($order))<p class="rental-alert rental-error">Kontrak telah berubah. Simpan jadwal baru sebelum memulai atau menyelesaikan penjemputan.</p>@endif
        @endif
        @if($canSchedule)
            <form class="rental-form" method="POST" action="{{ $task->exists ? route('admin.rentals.transport.update', [$order, $task]) : route('admin.rentals.transport.store', $order) }}">
                @csrf
                @if($task->exists) @method('PUT') @endif
                <input type="hidden" name="type" value="{{ $task->type }}">
                <input type="hidden" name="version" value="{{ old('version', $task->version) }}">
                <input type="hidden" name="entry_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                <label>Petugas aktif<select class="input" name="assigned_user_id" required><option value="">Pilih petugas</option>@foreach($staff as $person)<option value="{{ $person->id }}" @selected((string) old('assigned_user_id', $task->assigned_user_id) === (string) $person->id)>{{ $person->name }} ({{ $person->role }})</option>@endforeach</select></label>
                <label>Mulai jendela (WIB)<input class="input" type="datetime-local" name="scheduled_start" value="{{ old('scheduled_start', $task->scheduled_start?->setTimezone('Asia/Jakarta')->format('Y-m-d\TH:i')) }}" required></label>
                <label>Akhir jendela (WIB)<input class="input" type="datetime-local" name="scheduled_end" value="{{ old('scheduled_end', $task->scheduled_end?->setTimezone('Asia/Jakarta')->format('Y-m-d\TH:i')) }}" required></label>
                <p class="muted">{{ $task->type === 'delivery' ? 'Seluruh jendela pengantaran harus berada dalam tanggal kontrak.' : 'Penjemputan paling awal pada tanggal akhir kontrak. Perpanjangan kontrak memerlukan penjadwalan ulang.' }} Jadwal bersebelahan boleh memakai petugas yang sama.</p>
                <label>Catatan jadwal<textarea class="input" name="notes" maxlength="3000">{{ old('notes', $task->notes) }}</textarea></label>
                <button class="button button-primary">{{ $task->status === 'cancelled' ? 'Jadwalkan Ulang Tugas' : 'Simpan Jadwal' }}</button>
            </form>
        @else
            <p>Jadwal ini tidak dapat diubah pada status tugas/pesanan saat ini.</p>
        @endif
    </div></div>
    @if($task->exists && $task->isActive())
        <div class="card"><div class="card-body">
            <h2>Pelaksanaan angkutan</h2>
            <p>Catat serah-terima atau pengembalian unit melalui detail transaksi sebelum menyelesaikan tugas ini.</p>
            @if($task->status === 'scheduled')
                <form method="POST" action="{{ route('admin.rentals.transport.action', [$order, $task]) }}">
                    @csrf
                    <input type="hidden" name="action" value="start"><input type="hidden" name="version" value="{{ old('version', $task->version) }}"><input type="hidden" name="entry_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <button class="button button-primary">Mulai Perjalanan</button>
                </form>
            @else
                <form class="rental-form" method="POST" enctype="multipart/form-data" action="{{ route('admin.rentals.transport.action', [$order, $task]) }}">
                    @csrf
                    <input type="hidden" name="action" value="complete"><input type="hidden" name="version" value="{{ old('version', $task->version) }}"><input type="hidden" name="entry_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <label>Nama penerima / penyerah<input class="input" name="recipient_name" value="{{ old('recipient_name') }}" maxlength="150" required></label>
                    <label>Catatan penyelesaian<textarea class="input" name="notes" maxlength="3000" required>{{ old('notes') }}</textarea></label>
                    <label>Foto bukti privat (opsional, JPG/PNG/WebP, maks. 5 MB)<input class="input" type="file" name="photo" accept="image/jpeg,image/png,image/webp"></label>
                    <button class="button button-primary">Selesaikan Tugas</button>
                </form>
            @endif
            <details><summary>Batalkan tugas angkutan</summary>
                <form class="rental-form" method="POST" action="{{ route('admin.rentals.transport.action', [$order, $task]) }}">
                    @csrf
                    <input type="hidden" name="action" value="cancel"><input type="hidden" name="version" value="{{ old('version', $task->version) }}"><input type="hidden" name="entry_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <label>Alasan pembatalan<textarea class="input" name="notes" maxlength="3000" required></textarea></label>
                    <button class="button button-secondary">Batalkan Tugas</button>
                </form>
            </details>
        </div></div>
    @endif
    @if($task->status === 'completed')
        <div class="card"><div class="card-body"><h2>Bukti penyelesaian</h2><p>Penerima / penyerah: {{ $task->recipient_name }}<br>{{ $task->completed_at?->setTimezone('Asia/Jakarta')->format('d M Y H:i') }} WIB<br>{{ $task->completion_notes }}</p>
            @if($task->proof_photo_path)<a class="button button-secondary" href="{{ route('admin.rentals.transport.photo', [$order, $task]) }}" target="_blank" rel="noopener">Lihat Foto Bukti Privat</a>@endif
        </div></div>
    @elseif($task->status === 'cancelled')
        <p class="rental-alert">Tugas dibatalkan: {{ $task->cancellation_notes }}</p>
    @endif
@endsection
