@extends('layouts.app')

@section('title', $customer->name . ' - Gayatri CRM')

@section('content')
@include('partials.admin-workbench-assets')
    <x-ui.page-header
        eyebrow="Customer Detail"
        title="{{ $customer->name }}"
        description="Overview customer, histori komunikasi, booking, follow-up, campaign, feedback, dan catatan."
    >
        <x-slot name="actions">
            <a class="button button-secondary" href="{{ \App\Support\AdminReviewContext::back(request(),'customers') }}"><i data-lucide="arrow-left"></i> Semua Customer</a>
            @if(auth()->user()->hasRole(['owner','manager','admin']))<a class="button button-secondary" href="{{ route('admin.bookings.create', ['customer_id' => $customer->id]) }}"><i data-lucide="calendar-plus"></i> Buat Booking</a>@endif
            <a class="button button-primary" href="{{ route('admin.customers.edit',array_merge(['customer'=>$customer],\App\Support\AdminReviewContext::carry(request(),'customers'))) }}"><i data-lucide="pencil"></i> Edit</a>
        </x-slot>
    </x-ui.page-header>

    @if (session('status'))
        <div class="card" style="margin-bottom: 1rem; border-color: rgba(143,174,139,.55);">
            <div class="card-body" style="color: #3f6d3d;">{{ session('status') }}</div>
        </div>
    @endif

    <div class="grid grid-2" style="align-items: start;">
        <div class="card">
            <div class="card-body">
                <h2 class="section-title">Overview</h2>
                <p><strong>Status:</strong> {{ ucfirst(str_replace('_', ' ', $customer->status)) }}</p>
                <p><strong>Cabang:</strong> {{ $customer->branch?->name ?: '-' }}</p>
                <p><strong>Telepon:</strong> {{ $customer->phone ?: '-' }}</p>
                <p><strong>WhatsApp:</strong> {{ $customer->whatsapp_number ?: '-' }}</p>
                <p><strong>Email:</strong> {{ $customer->email ?: '-' }}</p>
                <p><strong>Tanggal Lahir Ibu:</strong> {{ $customer->birth_date?->format('d M Y') ?: '-' }}</p>
                <p><strong>Nama Bayi:</strong> {{ $customer->baby_name ?: '-' }}</p>
                <p><strong>Tanggal Lahir Bayi:</strong> {{ $customer->baby_birth_date?->format('d M Y') ?: '-' }}</p>
                <p><strong>Kota:</strong> {{ $customer->city ?: '-' }}</p>
                <p><strong>Tag:</strong> {{ filled($customer->tags) ? implode(', ', $customer->tags) : '-' }}</p>
                <p><strong>Interaksi Terakhir:</strong> {{ $customer->last_interaction_at?->format('d M Y H:i') ?: '-' }}</p>
                <p><strong>Booking Terakhir:</strong> {{ $customer->last_booking_at?->format('d M Y H:i') ?: '-' }}</p>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h2 class="section-title">Notes</h2>
                <p class="muted">{{ $customer->notes ?: 'Belum ada catatan untuk customer ini.' }}</p>
            </div>
        </div>
    </div>

    <div class="grid grid-2" style="margin-top: 1rem; align-items: start;">
        <details class="ops-section"><summary>Percakapan WhatsApp</summary>
            <div class="card-body">

                @forelse ($customer->conversations as $conversation)
                    <div style="padding: .85rem 0; border-bottom: 1px solid rgba(var(--g-ocean-rgb), .16);">
                        <strong>{{ $conversation->wa_chat_id }}</strong>
                        <p class="muted">Status: {{ ucfirst(str_replace('_', ' ', $conversation->status)) }} | Assigned: {{ $conversation->assignedUser?->name ?: '-' }}</p>
                    </div>
                @empty
                    <p class="muted">Belum ada histori conversation.</p>
                @endforelse
            </div>
        </details>

        <details class="ops-section"><summary>Riwayat booking</summary>
            <div class="card-body">

                @forelse ($customer->bookings as $booking)
                    <div style="padding: .85rem 0; border-bottom: 1px solid rgba(var(--g-ocean-rgb), .16);">
                        <strong>{{ $booking->booking_code }}</strong>
                        <p class="muted">{{ $booking->service?->name ?: '-' }} | {{ $booking->booking_date?->format('d M Y') }} {{ $booking->start_time }}</p>
                    </div>
                @empty
                    <p class="muted">Belum ada booking.</p>
                @endforelse
            </div>
        </details>

        <details class="ops-section"><summary>Tindak lanjut</summary>
            <div class="card-body">

                @forelse ($customer->followups as $followup)
                    <div style="padding: .85rem 0; border-bottom: 1px solid rgba(var(--g-ocean-rgb), .16);">
                        <strong>{{ $followup->title }}</strong>
                        <p class="muted">{{ ucfirst($followup->priority) }} | {{ $followup->assignedUser?->name ?: '-' }}</p>
                    </div>
                @empty
                    <p class="muted">Belum ada follow-up.</p>
                @endforelse
            </div>
        </details>

        <details class="ops-section"><summary>Riwayat campaign</summary>
            <div class="card-body">

                @forelse ($customer->campaignRecipients as $recipient)
                    <div style="padding: .85rem 0; border-bottom: 1px solid rgba(var(--g-ocean-rgb), .16);">
                        <strong>{{ $recipient->campaign?->name ?: '-' }}</strong>
                        <p class="muted">Status: {{ ucfirst($recipient->status) }}</p>
                    </div>
                @empty
                    <p class="muted">Belum ada campaign recipient.</p>
                @endforelse
            </div>
        </details>

        <details class="ops-section"><summary>Penilaian pelanggan</summary>
            <div class="card-body">

                @forelse ($customer->feedback as $feedback)
                    <div style="padding: .85rem 0; border-bottom: 1px solid rgba(var(--g-ocean-rgb), .16);">
                        <strong>Rating {{ $feedback->rating ?: '-' }}/5</strong>
                        <p class="muted">{{ $feedback->message ?: 'Tanpa catatan feedback.' }}</p>
                    </div>
                @empty
                    <p class="muted">Belum ada feedback customer.</p>
                @endforelse
            </div>
        </details>
    </div>
    @if(in_array(auth()->user()?->role, ['owner', 'manager', 'admin'], true))
    <section class="card" style="margin-top:1rem"><div class="card-body"><h2 class="section-title">Laundry terbaru</h2><p class="muted">Maksimal tiga pesanan yang terhubung ke identitas pelanggan ini.</p>@forelse($laundryOrders as $entry)<div class="wb-row"><div><strong>{{ $entry->code }}</strong><small>{{ $entry->received_at->format('d M Y') }} · {{ \App\Models\LaundryOrder::WORKFLOW[$entry->workflow_status] }}</small></div><a href="{{ route('admin.laundry.orders.show',$entry) }}">Buka pesanan →</a></div>@empty<p class="muted">Belum ada pesanan Laundry terhubung.</p>@endforelse</div></section>
        <div class="card" style="margin-top:1rem;"><div class="card-body"><h2 class="section-title">Rental Perlengkapan</h2><p class="muted">Lihat transaksi sewa, pembayaran, dan riwayat pengembalian pelanggan ini.</p><a class="button button-secondary" href="{{ route('admin.rentals.orders.index', ['customer_id' => $customer->id]) }}">Lihat Transaksi Rental</a></div></div>
    @endif
@endsection
