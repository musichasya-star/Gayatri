@extends('layouts.app')
@section('title', 'Perawatan Unit - Rental Gayatri')
@section('content')
    <x-ui.page-header eyebrow="Rental Perlengkapan" title="Perawatan Unit" description="Pemeriksaan, pembersihan, biaya perbaikan, dan riwayat kondisi setiap unit." />
    @include('admin.rentals.partials.navigation')
    @if($dueCount)<div class="rental-alert">{{ $dueCount }} unit memiliki jadwal perawatan dalam 7 hari atau sudah jatuh tempo. <a href="{{ route('admin.rentals.care.index', ['due' => 1]) }}">Lihat unit</a></div>@endif
    <form method="GET" class="ops-filters">
        <label>Cari unit / alat<input class="input" name="search" value="{{ request('search') }}" maxlength="150"></label>
        <label>Status unit<select class="input" name="status"><option value="">Semua status</option>@foreach(\App\Models\RentalUnit::STATUSES as $key => $label)<option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>@endforeach</select></label>
        <label>Jadwal perawatan<select class="input" name="due"><option value="">Semua jadwal</option><option value="1" @selected(request('due') === '1')>Jatuh tempo / 7 hari ke depan</option></select></label><button class="button button-secondary">Tampilkan</button>
    </form>
    <div class="card" style="margin-top:1rem"><div class="card-body"><table class="table-card"><thead><tr><th>Unit / produk</th><th>Kesiapan</th><th>Pemeriksaan terakhir</th><th>Perawatan berikutnya</th><th>Tindak lanjut</th></tr></thead><tbody>
    @forelse($units as $unit)<tr><td data-label="Unit / produk"><strong>{{ $unit->code }}</strong><span class="ops-subtitle">{{ $unit->product->name }}</span><details><summary>Catatan kondisi</summary><p>{{ $unit->condition_notes ?: 'Belum ada catatan kondisi.' }}</p><p>{{ $unit->care_records_count }} catatan riwayat</p></details></td>
    <td data-label="Kesiapan"><span class="badge badge-gold">{{ \App\Models\RentalUnit::STATUSES[$unit->status] }}</span>@if($unit->maintenanceDue())<p class="rental-error">Perawatan jatuh tempo. Unit belum dapat dialokasikan untuk sewa baru.</p>@endif</td>
    <td data-label="Pemeriksaan terakhir">{{ $unit->last_inspected_at?->format('d M Y H:i') ?: 'Belum dicatat' }}</td><td data-label="Perawatan berikutnya">{{ $unit->next_maintenance_at?->format('d M Y') ?: 'Belum dijadwalkan' }}</td>
    <td data-label="Tindak lanjut"><a class="button button-secondary" href="{{ route('admin.rentals.care.show',$unit) }}">Periksa & Lihat Riwayat</a></td></tr>@empty<tr><td colspan="5">Tidak ada unit sesuai filter.</td></tr>@endforelse
    </tbody></table></div></div>
    {{ $units->links() }}
@endsection
