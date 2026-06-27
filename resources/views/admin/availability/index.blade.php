@extends('layouts.app')

@section('title', 'Jadwal Tersedia - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="Operasional" title="Jadwal Tersedia" description="Input slot tersedia per tanggal, cabang, layanan, dan terapis agar booking dan AI membaca jadwal real." />

    @if(session('status'))<div class="card" style="margin-bottom:1rem;border-color:rgba(143,174,139,.55);"><div class="card-body" style="color:#3f6d3d;">{{ session('status') }}</div></div>@endif
    @if($errors->any())<div class="card" style="margin-bottom:1rem;border-color:rgba(201,122,106,.55);"><div class="card-body" style="color:#7a3228;">{{ $errors->first() }}</div></div>@endif

    <section class="grid grid-2" style="margin-bottom:1rem;align-items:start;">
        <div class="card"><div class="card-body"><h2 class="section-title">Tambah Slot</h2><form method="POST" action="{{ route('admin.availability.store') }}" class="grid grid-2">@csrf
            <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Tanggal</span><input class="input" type="date" name="slot_date" value="{{ old('slot_date', $date) }}" required></label>
            <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Status</span><select class="input" name="status" required>@foreach($statuses as $status)<option value="{{ $status }}">{{ $status }}</option>@endforeach</select></label>
            <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Cabang</span><select class="input" name="branch_id"><option value="">Semua cabang</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></label>
            <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Layanan</span><select class="input" name="service_id" required><option value="">Pilih layanan</option>@foreach($services as $service)<option value="{{ $service->id }}">{{ $service->name }}</option>@endforeach</select></label>
            <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Terapis</span><select class="input" name="therapist_id"><option value="">Siapa saja</option>@foreach($therapists as $therapist)<option value="{{ $therapist->id }}">{{ $therapist->name }}</option>@endforeach</select></label>
            <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Kapasitas</span><input class="input" type="number" name="capacity" min="1" max="20" value="{{ old('capacity', 1) }}" required></label>
            <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Mulai</span><input class="input" type="time" name="start_time" value="{{ old('start_time', '09:00') }}" required></label>
            <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Selesai</span><input class="input" type="time" name="end_time" value="{{ old('end_time', '10:00') }}" required></label>
            <label style="grid-column:1/-1;"><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Catatan</span><input class="input" name="notes" value="{{ old('notes') }}"></label>
            <div style="grid-column:1/-1;display:flex;justify-content:flex-end;"><button class="button button-primary" type="submit"><i data-lucide="plus"></i> Tambah Slot</button></div>
        </form></div></div>

        <div class="card"><div class="card-body"><h2 class="section-title">Bulk Generate</h2><form method="POST" action="{{ route('admin.availability.bulk-generate') }}" class="grid grid-2">@csrf
            <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Tanggal</span><input class="input" type="date" name="slot_date" value="{{ $date }}" required></label>
            <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Interval</span><input class="input" type="number" name="interval_minutes" value="60" min="15" max="240" required></label>
            <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Cabang</span><select class="input" name="branch_id"><option value="">Semua cabang</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></label>
            <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Layanan</span><select class="input" name="service_id" required><option value="">Pilih layanan</option>@foreach($services as $service)<option value="{{ $service->id }}">{{ $service->name }}</option>@endforeach</select></label>
            <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Terapis</span><select class="input" name="therapist_id"><option value="">Siapa saja</option>@foreach($therapists as $therapist)<option value="{{ $therapist->id }}">{{ $therapist->name }}</option>@endforeach</select></label>
            <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Kapasitas</span><input class="input" type="number" name="capacity" min="1" max="20" value="1" required></label>
            <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Dari</span><input class="input" type="time" name="start_time" value="09:00" required></label>
            <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Sampai</span><input class="input" type="time" name="end_time" value="18:00" required></label>
            <div style="grid-column:1/-1;display:flex;justify-content:flex-end;"><button class="button button-secondary" type="submit"><i data-lucide="calendar-plus"></i> Generate Slot</button></div>
        </form></div></div>
    </section>

    <div class="card" style="margin-bottom:1rem;"><div class="card-body"><form method="GET" class="grid grid-4">
        <input class="input" type="date" name="date" value="{{ $date }}">
        <select class="input" name="service_id"><option value="">Semua layanan</option>@foreach($services as $service)<option value="{{ $service->id }}" @selected(request('service_id') == $service->id)>{{ $service->name }}</option>@endforeach</select>
        <select class="input" name="status"><option value="">Semua status</option>@foreach($statuses as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>@endforeach</select>
        <button class="button button-secondary" type="submit"><i data-lucide="search"></i> Filter</button>
    </form></div></div>

    <div class="card"><div class="card-body"><table class="table-card"><thead><tr><th>Tanggal</th><th>Jam</th><th>Layanan</th><th>Cabang/Terapis</th><th>Kapasitas</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
        @forelse($slots as $slot)<tr>
            <td data-label="Tanggal">{{ $slot->slot_date?->format('d M Y') }}</td>
            <td data-label="Jam">{{ substr($slot->start_time,0,5) }} - {{ substr($slot->end_time,0,5) }}</td>
            <td data-label="Layanan">{{ $slot->service?->name }}</td>
            <td data-label="Cabang/Terapis">{{ $slot->branch?->name ?: 'Semua cabang' }}<br><span class="muted">{{ $slot->therapist?->name ?: 'Terapis siapa saja' }}</span></td>
            <td data-label="Kapasitas">{{ $slot->booked_count }} / {{ $slot->capacity }}</td>
            <td data-label="Status"><span class="badge {{ $slot->status === 'available' ? 'badge-green' : ($slot->status === 'full' ? 'badge-gold' : 'badge-red') }}">{{ $slot->status }}</span></td>
            <td data-label="Aksi">@if($slot->status !== 'blocked')<form method="POST" action="{{ route('admin.availability.block', $slot) }}">@csrf<button class="button button-ghost" type="submit">Block</button></form>@else-@endif</td>
        </tr>@empty<tr><td colspan="7" style="text-align:center;padding:2rem;">Belum ada slot jadwal untuk filter ini.</td></tr>@endforelse
    </tbody></table><div style="margin-top:1rem;">{{ $slots->links() }}</div></div></div>
@endsection
