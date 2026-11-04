@extends('layouts.app')

@section('title', 'Ucapan Ulang Tahun - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="Customer Care" title="Ucapan Ulang Tahun" description="Kirim perhatian kecil di hari spesial pelanggan melalui WhatsApp, dengan template dan jadwal yang bisa diatur.">
        <x-slot name="actions"><a class="button button-secondary" href="{{ route('admin.customers.index') }}"><i data-lucide="users"></i> Data Pelanggan</a><a class="button button-secondary" href="{{ route('admin.whatsapp.session') }}"><i data-lucide="qr-code"></i> Koneksi WhatsApp</a></x-slot>
    </x-ui.page-header>
    @if(session('status'))<div class="card" style="margin-bottom:1rem;border-color:#c8d7b8;"><div class="card-body" role="status" style="color:#3f6d3d;">{{ session('status') }}</div></div>@endif
    @if($errors->any())<div class="card" style="margin-bottom:1rem;border-color:#c97a6a;"><div class="card-body" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>@endif

    <div class="grid grid-4" style="margin-bottom:1rem;">
        <div class="card"><div class="card-body"><p class="muted">Ulang tahun hari ini</p><h2>{{ $todayRecipients->count() }}</h2><small class="muted">{{ $today->translatedFormat('d F Y') }} · WIB</small></div></div>
        <div class="card"><div class="card-body"><p class="muted">Terkirim hari ini</p><h2>{{ $counts->get('sent', 0) }}</h2><small class="muted">Pesan diterima gateway</small></div></div>
        <div class="card"><div class="card-body"><p class="muted">Dalam antrean</p><h2>{{ $counts->get('queued', 0) + $counts->get('sending', 0) }}</h2><small class="muted">Dikirim bertahap</small></div></div>
        <div class="card"><div class="card-body"><p class="muted">Butuh perhatian</p><h2>{{ $counts->get('failed', 0) + $counts->get('unknown', 0) }}</h2><small class="muted">Periksa riwayat di bawah</small></div></div>
    </div>

    <div class="card" style="margin-bottom:1rem;"><div class="card-body">
        <span class="badge {{ $settings['enabled'] ? 'badge-green' : 'badge-brown' }}">{{ $settings['enabled'] ? 'Ucapan otomatis aktif' : 'Ucapan otomatis nonaktif' }}</span>
        <p class="muted" style="margin-top:.65rem;">Pemeriksaan scheduler terakhir: <strong>{{ $lastCheck ? \Carbon\Carbon::parse($lastCheck)->timezone('Asia/Jakarta')->format('d M Y H:i:s').' WIB' : 'Belum ada pemeriksaan' }}</strong>. Gateway WhatsApp dan queue worker harus berjalan.</p>
    </div></div>

    <form method="POST" action="{{ route('admin.birthdays.update') }}" id="birthday-settings">
        @csrf
        <div class="grid grid-2" style="align-items:start;">
            <section class="card"><div class="card-body">
                <h2 class="section-title">Jadwal & Penerima</h2>
                <input type="hidden" name="enabled" value="0"><label class="birthday-check"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $settings['enabled']))> Aktifkan ucapan ulang tahun otomatis</label>
                <input type="hidden" name="include_customer" value="0"><label class="birthday-check"><input type="checkbox" name="include_customer" value="1" @checked(old('include_customer', $settings['include_customer']))> Ulang tahun ibu / pelanggan</label>
                <input type="hidden" name="include_baby" value="0"><label class="birthday-check"><input type="checkbox" name="include_baby" value="1" @checked(old('include_baby', $settings['include_baby']))> Ulang tahun bayi / anak (ke nomor WhatsApp pelanggan)</label>
                <div class="grid grid-2" style="margin-top:1rem;">
                    <label><span class="birthday-label">Jam Kirim (WIB)</span><input class="input" type="time" name="send_time" value="{{ old('send_time', $settings['send_time']) }}" required></label>
                    <label><span class="birthday-label">Tanggal Lahir 29 Februari</span><select class="input" name="leap_day"><option value="feb28" @selected(old('leap_day', $settings['leap_day']) === 'feb28')>28 Februari di tahun nonkabisat</option><option value="mar1" @selected(old('leap_day', $settings['leap_day']) === 'mar1')>1 Maret di tahun nonkabisat</option></select></label>
                </div>
                <p class="muted" style="margin-top:1rem;">Satu ucapan per jenis ulang tahun setiap tahun. Tanggal lahir diambil dari Data Pelanggan. Hanya pelanggan aktif/lead dengan WhatsApp valid yang dikirim; pelanggan nonaktif/blacklist dan tanggal lahir yang belum genap setahun dilewati.</p>
                <p class="muted" style="margin-top:.65rem;">Jika scheduler terlambat, ucapan tetap diproses setelah jam kirim pada hari yang sama. Ucapan hari sebelumnya tidak dikejar. Pengaturan ini terpisah dari aktif/nonaktif balasan AI.</p>
            </div></section>
            <section class="card"><div class="card-body">
                <h2 class="section-title">Template WhatsApp</h2>
                <label><span class="birthday-label">Ucapan untuk Pelanggan</span><textarea class="input" name="customer_template" rows="5" maxlength="3000" required>{{ old('customer_template', $settings['customer_template']) }}</textarea></label>
                <label style="display:block;margin-top:1rem;"><span class="birthday-label">Ucapan untuk Bayi / Anak</span><textarea class="input" name="baby_template" rows="5" maxlength="3000" required>{{ old('baby_template', $settings['baby_template']) }}</textarea></label>
                <p class="muted" style="margin-top:.75rem;">Variabel: <code>{nama_customer}</code>, <code>{nama_bayi}</code>, <code>{usia}</code>, <code>{nama_bisnis}</code>, <code>{nama_asisten}</code>, <code>{sapaan}</code>. Identitas bisnis dan sapaan mengikuti pengaturan Balasan Otomatis.</p>
            </div></section>
        </div>
        <section class="card" style="margin-top:1rem;"><div class="card-body">
            <h2 class="section-title">Pratinjau Ucapan</h2>
            <p class="muted" style="margin-bottom:1rem;">Memakai isian template saat ini dan usia di tahun ini. Tidak menyimpan pengaturan, mengantrekan pesan, atau mengirim WhatsApp.</p>
            <div class="grid grid-2">
                <label><span class="birthday-label">Pelanggan Contoh</span><select class="input" name="preview_customer_id"><option value="">Pilih pelanggan</option>@foreach($previewCustomers as $customer)<option value="{{ $customer->id }}" @selected(($preview['customer_id'] ?? null) == $customer->id)>{{ $customer->name }}</option>@endforeach</select></label>
                <label><span class="birthday-label">Jenis Ucapan</span><select class="input" name="preview_type"><option value="customer" @selected(($preview['type'] ?? 'customer') === 'customer')>Pelanggan / ibu</option><option value="baby" @selected(($preview['type'] ?? '') === 'baby')>Bayi / anak</option></select></label>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:.75rem;flex-wrap:wrap;margin-top:1rem;"><button class="button button-secondary" type="submit" formaction="{{ route('admin.birthdays.preview') }}"><i data-lucide="eye"></i> Lihat Pratinjau</button><button class="button button-primary" type="submit"><i data-lucide="save"></i> Simpan Pengaturan</button></div>
            @if($preview)<div class="birthday-preview" role="status"><strong>Pratinjau — belum dikirim atau disimpan</strong><div style="white-space:pre-wrap;overflow-wrap:anywhere;margin-top:.75rem;">{{ $preview['message'] }}</div></div>@endif
        </div></section>
    </form>

    <section class="card" style="margin-top:1rem;"><div class="card-body">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:1rem;"><h2 class="section-title" style="margin-bottom:0;">Pelanggan Berulang Tahun Hari Ini</h2><form method="POST" action="{{ route('admin.birthdays.process') }}">@csrf<button class="button button-secondary" type="submit" @disabled(!$canProcess)><i data-lucide="send"></i> Proses Jadwal Hari Ini</button></form></div>
        <p class="muted" style="margin-bottom:1rem;">Tombol proses mengikuti pengaturan yang sudah disimpan dan jam kirim, serta tidak menggandakan ucapan yang telah tercatat.</p>
        <table class="table-card"><thead><tr><th>Pelanggan</th><th>Yang Berulang Tahun</th><th>Usia</th><th>WhatsApp / Status</th><th>Data</th></tr></thead><tbody>
            @forelse($todayRecipients as $occasion)
                @php($record = $todayLogs->get($occasion['customer']->id.':'.$occasion['type']))
                <tr><td data-label="Pelanggan">{{ $occasion['customer']->name }}</td><td data-label="Yang Berulang Tahun">{{ $occasion['name'] }}<br><small class="muted">{{ $occasion['type'] === 'baby' ? 'Bayi / anak' : 'Pelanggan / ibu' }}</small></td><td data-label="Usia">{{ $occasion['age'] }} tahun</td><td data-label="WhatsApp / Status">@if($record)<span class="badge {{ $record->status === 'sent' ? 'badge-green' : 'badge-brown' }}">{{ $statuses[$record->status] ?? $record->status }}</span>@elseif($occasion['chat_id'])<span class="badge badge-green">Siap diproses</span>@else<span class="badge badge-red">Status pelanggan / WhatsApp tidak memenuhi syarat</span>@endif</td><td data-label="Data"><a class="button button-ghost" href="{{ route('admin.customers.edit', $occasion['customer']) }}">Edit Data</a></td></tr>
            @empty<tr><td colspan="5" style="text-align:center;padding:1.5rem;">Belum ada ulang tahun untuk jenis penerima yang dipilih hari ini.</td></tr>@endforelse
        </tbody></table>
    </div></section>

    <section class="card" style="margin-top:1rem;"><div class="card-body">
        <h2 class="section-title">7 Hari Mendatang</h2>
        <table class="table-card"><thead><tr><th>Tanggal</th><th>Pelanggan</th><th>Yang Berulang Tahun</th><th>Usia</th></tr></thead><tbody>
            @forelse($upcoming as $occasion)<tr><td data-label="Tanggal">{{ $occasion['date']->format('d M Y') }}</td><td data-label="Pelanggan">{{ $occasion['customer']->name }}</td><td data-label="Yang Berulang Tahun">{{ $occasion['name'] }} ({{ $occasion['type'] === 'baby' ? 'anak' : 'pelanggan' }})</td><td data-label="Usia">{{ $occasion['age'] }} tahun</td></tr>@empty<tr><td colspan="4" style="text-align:center;padding:1.5rem;">Belum ada ulang tahun dalam 7 hari ke depan.</td></tr>@endforelse
        </tbody></table>@if($upcomingCount > 30)<p class="muted" style="margin-top:.75rem;">Menampilkan 30 dari {{ $upcomingCount }} ulang tahun mendatang.</p>@endif
    </div></section>

    <section class="card" style="margin-top:1rem;"><div class="card-body">
        <h2 class="section-title">Riwayat Pengiriman</h2>
        <p class="muted" style="margin-bottom:1rem;">Status “Perlu diperiksa” berarti hasil gateway tidak pasti. Periksa chat WhatsApp sebelum menyatakan pesan belum terkirim. Pesan dengan status terkirim tidak bisa dikirim ulang lewat menu ini.</p>
        <table class="table-card"><thead><tr><th>Tanggal / Pelanggan</th><th>Ucapan</th><th>Status</th><th>Tindakan</th></tr></thead><tbody>
            @forelse($history as $record)
                <tr><td data-label="Tanggal / Pelanggan">{{ $record->birthday_on->format('d M Y') }}<br><strong>{{ $record->customer?->name ?: '-' }}</strong><br><small>{{ $record->birthday_type === 'baby' ? 'Bayi / anak' : 'Pelanggan / ibu' }} · {{ $record->attempts }} percobaan</small></td>
                    <td data-label="Ucapan"><details><summary>Lihat pesan</summary><div style="white-space:pre-wrap;overflow-wrap:anywhere;margin-top:.5rem;">{{ $record->message }}</div></details>@if($record->failure_reason)<small style="display:block;margin-top:.5rem;color:#7a3228;overflow-wrap:anywhere;">{{ \Illuminate\Support\Str::limit($record->failure_reason, 220) }}</small>@endif</td>
                    <td data-label="Status"><span class="badge {{ $record->status === 'sent' ? 'badge-green' : (in_array($record->status, ['failed', 'unknown']) ? 'badge-red' : 'badge-brown') }}">{{ $statuses[$record->status] ?? $record->status }}</span>@if($record->sent_at)<br><small>{{ $record->sent_at->timezone('Asia/Jakarta')->format('H:i').' WIB' }}</small>@endif @if($record->messageRecord?->read_at)<br><small>Sudah dibaca</small>@elseif($record->messageRecord?->delivered_at)<br><small>Diterima WhatsApp</small>@endif</td>
                    <td data-label="Tindakan">
                        @php($stale = $record->status === 'sending' && $record->started_at?->lt(now()->subMinutes(2)))
                        @if($canProcess && $record->birthday_on->toDateString() === $today->toDateString() && (in_array($record->status, ['failed', 'skipped', 'unknown']) || $stale))
                            <form method="POST" action="{{ route('admin.birthdays.retry', $record) }}">@csrf
                                @if($record->status === 'unknown' || $stale)<label class="birthday-check"><input type="checkbox" name="confirmed_not_sent" value="1" required> Saya sudah memeriksa WhatsApp; ucapan ini belum terkirim.</label>@endif
                                <button class="button button-ghost" type="submit">Coba Ulang</button>
                            </form>
                        @else<span class="muted">—</span>@endif
                    </td>
                </tr>
            @empty<tr><td colspan="4" style="text-align:center;padding:1.5rem;">Belum ada riwayat ucapan ulang tahun.</td></tr>@endforelse
        </tbody></table><div style="margin-top:1rem;">{{ $history->links() }}</div>
    </div></section>
    <style>
        .birthday-label{display:block;margin-bottom:.4rem;font-weight:700;color:#705b4d}
        .birthday-check{display:flex;gap:.6rem;align-items:center;margin-bottom:.75rem}
        .birthday-preview{margin-top:1rem;padding:1.25rem;background:#f2f6ed;border:1px solid #c8d7b8;border-radius:1rem}
    </style>
@endsection
