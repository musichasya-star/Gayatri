@extends('layouts.app')
@section('title', 'Monitoring WhatsApp - Gayatri')
@section('content')
@include('partials.admin-workbench-assets')
<link rel="stylesheet" href="{{ asset('css/whatsapp-monitor.css') }}">
<div class="whatsapp-monitor">
    <x-ui.page-header eyebrow="Operasional WhatsApp" title="Monitoring WhatsApp" description="Pantau koneksi, antrean pekerjaan, dan hasil pengiriman dari seluruh modul.">
        <x-slot name="actions">
            <div class="monitor-actions">
                <a class="button button-secondary" href="{{ route('admin.whatsapp.session') }}">Kelola koneksi / QR</a>
                <form method="POST" action="{{ route('admin.whatsapp.monitor.refresh') }}">@csrf<button class="button button-primary">Periksa koneksi sekarang</button></form>
            </div>
        </x-slot>
    </x-ui.page-header>
    @if(session('status'))<p class="monitor-notice" role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="monitor-notice monitor-warning" role="alert">{{ $errors->first() }}</p>@endif
    @if(request('message_id'))<p class="monitor-notice">Menampilkan pesan keluar #{{ request('message_id') }}. <a href="{{ route('admin.whatsapp.monitor',['source'=>'message','include_demo'=>request()->boolean('include_demo')?1:0]) }}">Lihat semua pesan</a></p>@endif

    <div class="wb-scope"><strong>Tiga status yang berbeda:</strong> pengaturan AI menentukan apakah balasan diizinkan; koneksi gateway menentukan kesiapan WhatsApp; worker memproses antrean. Keberhasilan menjawab AI belum berarti pesan sudah diterima pelanggan.</div>
    <nav class="wb-tabs" aria-label="Antrean pengiriman">@foreach(['Semua'=>null,'Tertunda'=>'queued','Gagal'=>'failed','Perlu diperiksa'=>'unknown','Diterima gateway'=>'sent'] as $label=>$state)<a class="button button-secondary" href="{{ route('admin.whatsapp.monitor',array_merge(request()->only(['start_date','end_date','source','include_demo']),$state ? ['status'=>$state] : [])) }}">{{ $label }}</a>@endforeach</nav>
    <section class="monitor-grid" aria-label="Kesehatan koneksi dan antrean">
        <div class="card"><div class="card-body">
            <p class="eyebrow">Koneksi {{ $health['provider'] }}</p>
            <h2 class="section-title">{{ $health['reachable'] ? 'Gateway dapat dihubungi' : 'Gateway belum dapat dihubungi' }}</h2>
            <p class="muted">Terakhir diperiksa {{ $health['checked_at'] }} WIB. Gunakan tombol periksa untuk memperbarui koneksi.</p>
            @if($health['error'])<p class="monitor-notice monitor-warning">Koneksi belum dapat dipastikan. Periksa layanan gateway dan konfigurasi sesi.</p><details class="wb-technical"><summary>Detail pemeriksaan koneksi</summary><p>{{ \App\Services\WhatsApp\DeliveryFailure::safeText($health['error']) }}</p></details>@endif
            @forelse($health['sessions'] as $session)
                <div class="monitor-session"><strong>{{ $session['name'] }}</strong><span class="monitor-badge {{ in_array($session['status'],['WORKING','CONNECTED']) ? 'monitor-sent' : 'monitor-unknown' }}">{{ match($session['status']) { 'WORKING','CONNECTED' => 'Terhubung', 'SCAN_QR_CODE' => 'Perlu pindai QR', 'STARTING' => 'Menghubungkan', 'STOPPED' => 'Dihentikan', 'FAILED' => 'Koneksi gagal', default => $session['status'] } }}</span></div>
            @empty<p>{{ $health['reachable'] ? 'Belum ada sesi pada gateway.' : 'Status sesi langsung belum tersedia.' }}</p>@endforelse
            @if($health['reachable'] && !collect($health['sessions'])->contains('name',$defaultSession))<p class="monitor-notice monitor-warning">Sesi utama {{ $defaultSession }} belum terdaftar pada gateway.</p>@endif
            <details><summary>Catatan sesi terakhir dari webhook</summary>
                @forelse($localSessions as $session)<p>{{ $session->session_name }} · {{ $session->status }} · {{ $session->last_seen_at?->format('d M Y H:i') ?: 'Belum ada waktu webhook' }} WIB</p>@empty<p>Belum ada catatan sesi lokal.</p>@endforelse
                <p class="muted">Catatan webhook adalah riwayat dan dapat berbeda dari pemeriksaan langsung.</p>
            </details>
            @if($health['webhooks_pending'] !== null)<p>Callback Baileys tertunda: <strong>{{ number_format($health['webhooks_pending']) }}</strong> · Percobaan tertinggi {{ $health['webhooks_attempts'] }}.</p><small class="muted">Callback membawa pesan masuk/status ke Gayatri; jumlah ini terpisah dari antrean pesan keluar.</small>@endif
        </div></div>
        <div class="card"><div class="card-body">
            <p class="eyebrow">Antrean aplikasi · semua tanggal</p>
            <h2 class="section-title">{{ $queue['available'] ? number_format($queue['pending']).' tugas menunggu worker / jadwal' : 'Status antrean' }}</h2>
            @if($queue['available'])<p>{{ number_format($queue['processing']) }} tugas sedang diproses · {{ $queue['failed'] === null ? 'Riwayat job gagal tidak tersedia' : number_format($queue['failed']).' tugas berakhir gagal' }}.</p>@endif
            @if($queue['error'])<p class="monitor-notice monitor-warning">{{ $queue['error'] }}</p>@endif
            <p class="muted">Tugas mencakup pesan masuk, pembuatan balasan AI, dan pengiriman. Satu tugas tidak selalu sama dengan satu pesan.</p>
            @if($queue['driver'] !== 'sync')
                <h3>Aktivitas worker</h3>
                @foreach($queue['workers'] as $worker)<div class="monitor-session"><strong>{{ $worker['queue'] }}</strong><span class="monitor-badge {{ $worker['seen_at'] ? 'monitor-sent' : 'monitor-unknown' }}">{{ $worker['seen_at'] ? 'Terpantau '.$worker['seen_at'].' WIB' : 'Belum ada heartbeat terbaru' }}</span></div>@endforeach
                <p class="muted">Heartbeat hilang setelah tiga menit tanpa pembaruan. Catatan ini mulai tersedia setelah worker memuat pembaruan aplikasi.</p>
            @endif
        </div></div>
    </section>

    <section class="card"><div class="card-body">
        <h2 class="section-title">Riwayat pengiriman</h2>
        <form method="GET" class="monitor-filter">
            <label>Dari tanggal<input class="input" type="date" name="start_date" value="{{ $startDate }}"></label>
            <label>Sampai tanggal<input class="input" type="date" name="end_date" value="{{ $endDate }}"></label>
            <label>Modul<select class="input" name="source"><option value="">Semua modul</option>@foreach($sources as $key=>$label)<option value="{{ $key }}" @selected(request('source')===$key)>{{ $label }}</option>@endforeach</select></label>
            <label>Status<select class="input" name="status"><option value="">Semua status</option>@foreach($statuses as $key=>$label)<option value="{{ $key }}" @selected(request('status')===$key)>{{ $label }}</option>@endforeach</select></label>
            <details class="ops-section ops-wide" @if(request()->hasAny(['search','include_demo'])) open @endif><summary>Filter pelanggan & data demo</summary><div class="ops-section-body"><label>Nama / WhatsApp<input class="input" type="search" name="search" maxlength="100" value="{{ request('search') }}" placeholder="Cari pelanggan"></label>
            <label class="monitor-check"><input type="checkbox" name="include_demo" value="1" @checked(request()->boolean('include_demo'))> Sertakan data demo</label>
            </div></details><button class="button button-primary">Terapkan filter</button><a class="button button-secondary" href="{{ route('admin.whatsapp.monitor') }}">Reset filter</a>
        </form>
        <p class="muted">Ringkasan mengikuti filter dan waktu pencatatan/penerimaan gateway. Pesan yang juga tercatat di inbox dihitung sekali. “Diterima gateway” belum berarti sudah dibaca pelanggan. Kegagalan lama tanpa kepastian hasil ditandai perlu diperiksa. Reminder lama tanpa tautan inbox dapat tampil sebagai pesan sistem.</p>
        <div class="monitor-stats">
            @foreach(['queued','sending','sent','failed','unknown'] as $state)<div><span class="monitor-badge monitor-{{ $state }}">{{ $statuses[$state] }}</span><strong>{{ number_format($counts[$state] ?? 0) }}</strong></div>@endforeach
        </div>
        @if(($counts['skipped'] ?? 0) || ($counts['simulated'] ?? 0))<p class="muted">Dilewati: {{ number_format($counts['skipped'] ?? 0) }} · Simulasi: {{ number_format($counts['simulated'] ?? 0) }}.</p>@endif
        <div class="monitor-table"><table class="table-card"><thead><tr><th>Waktu / sumber</th><th>Pelanggan / pesan</th><th>Status</th><th>Alasan / tindak lanjut</th></tr></thead><tbody>
            @forelse($rows as $row)
                <tr>
                    <td data-label="Waktu / sumber">{{ \Carbon\Carbon::parse($row->event_at)->format('d M Y H:i') }} WIB<br><strong>{{ $sources[$row->source] }}</strong><br><small>#{{ $row->record_id }} · {{ $row->kind }}</small></td>
                    <td data-label="Pelanggan / pesan"><strong>{{ $row->customer_name ?: 'Riwayat pelanggan' }}</strong>@if($row->preview !== '—')<details><summary>Ringkasan pesan</summary><p class="monitor-wrap">{{ $row->preview }}</p></details>@endif</td>
                    <td data-label="Status"><span class="monitor-badge monitor-{{ $row->delivery_state }}">{{ $statuses[$row->delivery_state] ?? 'Perlu diperiksa' }}</span><p class="muted">{{ $row->attempts ? $row->attempts.' percobaan' : ($row->delivery_state === 'queued' ? 'Belum mulai dikirim' : 'Percobaan historis tidak dicatat') }}</p></td>
                    <td data-label="Alasan / tindak lanjut">
                        @if($row->failure_reason !== '—')<p class="monitor-wrap">{{ $row->failure_reason }}</p>@endif
                        @if(!$row->retry_problem)
                            <details class="monitor-retry"><summary>Tinjau kirim ulang</summary>
                                <p>Periksa chat WhatsApp dan pastikan pesan belum diterima. Timeout dapat terjadi setelah gateway menerima pesan. Permintaan ini mengantrekan satu percobaan, tidak mengulang seluruh job.</p>
                                <form method="POST" action="{{ route('admin.whatsapp.monitor.retry', [$row->source,$row->record_id]) }}">
                                    @csrf<input type="hidden" name="fingerprint" value="{{ $row->fingerprint }}">
                                    <label>Alasan kirim ulang<textarea class="input" name="reason" minlength="5" maxlength="500" required placeholder="Contoh: tujuan dan chat sudah diperiksa, pesan belum diterima."></textarea></label>
                                    <label class="monitor-check"><input type="checkbox" name="confirmed_not_sent" value="1" required> Saya sudah memeriksa bahwa pesan belum terkirim, tujuan benar, dan isi masih sesuai.</label>
                                    <button class="button button-secondary">Antrekan satu percobaan ulang</button>
                                </form>
                            </details>
                        @elseif(in_array($row->delivery_state,['failed','unknown','sending']))<p class="muted">{{ $row->retry_problem }}</p>@endif
                        @if($row->source_url)<a class="monitor-source" href="{{ $row->source_url }}">Buka modul asal</a>@endif
                        @if($row->ai_decision_url)<a class="monitor-source" href="{{ $row->ai_decision_url }}">Telusuri keputusan AI</a>@endif
                    </td>
                </tr>
            @empty<tr><td colspan="4">Tidak ada pengiriman yang sesuai filter.</td></tr>@endforelse
        </tbody></table></div>
        <div class="monitor-pagination">{{ $rows->links() }}</div>
    </div></section>

    <section class="monitor-grid">
        <div class="card"><div class="card-body"><h2 class="section-title">Tugas WhatsApp yang tertunda</h2><p class="muted">Menampilkan 30 tugas tertua, semua tanggal. Payload pesan dan kredensial tidak ditampilkan.</p>
            @forelse($queue['jobs'] as $job)<div class="monitor-job"><strong>{{ $job->label }}</strong><span>{{ $job->state }} · antrean {{ $job->queue }}</span><small>Dibuat {{ \Carbon\Carbon::createFromTimestamp($job->created_at)->timezone(config('app.timezone'))->format('d M H:i') }} WIB · Siap {{ \Carbon\Carbon::createFromTimestamp($job->available_at)->timezone(config('app.timezone'))->format('d M H:i') }} WIB</small></div>@empty<p>{{ $queue['available'] ? 'Tidak ada tugas WhatsApp dalam antrean.' : 'Rincian tugas belum tersedia.' }}</p>@endforelse
            @if($queue['failures']->isNotEmpty())<h3>Tugas yang berakhir gagal</h3>@foreach($queue['failures'] as $job)<p>{{ $job->label }} · {{ $job->failed_at }} WIB · {{ $job->queue }}</p>@endforeach<p class="muted">Tinjau catatan pengiriman dan modul asal sebelum mencoba ulang. Job mentah tidak diulang dari halaman ini untuk menghindari efek samping ganda.</p>@endif
        </div></div>
        <div class="card"><div class="card-body"><h2 class="section-title">Jejak permintaan kirim ulang</h2>
            @forelse($retries as $retry)<div class="monitor-job"><strong>{{ $sources[$retry->source] ?? $retry->source }} #{{ $retry->source_id }}</strong><span>{{ $retry->actor ?: 'Akun tidak tersedia' }} · {{ $retry->created_at }} WIB</span><p class="monitor-wrap">{{ $retry->reason }}</p></div>@empty<p>Belum ada permintaan kirim ulang dari pusat monitoring.</p>@endforelse
        </div></div>
    </section>
</div>
@endsection
