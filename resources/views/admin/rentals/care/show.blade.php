@extends('layouts.app')
@section('title', 'Riwayat '.$unit->code.' - Rental Gayatri')
@section('content')
    <x-ui.page-header eyebrow="Pemeriksaan & Perawatan" title="{{ $unit->code }}" description="{{ $unit->product->name }} · {{ \App\Models\RentalUnit::STATUSES[$unit->status] }}" />
    @include('admin.rentals.partials.navigation')
    <div class="card"><div class="card-body"><h2>Kondisi unit</h2><p>{{ $unit->condition_notes }}</p><p>Nomor seri: {{ $unit->serial_number ?: '-' }}<br>Perawatan berikutnya: {{ $unit->next_maintenance_at?->format('d M Y') ?: 'Belum dijadwalkan' }}</p>
        <p class="muted">Total biaya perawatan tercatat: Rp {{ number_format($unit->careRecords()->sum('cost'), 0, ',', '.') }}. Biaya ini merupakan pengeluaran internal dan tidak otomatis dibebankan kepada penyewa.</p>
        @if($unit->maintenanceDue())<div class="rental-alert rental-error">Perawatan telah jatuh tempo. Selesaikan pekerjaan dan tetapkan jadwal berikutnya atau kosongkan jika tidak ada.</div>@endif
    </div></div>
    @if(!in_array($unit->status, ['rented', 'inspection', 'retired']))
        <div class="card"><div class="card-body"><h2>Catat pemeriksaan / perawatan</h2><p class="muted">Perubahan status dapat memengaruhi pesanan mendatang. Peringatan Rental akan menampilkan unit pesanan yang belum siap.</p>
            <form class="rental-form rental-grid" method="POST" enctype="multipart/form-data" action="{{ route('admin.rentals.care.store', $unit) }}">@csrf
                <input type="hidden" name="entry_key" value="{{ \Illuminate\Support\Str::uuid() }}"><input type="hidden" name="care_version" value="{{ $unit->care_version }}">
                <label>Jenis pekerjaan<select class="input" name="kind" required><option value="inspection">Pemeriksaan berkala</option><option value="cleaning">Pembersihan</option><option value="maintenance">Perbaikan / perawatan</option></select></label>
                <label>Status setelah pekerjaan<select class="input" name="unit_status">@foreach(['ready', 'cleaning', 'maintenance', 'retired'] as $status)<option value="{{ $status }}" @selected($unit->status === $status)>{{ \App\Models\RentalUnit::STATUSES[$status] }}</option>@endforeach</select></label>
                <div class="rental-wide">@include('admin.rentals.partials.checklist', ['checklistLabels' => $unit->product->inspection_checklist ?? []])</div>
                <label class="rental-wide">Hasil pekerjaan & kondisi aktual<textarea class="input" name="notes" minlength="3" maxlength="3000" rows="3" required placeholder="Catat kondisi, kelengkapan, dan pekerjaan yang dilakukan"></textarea></label>
                <label>Biaya perawatan (Rp)<input class="input" name="cost" type="number" min="0" max="1000000000" step="1" value="0" required></label>
                <label>Vendor / petugas tambahan (opsional)<input class="input" name="vendor" maxlength="150"></label>
                <label>Perawatan berikutnya (opsional)<input class="input" name="next_maintenance_at" type="date" min="{{ today()->addDay()->toDateString() }}" value="{{ $unit->next_maintenance_at?->isFuture() ? $unit->next_maintenance_at->toDateString() : '' }}"><span class="muted rental-small">Unit hanya dapat dipesan jika masa sewanya berakhir sebelum tanggal ini.</span></label>
                <label>Foto kondisi / hasil pekerjaan (maks. 5 MB)<input class="input" name="photo" type="file" accept="image/jpeg,image/png,image/webp"></label>
                <div class="rental-wide"><button class="button button-primary">Simpan Riwayat Perawatan</button></div>
            </form>
        </div></div>
    @else<p class="rental-alert">{{ $unit->status === 'retired' ? 'Unit sudah diarsipkan. Riwayat tetap tersedia.' : 'Selesaikan pengembalian dan pemeriksaan melalui detail transaksi sebelum melakukan perawatan unit.' }}</p>@endif
    <h2>Riwayat pemeriksaan & perawatan</h2>
    @forelse($records as $record)<article class="card"><div class="card-body">
        <h3>{{ \App\Models\RentalCareRecord::KINDS[$record->kind] ?? $record->kind }}</h3><p class="muted">{{ $record->performed_at->format('d M Y H:i') }} WIB · {{ $record->performer?->name ?: 'Petugas tidak tersedia' }}</p>
        <p>{{ $record->notes }}</p><p>{{ \App\Models\RentalUnit::STATUSES[$record->status_before] ?? 'Baru' }} → {{ \App\Models\RentalUnit::STATUSES[$record->status_after] ?? $record->status_after }}</p>
        @if($record->item?->order)<p><a href="{{ route('admin.rentals.orders.show', $record->item->order) }}">{{ $record->item->order->code }}</a></p>@endif
        @if($record->checklist)<details><summary>Hasil checklist ({{ count($record->checklist) }} butir)</summary><ul>@foreach($record->checklist as $row)<li>{{ $row['label'] }}: <strong>{{ $row['result'] === 'ok' ? 'Baik / lengkap' : 'Bermasalah' }}</strong></li>@endforeach</ul></details>@endif
        @if($record->cost || $record->vendor)<p>Biaya Rp {{ number_format($record->cost, 0, ',', '.') }} · {{ $record->vendor ?: 'Internal' }}</p>@endif
        @if($record->next_maintenance_at)<p class="muted">Jadwal berikutnya saat pencatatan: {{ $record->next_maintenance_at->format('d M Y') }}</p>@endif
        @if($record->photo_path)<a class="button button-secondary" href="{{ route('admin.rentals.care.photo', $record) }}" target="_blank" rel="noopener">Lihat foto kondisi</a>@endif
    </div></article>@empty<p class="muted">Belum ada catatan pemeriksaan/perawatan. Riwayat baru akan tampil di sini.</p>@endforelse
    {{ $records->links() }}
@endsection
