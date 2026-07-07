@extends('layouts.app')

@section('title', 'Review Approval - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="AI Data Automation" title="Review Approval" description="Periksa proposed data sebelum menjalankan aksi ke database.">
        <x-slot name="actions"><a class="button button-secondary" href="{{ route('admin.ai.data-automation.approvals.index') }}"><i data-lucide="arrow-left"></i> Kembali</a></x-slot>
    </x-ui.page-header>
    @if($errors->any())<div class="card" style="margin-bottom:1rem;border-color:rgba(201,122,106,.55);"><div class="card-body" style="color:#7a3228;">{{ $errors->first() }}</div></div>@endif
    <section class="grid grid-2"><div class="card"><div class="card-body"><h2 class="section-title">Ringkasan Approval</h2><ul class="soft-list"><li class="soft-list-item"><span>Customer</span><strong>{{ $approval->customer?->name ?: '-' }}</strong></li><li class="soft-list-item"><span>Target</span><strong>{{ $approval->target_entity }}</strong></li><li class="soft-list-item"><span>Action</span><strong>{{ $approval->action }}</strong></li><li class="soft-list-item"><span>Status</span><span class="badge badge-gold">{{ $approval->status }}</span></li><li class="soft-list-item"><span>Expires</span><strong>{{ $approval->expires_at?->format('d M Y H:i') ?: '-' }}</strong></li></ul></div></div><div class="card"><div class="card-body"><h2 class="section-title">Pesan Customer</h2><p class="muted">{{ $approval->extractedData?->message?->content ?: '-' }}</p></div></div></section>
    @php
        $proposed = $approval->proposed_data ?? [];
        $booking = $proposed['booking'] ?? [];
        $customer = $proposed['customer'] ?? [];
        $rule = $proposed['rule'] ?? [];
        $actionLabel = match ($approval->action) {
            'reschedule_booking' => 'Perubahan Jadwal',
            'cancel_booking' => 'Pembatalan Booking',
            'create_booking_draft', 'create_booking_confirmed' => 'Booking Baru',
            default => str($approval->action)->replace('_', ' ')->title(),
        };
    @endphp
    <div class="card" style="margin-top:1rem;">
        <div class="card-body">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:1rem;">
                <div>
                    <h2 class="section-title">Detail Request</h2>
                    <p class="muted" style="margin:0;">Ringkasan data yang akan diproses jika approval disetujui.</p>
                </div>
                <span class="badge badge-gold">{{ $actionLabel }}</span>
            </div>

            @if($approval->action === 'reschedule_booking')
                <section class="grid grid-2">
                    <div class="card"><div class="card-body"><h3 class="section-title">Jadwal Saat Ini</h3><ul class="soft-list"><li class="soft-list-item"><span>Kode Booking</span><strong>{{ $booking['booking_code'] ?? '-' }}</strong></li><li class="soft-list-item"><span>Layanan</span><strong>{{ $booking['service_name'] ?? '-' }}</strong></li><li class="soft-list-item"><span>Tanggal</span><strong>{{ $booking['current_booking_date'] ?? '-' }}</strong></li><li class="soft-list-item"><span>Jam</span><strong>{{ substr((string) ($booking['current_start_time'] ?? ''), 0, 5) ?: '-' }}</strong></li></ul></div></div>
                    <div class="card"><div class="card-body"><h3 class="section-title">Jadwal Baru Diminta</h3><ul class="soft-list"><li class="soft-list-item"><span>Tanggal Baru</span><strong>{{ $booking['booking_date'] ?? '-' }}</strong></li><li class="soft-list-item"><span>Jam Baru</span><strong>{{ substr((string) ($booking['start_time'] ?? ''), 0, 5) ?: '-' }}</strong></li><li class="soft-list-item"><span>Slot ID</span><strong>{{ $booking['availability_slot_id'] ?? '-' }}</strong></li><li class="soft-list-item"><span>Sumber</span><strong>{{ $booking['source'] ?? '-' }}</strong></li></ul></div></div>
                </section>
            @elseif($approval->action === 'cancel_booking')
                <section class="grid grid-2">
                    <div class="card"><div class="card-body"><h3 class="section-title">Booking yang Dibatalkan</h3><ul class="soft-list"><li class="soft-list-item"><span>Kode Booking</span><strong>{{ $booking['booking_code'] ?? '-' }}</strong></li><li class="soft-list-item"><span>Layanan</span><strong>{{ $booking['service_name'] ?? $booking['active_service_name'] ?? '-' }}</strong></li><li class="soft-list-item"><span>Tanggal</span><strong>{{ $booking['booking_date'] ?? $booking['active_booking_date'] ?? '-' }}</strong></li><li class="soft-list-item"><span>Jam</span><strong>{{ substr((string) ($booking['start_time'] ?? $booking['active_start_time'] ?? ''), 0, 5) ?: '-' }}</strong></li></ul></div></div>
                    <div class="card"><div class="card-body"><h3 class="section-title">Catatan</h3><p class="muted">Customer meminta pembatalan. Approval akan membatalkan booking terkait jika disetujui.</p></div></div>
                </section>
            @else
                <section class="grid grid-2">
                    <div class="card"><div class="card-body"><h3 class="section-title">Data Customer</h3><ul class="soft-list"><li class="soft-list-item"><span>Nama</span><strong>{{ $customer['name'] ?? $approval->customer?->name ?? '-' }}</strong></li><li class="soft-list-item"><span>Alamat</span><strong>{{ $customer['address'] ?? $approval->customer?->address ?? '-' }}</strong></li></ul></div></div>
                    <div class="card"><div class="card-body"><h3 class="section-title">Data Booking</h3><ul class="soft-list"><li class="soft-list-item"><span>Layanan</span><strong>{{ $booking['service_name'] ?? $booking['service_id'] ?? '-' }}</strong></li><li class="soft-list-item"><span>Tanggal</span><strong>{{ $booking['booking_date'] ?? '-' }}</strong></li><li class="soft-list-item"><span>Jam</span><strong>{{ substr((string) ($booking['start_time'] ?? ''), 0, 5) ?: '-' }}</strong></li><li class="soft-list-item"><span>Slot ID</span><strong>{{ $booking['availability_slot_id'] ?? '-' }}</strong></li></ul></div></div>
                </section>
            @endif

            <section class="grid grid-3" style="margin-top:1rem;">
                <div class="card"><div class="card-body"><h3 class="section-title">Intent</h3><p class="muted">{{ $proposed['intent'] ?? $approval->extractedData?->intent ?? '-' }}</p></div></div>
                <div class="card"><div class="card-body"><h3 class="section-title">Confidence</h3><p class="muted">{{ isset($proposed['confidence_score']) ? number_format((float) $proposed['confidence_score'] * 100, 0).'%' : '-' }}</p></div></div>
                <div class="card"><div class="card-body"><h3 class="section-title">Rule</h3><p class="muted">{{ $rule['name'] ?? '-' }}</p></div></div>
            </section>
        </div>
    </div>
    @if($approval->status === 'pending')<section class="grid grid-3" style="margin-top:1rem;"><div class="card"><div class="card-body"><h2 class="section-title">Approve</h2><p class="muted">Menyetujui akan menjalankan action ke database. Booking baru akan menjadi confirmed; reschedule akan mengubah jadwal booking existing.</p><form method="POST" action="{{ route('admin.ai.data-automation.approvals.approve', $approval) }}">@csrf<button class="button button-primary" type="submit"><i data-lucide="check"></i> Approve</button></form></div></div><div class="card"><div class="card-body"><h2 class="section-title">Edit & Approve</h2><form method="POST" action="{{ route('admin.ai.data-automation.approvals.edit-approve', $approval) }}" class="grid">@csrf<input class="input" name="booking_id" placeholder="Booking ID" value="{{ data_get($approval->proposed_data, 'booking.booking_id') }}"><input class="input" name="service_id" placeholder="Service ID" value="{{ data_get($approval->proposed_data, 'booking.service_id') }}"><input class="input" name="availability_slot_id" placeholder="Availability Slot ID" value="{{ data_get($approval->proposed_data, 'booking.availability_slot_id') }}"><input class="input" type="date" name="booking_date" value="{{ data_get($approval->proposed_data, 'booking.booking_date') }}"><input class="input" type="time" name="start_time" value="{{ substr((string) data_get($approval->proposed_data, 'booking.start_time'), 0, 5) }}"><button class="button button-primary" type="submit"><i data-lucide="edit"></i> Edit & Approve</button></form></div></div><div class="card"><div class="card-body"><h2 class="section-title">Reject</h2><form method="POST" action="{{ route('admin.ai.data-automation.approvals.reject', $approval) }}" class="grid">@csrf<label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Alasan Reject</span><textarea class="input" name="rejection_reason" rows="4" required></textarea></label><button class="button button-secondary" type="submit"><i data-lucide="x"></i> Reject</button></form></div></div></section>@endif
@endsection
