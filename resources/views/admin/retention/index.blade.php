@extends('layouts.app')

@section('title', 'Customer Retention - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="Retention" title="Customer Lama" description="Segment customer yang belum booking dalam 30/60/90 hari untuk repeat order.">
        <x-slot name="actions"><a class="button button-secondary" href="{{ route('admin.followups.index') }}"><i data-lucide="phone-forwarded"></i> Follow-Up</a></x-slot>
    </x-ui.page-header>

    @if(session('status'))<div class="card" style="margin-bottom:1rem;border-color:rgba(143,174,139,.55);"><div class="card-body" style="color:#3f6d3d;">{{ session('status') }}</div></div>@endif

    <section class="grid grid-3" style="margin-bottom:1rem;">
        @foreach([30,60,90] as $segment)
            <a class="card" href="{{ route('admin.retention', ['days' => $segment]) }}"><div class="card-body"><h2 class="section-title">Inactive {{ $segment }} Hari</h2><p class="muted">Segment customer belum booking minimal {{ $segment }} hari.</p>@if($days===$segment)<span class="badge badge-gold">Aktif</span>@endif</div></a>
        @endforeach
    </section>

    <section class="grid" style="margin-bottom:1rem;">
        <div class="card">
            <div class="card-body">
                <form method="GET" action="{{ route('admin.retention') }}" style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;">
                    <input type="hidden" name="days" value="{{ $days }}">
                    <input class="input" name="q" value="{{ $search }}" placeholder="Cari nama, nomor HP, WA, tag">
                    <input class="input" name="tag" list="retention-tags" value="{{ $tag }}" placeholder="Tag retention">
                    <datalist id="retention-tags">
                        @foreach($tagOptions as $tagOption)
                            <option value="{{ $tagOption }}"></option>
                        @endforeach
                    </datalist>
                    <select class="input" name="followup_status">
                        <option value="all" @selected($followupStatus === 'all')>Semua Follow-Up</option>
                        <option value="active" @selected($followupStatus === 'active')>Follow-Up aktif</option>
                        <option value="open" @selected($followupStatus === 'open')>Follow-Up open</option>
                        <option value="sent" @selected($followupStatus === 'sent')>Follow-Up sent</option>
                        <option value="completed" @selected($followupStatus === 'completed')>Follow-Up completed</option>
                        <option value="none" @selected($followupStatus === 'none')>Belum ada Follow-Up</option>
                    </select>
                    <button class="button button-primary" type="submit"><i data-lucide="search"></i> Cari</button>
                    <a class="button button-secondary" href="{{ route('admin.retention', ['days' => $days]) }}">Reset</a>
                </form>
            </div>
        </div>
        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ route('admin.retention.generate-followups') }}" style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;">@csrf<input type="hidden" name="days" value="{{ $days }}"><span class="muted">{{ $customers->total() }} customer pada segment inactive {{ $days }} hari.</span><button class="button button-primary" type="submit"><i data-lucide="sparkles"></i> Generate Follow-Up</button></form>
            </div>
        </div>
    </section>

    <div class="card">
        <div class="card-body">
            <table class="table-card">
                <thead>
                    <tr><th>Customer</th><th>WhatsApp</th><th>Last Booking</th><th>Tags</th><th>Follow-Up Aktif</th><th>Aksi</th></tr>
                </thead>
                <tbody>
                    @forelse($customers as $customer)
                        <tr>
                            <td data-label="Customer">{{ $customer->name }}</td>
                            <td data-label="WhatsApp">{{ $customer->whatsapp_number ?: '-' }}</td>
                            <td data-label="Last Booking">{{ $customer->last_booking_at?->format('d M Y') ?: 'Belum pernah' }}</td>
                            <td data-label="Tags">{{ implode(', ', $customer->tags ?? []) ?: '-' }}</td>
                            <td data-label="Follow-Up Aktif">{{ $customer->active_followups_count }}</td>
                            <td data-label="Aksi">
                                @if((int) $customer->active_followups_count > 0)
                                    <span class="badge badge-brown">Follow-Up aktif</span>
                                @else
                                    <a class="button button-secondary" href="{{ route('admin.followups.create', $quickFollowups[$customer->id] ?? []) }}">Buat Follow-Up</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" style="text-align:center;padding:2rem;">Tidak ada customer pada segment ini.</td></tr>
                    @endforelse
                </tbody>
            </table>

            <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-top:1rem;">
                <p class="muted" style="margin:0;">Menampilkan {{ $customers->firstItem() ?? 0 }} sampai {{ $customers->lastItem() ?? 0 }} dari {{ $customers->total() }} customer</p>
                {{ $customers->links() }}
            </div>
        </div>
    </div>
@endsection
