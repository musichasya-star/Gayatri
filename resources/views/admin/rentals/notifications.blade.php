@extends('layouts.app')
@section('title', 'WhatsApp Rental - Gayatri')
@section('content')
    <x-ui.page-header eyebrow="Rental Perlengkapan" title="WhatsApp Rental" description="Atur pesan pembayaran, portal pelanggan, pengembalian, serta pantau hasil pengiriman." />
    @include('admin.rentals.partials.navigation')
    <p class="muted">Pemeriksaan scheduler terakhir: {{ $lastRun ?: 'Belum berjalan' }}. Pesan diproses melalui antrean dan koneksi WhatsApp yang dipilih di aplikasi.</p>
    <div class="card"><div class="card-body"><form class="rental-form" data-rental-notification-settings method="POST" action="{{ route('admin.rentals.notifications.update') }}">@csrf @method('PUT')
        <div class="rental-grid">
            <label>Notifikasi WhatsApp rental<select class="input" name="enabled"><option value="0" @selected(!old('enabled', $settings['enabled']))>Nonaktif</option><option value="1" @selected(old('enabled', $settings['enabled']))>Aktif</option></select></label>
            <label>Kirim pengingat mulai jam (WIB)<input class="input" type="time" name="send_time" value="{{ old('send_time', $settings['send_time']) }}" required></label>
            <label>Ingatkan berapa hari sebelum pengembalian<input class="input" type="number" min="0" max="7" name="reminder_days" value="{{ old('reminder_days', $settings['reminder_days']) }}" required></label>
            <label>Jeda minimal antar pesan (detik)<input class="input" type="number" min="2" max="60" name="min_interval_seconds" value="{{ old('min_interval_seconds', $settings['min_interval_seconds']) }}" required></label>
            <label>Ingatkan pembayaran mulai berapa hari sebelum tenggat<input class="input" type="number" min="0" max="7" name="payment_reminder_days" value="{{ old('payment_reminder_days', $settings['payment_reminder_days']) }}" required></label>
            <label>Jarak antar pengingat pembayaran (hari)<input class="input" type="number" min="1" max="7" name="payment_reminder_interval_days" value="{{ old('payment_reminder_interval_days', $settings['payment_reminder_interval_days']) }}" required></label>
            <label>Maksimal pengingat untuk masing-masing DP/pelunasan<input class="input" type="number" min="1" max="30" name="payment_max_reminders" value="{{ old('payment_max_reminders', $settings['payment_max_reminders']) }}" required></label>
            <label class="rental-wide">Alamat publik aplikasi untuk tautan portal<input class="input" type="url" name="public_base_url" maxlength="250" value="{{ old('public_base_url', $settings['public_base_url']) }}" placeholder="https://gayatri-kediri.web.id"><span class="muted rental-small">Gunakan alamat HTTPS aplikasi tanpa path tambahan. Kosong mengikuti APP_URL jika sudah berupa alamat publik HTTPS.</span></label>
        </div>
        <p class="muted">Pengingat dan keterlambatan dikirim maksimal sekali per transaksi per hari. Pesan konfirmasi, perpanjangan, pengembalian, dan penyelesaian dana mengikuti tindakan admin. Aktifkan setelah sesi WhatsApp tersambung dan nomor pelanggan sudah benar.</p>
        <p class="muted">Pengingat pembayaran maksimal satu pesan per pesanan per hari, mengikuti jeda dan batas percobaan di atas. Pengingat berhenti setelah kewajiban terpenuhi dan ditunda saat bukti transfer atau pembatalan menunggu peninjauan. Pesan pembayaran, keputusan pengajuan, serta tautan pribadi memerlukan tujuan WhatsApp yang dicocokkan admin pada detail transaksi.</p>
        @php($selectedEvents = session()->hasOldInput('enabled') ? old('events', []) : $settings['events'])
        <fieldset style="border:0;padding:0;"><legend><strong>Jenis pesan yang diaktifkan</strong></legend><div class="rental-grid">@foreach($events as $kind => $label)<label><input type="checkbox" name="events[]" value="{{ $kind }}" @checked(in_array($kind, $selectedEvents))> {{ $label }}</label>@endforeach</div></fieldset>
        <h2>Template pesan</h2><p class="muted">Variabel: {{ collect(\App\Services\CRM\RentalNotificationService::VARIABLES)->map(fn ($key) => '{'.$key.'}')->join(', ') }}</p>
        @foreach($events as $kind => $label)<details><summary>{{ $label }}</summary><label>Template {{ $label }}<textarea class="input" rows="5" name="templates[{{ $kind }}]" maxlength="3000" required>{{ old('templates.'.$kind, $settings['templates'][$kind]) }}</textarea></label></details>@endforeach
        <button class="button button-primary" type="submit">Simpan Pengaturan WhatsApp Rental</button>
    </form></div></div>
    <div class="card" style="margin-top:1rem;"><div class="card-body"><h2>Pratinjau tanpa mengirim pesan</h2>
        <form class="rental-form rental-grid" data-rental-notification-preview data-preview-url="{{ route('admin.rentals.notifications.preview') }}">@csrf
            <label>Contoh transaksi<select class="input" name="order_id" required><option value="">Pilih transaksi</option>@foreach($orders as $order)<option value="{{ $order->id }}">{{ $order->code }} · {{ $order->customer?->name }}</option>@endforeach</select></label>
            <label>Jenis pratinjau<select class="input" name="kind">@foreach($events as $kind => $label)<option value="{{ $kind }}">{{ $label }}</option>@endforeach</select></label>
            <button class="button button-secondary" type="submit">Lihat Pratinjau</button>
            <div class="rental-summary rental-wide" data-notification-preview role="status" aria-live="polite">Pilih transaksi. Pratinjau memakai template yang sedang Anda edit dan tidak mengirim WhatsApp. Token tautan pribadi disamarkan; pratinjau tidak membuat atau mengganti tautan.</div>
        </form>
    </div></div>
    <h2>Riwayat pengiriman</h2>
    <form method="GET" class="rental-actions"><select class="input" name="status" aria-label="Filter status pengiriman"><option value="">Semua status</option>@foreach(\App\Models\RentalNotification::STATUSES as $status => $label)<option value="{{ $status }}" @selected(request('status') === $status)>{{ $label }}</option>@endforeach</select><button class="button button-secondary">Filter</button></form>
    <div class="rental-grid" style="margin-top:1rem;">@forelse($notifications as $notification)<article class="card"><div class="card-body">
        <span class="badge badge-gold">{{ \App\Models\RentalNotification::STATUSES[$notification->status] }}</span>
        <h3>{{ $events[$notification->kind] ?? $notification->kind }}</h3><p>{{ $notification->order?->code }} · {{ $notification->order?->customer?->name }}<br>{{ $notification->created_at->format('d M Y H:i') }} · Percobaan {{ $notification->attempts }}</p>
        @if($notification->failure_reason)<p class="rental-alert rental-error">{{ $notification->failure_reason }}</p>@endif
        @if($notification->content)<details><summary>Lihat isi pesan</summary><p style="white-space:pre-line;overflow-wrap:anywhere;">{{ $notification->content }}</p></details>@endif
        @if(in_array($notification->status, ['failed', 'unknown']) && !$notification->expires_at->isPast())<form class="rental-form" method="POST" action="{{ route('admin.rentals.notifications.retry', $notification) }}">@csrf
            @if($notification->status === 'unknown')<label><input type="checkbox" name="checked_not_sent" value="1" required> Saya sudah memeriksa WhatsApp dan pesan belum terkirim.</label>@endif
            <button class="button button-secondary">Coba Kirim Ulang</button>
        </form>@endif
        <p><a href="{{ route('admin.rentals.orders.show', $notification->order) }}">Buka transaksi</a></p>
    </div></article>@empty<p class="muted">Belum ada riwayat. Pesan baru dibuat setelah notifikasi diaktifkan dan ada tindakan/jadwal yang sesuai.</p>@endforelse</div>{{ $notifications->links() }}
    <script src="{{ asset('js/rental-notifications.js') }}" defer></script>
@endsection
