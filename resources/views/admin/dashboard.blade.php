@extends('layouts.app')

@section('title', 'Dashboard - Gayatri CRM')

@section('content')
    <x-ui.page-header
        eyebrow="Dashboard Overview"
        title="Ringkasan Bisnis {{ $rangeLabel }}"
        description="Pantau customer, booking, WhatsApp, campaign, dan aktivitas AI dalam satu dashboard premium yang mudah dibaca."
    >
        <x-slot name="actions">
            <a class="button button-secondary" href="{{ route('admin.reports.index') }}"><i data-lucide="bar-chart-3"></i> Reports</a>
        </x-slot>
    </x-ui.page-header>

    <div class="card" style="margin-bottom:1rem;"><div class="card-body"><form method="GET" class="grid grid-4"><select class="input" name="range"><option value="today" @selected($range==='today')>Hari ini</option><option value="week" @selected($range==='week')>Minggu ini</option><option value="month" @selected($range==='month')>Bulan ini</option><option value="custom" @selected($range==='custom')>Custom</option></select><input class="input" type="date" name="start_date" value="{{ $startDate }}"><input class="input" type="date" name="end_date" value="{{ $endDate }}"><button class="button button-secondary" type="submit"><i data-lucide="filter"></i> Terapkan</button></form></div></div>

    <section class="grid grid-4">
        <x-ui.stat-card icon="users" label="Total Customer" value="{{ number_format($totalCustomers) }}" note="+{{ $newCustomers }} customer baru periode ini" badge="CRM" />
        <x-ui.stat-card icon="message-circle" label="Chat Masuk" value="{{ $incomingChats }}" note="{{ $unansweredChats }} conversation unread" badge="WA" />
        <x-ui.stat-card icon="calendar-check" label="Booking Periode" value="{{ $bookingsInRange }}" note="Hari ini {{ $bookingsToday }}, minggu {{ $bookingsWeek }}, bulan {{ $bookingsMonth }}" badge="Booking" />
        <x-ui.stat-card icon="star" label="Rating Rata-Rata" value="{{ $averageRating ?: '-' }}" note="{{ $feedbackCount }} feedback, {{ $complaintCount }} komplain" badge="Feedback" />
    </section>

    <section class="grid grid-4" style="margin-top:1rem;">
        <x-ui.stat-card icon="banknote" label="Estimasi Revenue" value="Rp {{ number_format($estimatedRevenue,0,',','.') }}" note="Booking non-cancelled/no-show" badge="Sales" />
        <x-ui.stat-card icon="phone-forwarded" label="Follow-Up Pending" value="{{ $pendingFollowups->count() }}" note="Open follow-up prioritas" badge="Follow-Up" />
        <x-ui.stat-card icon="megaphone" label="Campaign Aktif" value="{{ $activeCampaigns }}" note="Scheduled dan running" badge="Campaign" />
        <x-ui.stat-card icon="bot" label="AI Answered" value="{{ $aiAnswered }}" note="{{ $humanTakeover }} escalated/human takeover" badge="AI" />
    </section>

    @if($wahaDisconnected > 0 || $failedOutgoingMessages > 0)
        <div class="card" style="margin-top:1rem;border-color:rgba(201,122,106,.55);"><div class="card-body" style="color:#7a3228;display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;"><span><strong>Reliability Alert:</strong> {{ $wahaDisconnected }} session WAHA tidak connected, {{ $failedOutgoingMessages }} pesan outgoing gagal.</span><a class="button button-secondary" href="{{ route('admin.whatsapp.session') }}">Cek WAHA</a></div></div>
    @endif

    <section class="grid grid-3" style="margin-top: 1rem;">
        <div class="card" style="grid-column: span 2;">
            <div class="card-body">
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 1rem;">
                    <h2 class="section-title">Trend Booking & Chat</h2>
                    <span class="badge badge-gold"><i data-lucide="calendar-days"></i> {{ $rangeLabel }}</span>
                </div>
                <div class="grid" style="grid-template-columns: repeat(7, 1fr); align-items: end; min-height: 16rem; gap: .65rem;">
                    @foreach ($trend as $item)
                        <div title="{{ $item['label'] }}: {{ $item['bookings'] }} booking, {{ $item['chats'] }} chat" style="height: {{ $item['height'] }}%; min-height: 4rem; border-radius: 1rem 1rem .45rem .45rem; background: linear-gradient(180deg, var(--gold), var(--soft-brown)); opacity: .9;"></div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h2 class="section-title">AI Alert</h2>
                <ul class="soft-list">
                    <li class="soft-list-item"><span>AI answered hari ini</span><span class="badge badge-green">{{ $aiAnswered }}</span></li>
                    <li class="soft-list-item"><span>Human takeover/escalated</span><span class="badge badge-gold">{{ $humanTakeover }}</span></li>
                    <li class="soft-list-item"><span>Komplain feedback</span><span class="badge badge-red">{{ $complaintCount }}</span></li>
                </ul>
            </div>
        </div>
    </section>

    <section class="grid grid-2" style="margin-top: 1rem;">
        <div class="card">
            <div class="card-body">
                <h2 class="section-title">Booking Hari Ini</h2>
                <table class="table-card">
                    <thead>
                        <tr><th>Customer</th><th>Layanan</th><th>Jam</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        @forelse($todayBookings as $booking)<tr><td data-label="Customer">{{ $booking->customer?->name }}</td><td data-label="Layanan">{{ $booking->service?->name ?: '-' }}</td><td data-label="Jam">{{ substr((string) $booking->start_time,0,5) }}</td><td data-label="Status"><span class="badge badge-gold">{{ $booking->status }}</span></td></tr>@empty<tr><td colspan="4" style="text-align:center;padding:2rem;">Belum ada booking hari ini.</td></tr>@endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h2 class="section-title">Pending Follow-Up</h2>
                <ul class="soft-list">
                    @forelse($pendingFollowups as $followup)<li class="soft-list-item"><span>{{ $followup->customer?->name }} - {{ $followup->title }}</span><a class="button button-secondary" href="{{ route('admin.followups.index') }}">Follow-Up</a></li>@empty<li class="soft-list-item"><span>Tidak ada follow-up pending.</span><span class="badge badge-green">Clear</span></li>@endforelse
                </ul>
            </div>
        </div>
    </section>
@endsection
