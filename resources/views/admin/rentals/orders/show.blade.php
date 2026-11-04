@extends('layouts.app')
@section('title', $order->code.' - Rental Gayatri')
@section('content')
@include('partials.admin-operations-assets')
    <x-ui.page-header eyebrow="Rental Perlengkapan" title="{{ $order->code }}" description="{{ $order->customer->name }} · {{ $order->start_date->format('d M Y') }} — {{ $order->end_date->format('d M Y') }}" />
    @include('admin.rentals.partials.navigation')
    @php($next=\App\Support\OperationalGuidance::rental($order))
    <div class="ops-next"><div><strong>Langkah berikutnya: {{ $next['label'] }}</strong><p>{{ $next['hint'] }}</p></div><nav data-record-nav><a class="button button-primary" href="#{{ $next['anchor'] }}">Buka bagian</a></nav></div>
    <nav class="ops-nav" data-record-nav aria-label="Bagian transaksi sewa"><a class="button button-secondary" href="{{ route('admin.rentals.orders.index',\App\Support\OperationalQueue::rental(request())) }}">← Kembali ke daftar</a><a href="#ringkasan">Ringkasan & konfirmasi</a><a href="#unit-pengembalian">Unit & pengembalian</a><a href="#transport">Antar-jemput</a><a href="#pembayaran">Pembayaran & refund</a><a href="#dokumen">Invoice & kuitansi</a></nav>

    @include('admin.rentals.partials.operational-alerts')
    @if($order->is_demo)<p class="rental-alert">Transaksi DEMO — dikecualikan dari laporan operasional kecuali pilihan Sertakan data demo diaktifkan.</p>@endif
    <div class="card ops-record" id="ringkasan"><div class="card-body">
        <span class="badge badge-gold">{{ $order->isExpired() ? 'Permintaan kedaluwarsa — buat transaksi baru' : \App\Models\RentalOrder::STATUSES[$order->status] }}</span>
        @if($order->isOverdue())<p class="rental-alert rental-error">Ada unit terlambat kembali. Unit belum dapat dijanjikan untuk pesanan baru sebelum dikembalikan dan diperiksa.</p>@endif
        @if($order->status === 'pending' && !$order->isExpired())<p class="muted">Batas konfirmasi: {{ $order->hold_expires_at?->format('d M Y H:i') }} WIB. Setelah batas ini, stok tidak lagi ditahan.</p>@endif
        <p>WhatsApp pelanggan: {{ $order->customer->whatsapp_number ?: '-' }} · {{ \App\Models\RentalOrder::PERIODS[$order->period] ?? 'Periode tersimpan: '.$order->period }} × {{ $order->period_count }}</p>
        @if(!array_key_exists($order->period, \App\Models\RentalOrder::PERIODS))<p class="ops-notice">Kode periode pada data lama berbeda dari katalog saat ini. Tanggal dan tagihan tetap mengikuti transaksi tersimpan; tinjau sebelum melakukan perubahan.</p>@endif
        <div class="rental-facts">
            @foreach(['Sewa' => $order->rental_subtotal, 'Antar' => $order->delivery_fee, 'Jemput' => $order->collection_fee, 'Deposit awal' => $order->deposit_total, 'Biaya sewa + tambahan' => $order->charges(), 'Dana diterima bersih' => $order->received(), 'Sisa tagihan' => $order->balance(), 'Saldo dapat dikembalikan' => $order->refundable()] as $label => $amount)<div>{{ $label }}<strong>Rp {{ number_format((float) $amount, 0, ',', '.') }}</strong></div>@endforeach
        </div>
        <p class="muted">Deposit dapat dikembalikan setelah semua unit diperiksa atau pesanan dibatalkan. Biaya keterlambatan/kerusakan dicatat admin berdasarkan hasil pemeriksaan.</p>
        <p>{{ \App\Models\RentalOrder::FULFILMENTS[$order->fulfilment] }}@if($order->address)<br>{{ $order->address }}<br>Patokan: {{ $order->landmark ?: '-' }}@endif</p>
        @if($order->navigationUrl())<p><a class="button button-secondary" href="{{ $order->navigationUrl() }}" target="_blank" rel="noopener">Navigasi antar / jemput</a></p>@endif
        @if(isset($order->pricing_snapshot['route']['distance_meters'], $order->pricing_snapshot['route']['duration_seconds']))<p class="muted">Jarak jalan satu arah {{ number_format($order->pricing_snapshot['route']['distance_meters'] / 1000, 2, ',', '.') }} km · ±{{ ceil($order->pricing_snapshot['route']['duration_seconds'] / 60) }} menit. Ongkos mengikuti tarif saat pemesanan.</p>@endif
        <p>Catatan: {{ $order->notes ?: '-' }}<br>Catatan serah-terima: {{ $order->dispatch_notes ?: '-' }}</p>
        <div class="rental-actions">
            @if($order->status === 'pending' && !$order->isExpired())<form method="POST" action="{{ route('admin.rentals.orders.action', $order) }}">@csrf<input type="hidden" name="action" value="confirm"><button class="button button-primary">Konfirmasi Pesanan</button></form>@endif
            @if($order->status === 'reserved')<form class="rental-form" method="POST" action="{{ route('admin.rentals.orders.action', $order) }}">@csrf<input type="hidden" name="action" value="dispatch"><label>Catatan serah-terima / petugas<input class="input" name="notes" maxlength="3000" placeholder="Kondisi, kelengkapan, nama petugas"></label><button class="button button-primary">Catat Serah-terima / Antar</button></form>@endif
            @if(in_array($order->status, ['pending', 'reserved']))<details><summary>Batalkan pesanan</summary>
                @php($cancellationEstimate = app(\App\Services\CRM\RentalPolicy::class)->cancellation($order))
                <p>Estimasi potongan saat ini Rp {{ number_format($cancellationEstimate['fee'], 0, ',', '.') }} · saldo refund Rp {{ number_format($cancellationEstimate['refund'], 0, ',', '.') }}. Potongan mengikuti aturan pesanan. Jika ada pengajuan pelanggan, proses melalui menu Pembatalan & Refund.</p>
                <form class="rental-form" method="POST" action="{{ route('admin.rentals.orders.action', $order) }}">@csrf<input type="hidden" name="action" value="cancel"><label>Alasan pembatalan<input class="input" name="notes" maxlength="3000" required></label><button class="button button-secondary">Batalkan & Lepaskan Stok</button></form></details>@endif
        </div>
    </div></div>
<details class="ops-section" id="perpanjangan"><summary>Perpanjangan sewa</summary><div class="ops-section-body">@include('admin.rentals.partials.extension')</div></details>
<details class="ops-section" id="portal"><summary>Portal pelanggan</summary><div class="ops-section-body">@include('admin.rentals.partials.customer-portal')</div></details>
<details class="ops-section"><summary>Ketentuan & persetujuan pelanggan</summary><div class="ops-section-body">@include('rental.partials.order-terms', ['customerFacing' => false])</div></details>
<details class="ops-section"><summary>Pengajuan pembatalan & refund</summary><div class="ops-section-body">
    @foreach($order->customerRequests()->with('order.customer')->latest()->get() as $entry)@include('admin.rentals.partials.customer-request-review')@endforeach
    <p><a href="{{ route('admin.rentals.customer-requests.index') }}">Semua pengajuan pembatalan & refund</a></p></div></details>
<details class="ops-section" id="transport"><summary>Jadwal antar-jemput</summary><div class="ops-section-body">@include('admin.rentals.partials.transport')</div></details>
<details class="ops-section" id="unit-pengembalian" @if($next['anchor']==='unit-pengembalian') open @endif><summary>Unit & pengembalian</summary><div class="ops-section-body">
    <div class="rental-grid">@foreach($order->items as $item)<div class="card"><div class="card-body rental-form">
        <h3>{{ $item->unit_code }} · {{ $item->product_name }}</h3>
        <p>Sewa Rp {{ number_format((float) $item->rental_price, 0, ',', '.') }} · Deposit Rp {{ number_format((float) $item->deposit, 0, ',', '.') }}</p>
        <p class="muted">Kelengkapan: {{ $item->components ?: '-' }}<br>Kondisi saat keluar: {{ $item->checkout_condition ?: '-' }}<br>Status unit sekarang: {{ \App\Models\RentalUnit::STATUSES[$item->unit->status] }}</p>
        <p><a href="{{ route('admin.rentals.care.show', $item->unit) }}">Riwayat kondisi & perawatan {{ $item->unit_code }}</a></p>
        @include('admin.rentals.partials.checkout-inspection')
        @php($lateCalculation = $item->late_fee_calculation ?? app(\App\Services\CRM\RentalLateFee::class)->calculate($order, $item))
        @include('admin.rentals.partials.late-fee')
        @if(in_array($order->status, ['pending', 'reserved']) && !$order->isExpired())
            <details><summary>Ganti unit {{ $item->unit_code }}</summary><form method="POST" action="{{ route('admin.rentals.orders.replace', [$order, $item]) }}">@csrf<label>Unit pengganti<select class="input" name="unit_id" required><option value="">Pilih unit tersedia</option>@foreach($replacementUnits[$item->id] ?? [] as $replacement)<option value="{{ $replacement->id }}">{{ $replacement->code }}</option>@endforeach</select></label><button class="button button-secondary">Ganti Unit</button></form></details>
        @endif
        @if($order->status === 'rented' && !$item->returned_at)
            <form method="POST" enctype="multipart/form-data" action="{{ route('admin.rentals.orders.return', [$order, $item]) }}">@csrf
                <label>Kondisi saat diterima {{ $item->unit_code }}<textarea class="input" name="notes" minlength="3" maxlength="3000" required placeholder="Catat kelengkapan dan kondisi aktual"></textarea></label>
                <label>Foto pengembalian {{ $item->unit_code }} (opsional)<input class="input" type="file" name="photo" accept="image/jpeg,image/png,image/webp"></label>
                <button class="button button-primary">Terima Pengembalian {{ $item->unit_code }}</button>
            </form>
        @endif
        @if($item->returned_at)<p>Diterima {{ $item->returned_at->format('d M Y H:i') }}<br>{{ $item->return_notes }}</p>@endif
        @if($item->return_photo_path)<a class="button button-secondary" href="{{ route('admin.rentals.items.photo', $item) }}" target="_blank" rel="noopener">Foto kondisi kembali</a>@endif
        @if($item->returned_at && !$item->inspected_at)
            <form method="POST" action="{{ route('admin.rentals.orders.inspect', [$order, $item]) }}">@csrf
                <label>Hasil pemeriksaan {{ $item->unit_code }}<textarea class="input" name="notes" minlength="3" maxlength="3000" required placeholder="Periksa kondisi, kebersihan, dan kelengkapan"></textarea></label>
                @include('admin.rentals.partials.checklist', ['checklistLabels' => $item->checklistLabels()])
                <label>Status setelah pemeriksaan<select class="input" name="unit_status"><option value="ready">Siap disewa — bersih & lengkap</option><option value="cleaning">Masih dibersihkan</option><option value="maintenance">Perlu perbaikan</option><option value="retired">Diarsipkan</option></select></label>
                <label>Biaya keterlambatan {{ $item->unit_code }} (Rp)<input class="input" name="late_fee" type="number" min="0" max="100000000" step="1" value="{{ $lateCalculation['suggested_fee'] }}" required></label>
                <button class="button button-secondary" type="button" data-use-late-fee="{{ $lateCalculation['suggested_fee'] }}">Gunakan Usulan Denda {{ $item->unit_code }}</button>
                <label>Alasan penyesuaian denda {{ $item->unit_code }} (ditampilkan kepada pelanggan)<textarea class="input" name="late_fee_reason" maxlength="1000" placeholder="Wajib diisi jika nominal berbeda dari usulan kalkulator"></textarea></label>
                <label>Biaya kerusakan / kehilangan kelengkapan {{ $item->unit_code }} (Rp)<input class="input" name="damage_fee" type="number" min="0" step="1" value="0" required></label>
                <button class="button button-primary">Selesaikan Pemeriksaan {{ $item->unit_code }}</button>
            </form>
        @elseif($item->inspected_at)<p class="muted">Diperiksa {{ $item->inspected_at->format('d M Y H:i') }}<br>{{ $item->inspection_notes }}<br>Tambahan: terlambat Rp {{ number_format((float) $item->late_fee, 0, ',', '.') }} + kerusakan Rp {{ number_format((float) $item->damage_fee, 0, ',', '.') }}</p>@endif
    </div></div>@endforeach</div>
</div></details><details class="ops-section" id="pembayaran" @if($next['anchor']==='pembayaran') open @endif><summary>Pembayaran & pengembalian dana</summary><div class="ops-section-body">
    <div class="card"><div class="card-body">
        <p class="muted">Catat pembayaran yang sudah diterima atau dana yang sudah dikembalikan. Simpan bukti/referensinya di sini.</p>
        <form class="rental-form rental-grid" method="POST" action="{{ route('admin.rentals.orders.payment', $order) }}">@csrf
            <input type="hidden" name="entry_key" value="{{ \Illuminate\Support\Str::uuid() }}">
            <label>Jenis pencatatan<select class="input" name="kind"><option value="payment">Pembayaran diterima</option><option value="refund">Pengembalian dana</option></select></label>
            <label>Nominal (Rp)<input class="input" type="number" name="amount" min="1" step="1" required></label>
            <label>Metode<select class="input" name="method"><option value="cash">Tunai</option><option value="transfer">Transfer</option><option value="other">Lainnya</option></select></label>
            <label>Nomor referensi / bukti<input class="input" name="reference" maxlength="150"></label>
            <label>Nama pembayar / penerima refund (opsional)<input class="input" name="party_name" maxlength="150"></label>
            <label>Catatan pembayaran<input class="input" name="notes" maxlength="2000"></label>
            <button class="button button-primary" style="align-self:end;">Simpan Pencatatan</button>
        </form>
        @forelse($order->payments as $payment)<p>{{ $payment->created_at->format('d M Y H:i') }} · {{ $payment->kind === 'payment' ? 'Diterima' : 'Dikembalikan' }} Rp {{ number_format((float) $payment->amount, 0, ',', '.') }} · {{ $payment->method }} · {{ $payment->reference ?: '-' }} · {{ $payment->notes }}</p>@empty<p class="muted">Belum ada pencatatan pembayaran.</p>@endforelse
    </div></div>
</div></details><details class="ops-section" id="dokumen"><summary>Invoice & kuitansi</summary><div class="ops-section-body">@include('admin.rentals.partials.documents')</div></details>
    <script src="{{ asset('js/rental-policy.js') }}" defer></script>
@endsection
