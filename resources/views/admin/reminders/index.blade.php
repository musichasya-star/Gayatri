@extends('layouts.app')

@section('title', 'Reminder Log - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="Reminder" title="Reminder Otomatis" description="Pantau reminder treatment, pembayaran, reschedule, dan follow-up. Admin juga bisa membuat reminder manual, kirim sekarang, retry, atau cancel sesuai kebutuhan operasional." />

    @if (session('status'))
        <div class="card" style="margin-bottom: 1rem; border-color: rgba(143,174,139,.55);">
            <div class="card-body" style="color: #3f6d3d;">{{ session('status') }}</div>
        </div>
    @endif

    @if ($errors->any())
        <div class="card" style="margin-bottom: 1rem; border-color: rgba(201,122,106,.55);">
            <div class="card-body" style="color: #7a3228;">{{ $errors->first() }}</div>
        </div>
    @endif

    <div class="grid grid-4" style="margin-bottom: 1rem;">
        <div class="card"><div class="card-body"><p class="stat-label">Total Reminder</p><p class="stat-value">{{ number_format($stats['total']) }}</p><p class="stat-note">Semua log reminder</p></div></div>
        <div class="card"><div class="card-body"><p class="stat-label">Due Sekarang</p><p class="stat-value">{{ number_format($stats['due_now']) }}</p><p class="stat-note">Perlu dikirim / diproses</p></div></div>
        <div class="card"><div class="card-body"><p class="stat-label">Failed</p><p class="stat-value">{{ number_format($stats['failed']) }}</p><p class="stat-note">Perlu retry / evaluasi</p></div></div>
        <div class="card"><div class="card-body"><p class="stat-label">Sent Hari Ini</p><p class="stat-value">{{ number_format($stats['sent_today']) }}</p><p class="stat-note">Terkirim via WhatsApp</p></div></div>
    </div>

    <div class="grid grid-2" style="align-items: start; margin-bottom: 1rem;">
        <div class="card">
            <div class="card-body">
                <h2 class="section-title">Filter Reminder</h2>
                <form method="GET" action="{{ route('admin.reminders.index') }}" class="grid grid-3">
                    <input class="input" type="search" name="search" value="{{ request('search') }}" placeholder="Cari customer, booking, isi reminder">
                    <select class="input" name="status">
                        <option value="">Semua status</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </select>
                    <select class="input" name="type">
                        <option value="">Semua tipe</option>
                        @foreach ($types as $type => $label)
                            <option value="{{ $type }}" @selected(request('type') === $type)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <select class="input" name="due">
                        <option value="">Semua waktu</option>
                        <option value="due_now" @selected(request('due') === 'due_now')>Due sekarang</option>
                        <option value="upcoming" @selected(request('due') === 'upcoming')>Upcoming</option>
                        <option value="failed" @selected(request('due') === 'failed')>Failed</option>
                    </select>
                    <input class="input" type="date" name="date_from" value="{{ request('date_from') }}">
                    <input class="input" type="date" name="date_to" value="{{ request('date_to') }}">
                    <button class="button button-secondary" type="submit"><i data-lucide="search"></i> Filter</button>
                    <a class="button button-ghost" href="{{ route('admin.reminders.index') }}">Reset</a>
                    <a class="button button-secondary" href="{{ route('admin.bookings.index') }}"><i data-lucide="calendar-check"></i> Booking</a>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h2 class="section-title">Buat Reminder Manual</h2>
                <form method="POST" action="{{ route('admin.reminders.store') }}" class="grid grid-2">
                    @csrf
                    <label>
                        <span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Customer</span>
                        <select class="input" name="customer_id" required>
                            <option value="">Pilih customer</option>
                            @foreach ($customers as $customer)
                                <option value="{{ $customer->id }}">{{ $customer->name }} - {{ $customer->whatsapp_number ?: $customer->phone }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>
                        <span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Booking</span>
                        <select class="input" name="booking_id">
                            <option value="">Tanpa booking spesifik</option>
                            @foreach ($bookings as $booking)
                                <option value="{{ $booking->id }}">{{ $booking->booking_code }} - {{ $booking->customer?->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>
                        <span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Tipe Reminder</span>
                        <select class="input" name="type" required>
                            @foreach ($types as $type => $label)
                                <option value="{{ $type }}" @selected(old('type') === $type)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>
                        <span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Channel</span>
                        <select class="input" name="channel" required>
                            <option value="whatsapp">WhatsApp</option>
                        </select>
                    </label>
                    <label>
                        <span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Jadwal Kirim</span>
                        <input class="input" type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at', now()->addHour()->format('Y-m-d\TH:i')) }}" required>
                    </label>
                    <div></div>
                    <label style="grid-column: 1 / -1;">
                        <span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Pesan Override</span>
                        <textarea class="input" name="message" rows="4" placeholder="Kosongkan untuk memakai template otomatis sesuai tipe reminder.">{{ old('message') }}</textarea>
                    </label>
                    <div style="grid-column: 1 / -1; display:flex; justify-content:flex-end;">
                        <button class="button button-primary" type="submit"><i data-lucide="bell-ring"></i> Simpan Reminder</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <table class="table-card">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Booking</th>
                        <th>Tipe</th>
                        <th>Isi Reminder</th>
                        <th>Scheduled</th>
                        <th>Sent</th>
                        <th>Status</th>
                        <th>Error</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($reminders as $reminder)
                        <tr>
                            <td data-label="Customer">
                                <strong>{{ $reminder->customer?->name ?: '-' }}</strong><br>
                                <span class="muted">{{ $reminder->customer?->whatsapp_number ?: ($reminder->customer?->phone ?: '-') }}</span>
                            </td>
                            <td data-label="Booking">
                                {{ $reminder->booking?->booking_code ?: '-' }}<br>
                                <span class="muted">{{ $reminder->booking?->service?->name ?: 'Manual / non-booking' }}</span>
                            </td>
                            <td data-label="Tipe">{{ $types[$reminder->type] ?? strtoupper($reminder->type) }}</td>
                            <td data-label="Isi Reminder">{{ \Illuminate\Support\Str::limit($previewReminderService->buildMessage($reminder), 110) }}</td>
                            <td data-label="Scheduled">{{ $reminder->scheduled_at?->format('d M Y H:i') ?: '-' }}</td>
                            <td data-label="Sent">{{ $reminder->sent_at?->format('d M Y H:i') ?: '-' }}</td>
                            <td data-label="Status">
                                <span class="badge {{ $reminder->status === 'sent' ? 'badge-green' : ($reminder->status === 'failed' ? 'badge-red' : ($reminder->status === 'cancelled' ? 'badge-brown' : 'badge-gold')) }}">
                                    {{ ucfirst($reminder->status) }}
                                </span>
                            </td>
                            <td data-label="Error">{{ \Illuminate\Support\Str::limit($reminder->failed_reason ?: '-', 60) }}</td>
                            <td data-label="Aksi">
                                <div style="display:flex; gap:.4rem; flex-wrap:wrap;">
                                    @if (in_array($reminder->status, ['failed', 'scheduled', 'pending'], true))
                                        <form method="POST" action="{{ route('admin.reminders.send-now', $reminder) }}">
                                            @csrf
                                            <button class="button button-secondary" type="submit">Kirim Sekarang</button>
                                        </form>
                                    @endif
                                    @if ($reminder->status === 'failed')
                                        <form method="POST" action="{{ route('admin.reminders.retry', $reminder) }}">
                                            @csrf
                                            <button class="button button-secondary" type="submit">Retry</button>
                                        </form>
                                    @endif
                                    @if (! in_array($reminder->status, ['sent', 'cancelled'], true))
                                        <form method="POST" action="{{ route('admin.reminders.cancel', $reminder) }}">
                                            @csrf
                                            <button class="button button-ghost" type="submit">Cancel</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 2rem;">Belum ada reminder yang sesuai filter.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            <div style="margin-top: 1rem;">
                {{ $reminders->links() }}
            </div>
        </div>
    </div>
@endsection
