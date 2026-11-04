@extends('layouts.app')

@section('title', 'WhatsApp Gateway - Gayatri CRM')

@section('content')
@include('partials.admin-workbench-assets')
    <x-ui.page-header
        eyebrow="WhatsApp Gateway"
        :title="'Session '.$gatewayLabel"
        description="Pantau koneksi WhatsApp utama, jalankan session, scan QR, dan cek status webhook tanpa keluar dari dashboard."
    ><x-slot name="actions"><a class="button button-secondary" href="{{ route('admin.whatsapp.monitor') }}">Monitoring WhatsApp</a></x-slot></x-ui.page-header>

    @if (session('status'))
        <div class="card" style="margin-bottom: 1rem; border-color: rgba(143,174,139,.55);">
            <div class="card-body" style="color: #3f6d3d;">{{ session('status') }}</div>
        </div>
    @endif

    @if ($error)
        <div class="card" style="margin-bottom: 1rem; border-color: rgba(201,122,106,.55);">
            <div class="card-body" style="color: #7a3228;"><strong>Status koneksi belum tersedia.</strong> Periksa gateway dan sesi sebelum mengirim pesan.<details class="wb-technical"><summary>Detail pemeriksaan</summary><p>{{ \App\Services\WhatsApp\DeliveryFailure::safeText($error) }}</p></details></div>
        </div>
    @endif

    <div class="grid grid-2" style="align-items: start;">
        <div class="card">
            <div class="card-body">
                <h2 class="section-title">Status Session</h2>
                <div class="soft-list">
                    <div class="soft-list-item"><span>Nama Session</span><strong>{{ $sessionName }}</strong></div>
                    <div class="soft-list-item"><span>Status {{ $gatewayLabel }}</span><span class="badge badge-brown">{{ data_get($remoteSession, 'status', strtoupper($localSession?->status ?? 'unknown')) }}</span></div>
                    <div class="soft-list-item"><span>Nomor Tersambung</span><strong>{{ $remoteSession !== null ? (data_get($remoteSession, 'me.id') ?: '-') : ($localSession?->phone_number ?: '-') }}</strong></div>
                    <details class="wb-technical"><summary>Alamat callback & informasi teknis</summary><div class="soft-list-item"><span>Webhook Message</span><strong style="min-width:0;overflow-wrap:anywhere;text-align:right;">{{ route($webhookRoutePrefix.'.messages') }}</strong></div>
                    <div class="soft-list-item"><span>Webhook Status</span><strong style="min-width:0;overflow-wrap:anywhere;text-align:right;">{{ route($webhookRoutePrefix.'.status') }}</strong></div></details>
                </div>

                <div style="display: flex; gap: .65rem; flex-wrap: wrap; margin-top: 1rem;">
                    <form method="POST" action="{{ route('admin.whatsapp.session.start') }}">
                        @csrf
                        <input type="hidden" name="session_name" value="{{ $sessionName }}">
                        <button class="button button-primary" type="submit"><i data-lucide="play"></i> Mulai sesi</button>
                    </form>
                    <form method="POST" action="{{ route('admin.whatsapp.session.restart') }}">
                        @csrf
                        <input type="hidden" name="session_name" value="{{ $sessionName }}">
                        <button class="button button-secondary" type="submit"><i data-lucide="refresh-cw"></i> Sambungkan ulang</button>
                    </form>
                    <form method="POST" action="{{ route('admin.whatsapp.session.stop') }}">
                        @csrf
                        <input type="hidden" name="session_name" value="{{ $sessionName }}">
                        <button class="button button-ghost" type="submit"><i data-lucide="square"></i> Hentikan sesi</button>
                    </form>
                    <form method="POST" action="{{ route('admin.whatsapp.session.logout') }}">
                        @csrf
                        <input type="hidden" name="session_name" value="{{ $sessionName }}">
                        <button class="button button-ghost" type="submit"><i data-lucide="log-out"></i> Keluar dari WhatsApp</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h2 class="section-title">QR Code</h2>
                @if (($qr['data'] ?? null) && ($qr['mimetype'] ?? null))
                    <img src="data:{{ $qr['mimetype'] }};base64,{{ $qr['data'] }}" alt="QR {{ $gatewayLabel }}" style="width:280px;max-width:100%;border-radius:1rem;border:1px solid rgba(157,127,102,.16);">
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

    <details class="ops-section"><summary>Riwayat status dari webhook</summary><div class="ops-section-body"><h2 class="section-title">Catatan lokal</h2>
            <div class="soft-list">
                <div class="soft-list-item"><span>Status Lokal</span><strong>{{ $localSession?->status ?: '-' }}</strong></div>
                <div class="soft-list-item"><span>Terhubung sejak</span><strong>{{ $localSession?->connected_at?->format('d M Y H:i') ?: '-' }}</strong></div>
                <div class="soft-list-item"><span>Aktivitas terakhir</span><strong>{{ $localSession?->last_seen_at?->format('d M Y H:i') ?: '-' }}</strong></div>
            </div>
        </div>
</details>
    @if (in_array(data_get($remoteSession, 'status'), ['STARTING', 'SCAN_QR_CODE'], true))
        <script>
            // Renew expiring pairing codes and show WORKING after the phone connects.
            window.setTimeout(() => window.location.reload(), 15000);
        </script>
    @endif
@endsection
