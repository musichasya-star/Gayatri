@extends('layouts.app')

@section('title', 'WhatsApp Gateway - Gayatri CRM')

@section('content')
    <x-ui.page-header
        eyebrow="WhatsApp Gateway"
        title="Session WAHA"
        description="Pantau koneksi WhatsApp utama, jalankan session, scan QR, dan cek status webhook tanpa keluar dari dashboard."
    />

    @if (session('status'))
        <div class="card" style="margin-bottom: 1rem; border-color: rgba(143,174,139,.55);">
            <div class="card-body" style="color: #3f6d3d;">{{ session('status') }}</div>
        </div>
    @endif

    @if ($error)
        <div class="card" style="margin-bottom: 1rem; border-color: rgba(201,122,106,.55);">
            <div class="card-body" style="color: #7a3228;">{{ $error }}</div>
        </div>
    @endif

    <div class="grid grid-2" style="align-items: start;">
        <div class="card">
            <div class="card-body">
                <h2 class="section-title">Status Session</h2>
                <div class="soft-list">
                    <div class="soft-list-item"><span>Nama Session</span><strong>{{ $sessionName }}</strong></div>
                    <div class="soft-list-item"><span>Status WAHA</span><span class="badge badge-brown">{{ data_get($remoteSession, 'status', strtoupper($localSession?->status ?? 'unknown')) }}</span></div>
                    <div class="soft-list-item"><span>Nomor Tersambung</span><strong>{{ data_get($remoteSession, 'me.id', $localSession?->phone_number ?: '-') }}</strong></div>
                    <div class="soft-list-item"><span>Webhook Message</span><strong>{{ route('webhooks.waha.messages') }}</strong></div>
                    <div class="soft-list-item"><span>Webhook Status</span><strong>{{ route('webhooks.waha.status') }}</strong></div>
                </div>

                <div style="display: flex; gap: .65rem; flex-wrap: wrap; margin-top: 1rem;">
                    <form method="POST" action="{{ route('admin.whatsapp.session.start') }}">
                        @csrf
                        <input type="hidden" name="session_name" value="{{ $sessionName }}">
                        <button class="button button-primary" type="submit"><i data-lucide="play"></i> Start</button>
                    </form>
                    <form method="POST" action="{{ route('admin.whatsapp.session.restart') }}">
                        @csrf
                        <input type="hidden" name="session_name" value="{{ $sessionName }}">
                        <button class="button button-secondary" type="submit"><i data-lucide="refresh-cw"></i> Restart</button>
                    </form>
                    <form method="POST" action="{{ route('admin.whatsapp.session.stop') }}">
                        @csrf
                        <input type="hidden" name="session_name" value="{{ $sessionName }}">
                        <button class="button button-ghost" type="submit"><i data-lucide="square"></i> Stop</button>
                    </form>
                    <form method="POST" action="{{ route('admin.whatsapp.session.logout') }}">
                        @csrf
                        <input type="hidden" name="session_name" value="{{ $sessionName }}">
                        <button class="button button-ghost" type="submit"><i data-lucide="log-out"></i> Logout</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h2 class="section-title">QR Code</h2>
                @if (($qr['data'] ?? null) && ($qr['mimetype'] ?? null))
                    <img src="data:{{ $qr['mimetype'] }};base64,{{ $qr['data'] }}" alt="QR WAHA" style="max-width: 280px; border-radius: 1rem; border: 1px solid rgba(157,127,102,.16);">
                    <p class="muted" style="margin-top: .85rem;">Scan QR ini dari WhatsApp di perangkat utama jika status masih `SCAN_QR_CODE`.</p>
                @else
                    <x-ui.empty-state
                        icon="qr-code"
                        title="QR Belum Tersedia"
                        description="QR akan muncul otomatis saat session membutuhkan pairing atau scan ulang."
                    />
                @endif
            </div>
        </div>
    </div>

    <div class="card" style="margin-top: 1rem;">
        <div class="card-body">
            <h2 class="section-title">Local Tracking</h2>
            <div class="soft-list">
                <div class="soft-list-item"><span>Status Lokal</span><strong>{{ $localSession?->status ?: '-' }}</strong></div>
                <div class="soft-list-item"><span>Connected At</span><strong>{{ $localSession?->connected_at?->format('d M Y H:i') ?: '-' }}</strong></div>
                <div class="soft-list-item"><span>Last Seen</span><strong>{{ $localSession?->last_seen_at?->format('d M Y H:i') ?: '-' }}</strong></div>
            </div>
        </div>
    </div>
@endsection
