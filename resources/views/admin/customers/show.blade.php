@extends('layouts.app')

@section('title', $customer->name . ' - Gayatri CRM')

@section('content')
    <x-ui.page-header
        eyebrow="Customer Detail"
        title="{{ $customer->name }}"
        description="Overview customer, histori komunikasi, booking, follow-up, campaign, feedback, dan catatan."
    >
        <x-slot name="actions">
            <a class="button button-secondary" href="{{ route('admin.customers.index') }}"><i data-lucide="arrow-left"></i> Semua Customer</a>
            <a class="button button-secondary" href="{{ route('admin.bookings.create', ['customer_id' => $customer->id]) }}"><i data-lucide="calendar-plus"></i> Buat Booking</a>
            <a class="button button-primary" href="{{ route('admin.customers.edit', $customer) }}"><i data-lucide="pencil"></i> Edit</a>
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
        <div class="card">
            <div class="card-body">
                <h2 class="section-title">Conversations</h2>
                @forelse ($customer->conversations as $conversation)
                    <div style="padding: .85rem 0; border-bottom: 1px solid rgba(157,127,102,.16);">
                        <strong>{{ $conversation->wa_chat_id }}</strong>
                        <p class="muted">Status: {{ ucfirst(str_replace('_', ' ', $conversation->status)) }} | Assigned: {{ $conversation->assignedUser?->name ?: '-' }}</p>
                    </div>
                @empty
                    <p class="muted">Belum ada histori conversation.</p>
                @endforelse
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h2 class="section-title">Bookings</h2>
                @forelse ($customer->bookings as $booking)
                    <div style="padding: .85rem 0; border-bottom: 1px solid rgba(157,127,102,.16);">
                        <strong>{{ $booking->booking_code }}</strong>
                        <p class="muted">{{ $booking->service?->name ?: '-' }} | {{ $booking->booking_date?->format('d M Y') }} {{ $booking->start_time }}</p>
                    </div>
                @empty
                    <p class="muted">Belum ada booking.</p>
                @endforelse
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h2 class="section-title">Follow-Ups</h2>
                @forelse ($customer->followups as $followup)
                    <div style="padding: .85rem 0; border-bottom: 1px solid rgba(157,127,102,.16);">
                        <strong>{{ $followup->title }}</strong>
                        <p class="muted">{{ ucfirst($followup->priority) }} | {{ $followup->assignedUser?->name ?: '-' }}</p>
                    </div>
                @empty
                    <p class="muted">Belum ada follow-up.</p>
                @endforelse
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h2 class="section-title">Campaigns</h2>
                @forelse ($customer->campaignRecipients as $recipient)
                    <div style="padding: .85rem 0; border-bottom: 1px solid rgba(157,127,102,.16);">
                        <strong>{{ $recipient->campaign?->name ?: '-' }}</strong>
                        <p class="muted">Status: {{ ucfirst($recipient->status) }}</p>
                    </div>
                @empty
                    <p class="muted">Belum ada campaign recipient.</p>
                @endforelse
            </div>
        </div>

        <div class="card" style="grid-column: 1 / -1;">
            <div class="card-body">
                <h2 class="section-title">Feedback</h2>
                @forelse ($customer->feedback as $feedback)
                    <div style="padding: .85rem 0; border-bottom: 1px solid rgba(157,127,102,.16);">
                        <strong>Rating {{ $feedback->rating ?: '-' }}/5</strong>
                        <p class="muted">{{ $feedback->message ?: 'Tanpa catatan feedback.' }}</p>
                    </div>
                @empty
                    <p class="muted">Belum ada feedback customer.</p>
                @endforelse
            </div>
        </div>
    </div>
@endsection
