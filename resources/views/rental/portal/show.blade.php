@extends('layouts.rental')
@section('title', 'Portal Pesanan '.$order->code)
@section('content')
    <p class="muted">PORTAL PRIBADI PESANAN</p><h1>{{ $order->code }}</h1><p>Halo {{ $order->customer_snapshot['name'] ?? 'Pelanggan Gayatri' }}. {{ \App\Models\RentalOrder::STATUSES[$order->status] }} · {{ $order->start_date->format('d M Y') }} – {{ $order->end_date->format('d M Y') }}.</p>
    <p class="muted">Halaman ini hanya untuk pesanan Anda. Gunakan tautan pribadi yang disimpan atau diberikan admin saat membuka dari perangkat lain.</p>
    @if($order->isExpired())<p class="rental-alert rental-error">Batas konfirmasi permintaan telah lewat. Hubungi admin untuk ketersediaan terbaru.</p>@endif
    @if($order->status === 'cancelled')
        <x-public.next-step label="Pesanan dibatalkan" detail="Periksa rincian pembatalan dan saldo pengembalian di bawah."/>
    @elseif($order->isExpired())
        <x-public.next-step label="Perlu konfirmasi ulang" detail="Hubungi admin untuk memeriksa ketersediaan pada tanggal yang Anda perlukan."/>
    @elseif($order->paymentProofs->contains('status', 'pending'))
        <x-public.next-step label="Bukti sedang diperiksa" detail="Tunggu verifikasi admin. Sisa tagihan diperbarui setelah bukti disetujui." href="#pembayaran" action="Lihat bukti pembayaran"/>
    @elseif($order->customerRequests->where('kind', 'cancellation')->contains('status', 'pending'))
        <x-public.next-step label="Pembatalan sedang ditinjau" detail="Tunggu keputusan admin sebelum melakukan transfer baru."/>
    @elseif($order->status === 'pending')
        <x-public.next-step label="Menunggu konfirmasi admin" detail="Admin akan mengonfirmasi ketersediaan, jadwal serah-terima, dan instruksi pembayaran."/>
    @elseif($paymentSummary['payable'])
        <x-public.next-step label="Menunggu pembayaran" detail="Periksa sisa tagihan dan instruksi transfer, lalu unggah bukti." href="#pembayaran" action="Lihat pembayaran"/>
    @else
        <x-public.next-step label="Tagihan telah dipenuhi" detail="Periksa jadwal serah-terima dan riwayat pesanan di bawah."/>
    @endif
    <div class="rental-grid"><section class="card"><h2>Rincian alat</h2>
        @foreach($order->items->groupBy('product_name') as $name => $items)<p><strong>{{ $name }} × {{ $items->count() }}</strong><br>Sewa Rp {{ number_format($items->sum('rental_price'), 0, ',', '.') }}<br>{{ $items->whereNotNull('returned_at')->count() }} dari {{ $items->count() }} unit sudah dikembalikan.</p>@endforeach
        <p>{{ \App\Models\RentalOrder::FULFILMENTS[$order->fulfilment] }}<br>{{ $order->address ?: ($order->pricing_snapshot['office_address'] ?? 'Alamat pengambilan dikonfirmasi admin.') }}</p>
    </section><section class="card"><h2>Tagihan terkini</h2><div class="rental-facts">
        @foreach(['Biaya jasa sewa & tambahan' => $order->charges(), 'Deposit awal' => $order->deposit_total, 'Total tagihan saat ini' => $order->totalDue(), 'Pembayaran bersih terverifikasi' => $order->received(), 'Sisa tagihan' => $order->balance(), 'Saldo dapat dikembalikan' => $order->refundable()] as $label => $value)<div>{{ $label }}<strong>Rp {{ number_format((float) $value, 0, ',', '.') }}</strong></div>@endforeach
        </div><p class="muted">Deposit terpisah dari biaya jasa. Saldo pengembalian ditentukan setelah pemeriksaan/pembatalan dan pencatatan refund oleh admin.</p>
        @if($paymentSummary['minimum_dp'])<p>DP minimum Rp {{ number_format($paymentSummary['minimum_dp'], 0, ',', '.') }}. Kekurangan DP terverifikasi Rp {{ number_format($paymentSummary['dp_remaining'], 0, ',', '.') }}.</p>@endif
        @if($order->dp_due_at)<p>Batas DP: {{ $order->dp_due_at->format('d M Y H:i') }} WIB.</p>@endif
        @if($order->balance_due_at && !in_array($order->status, ['completed','cancelled']))<p>Batas pelunasan: {{ $order->balance_due_at->format('d M Y H:i') }} WIB.</p>@endif
        @if($paymentSummary['dp_overdue'] || $paymentSummary['balance_overdue'])<p class="rental-alert rental-error">Ada pembayaran yang melewati tenggat. Hubungi admin jika sudah melakukan transfer.</p>@endif
        @if($order->payment_terms['require_full_payment'] ?? false)<p class="muted">Pelunasan harus diverifikasi sebelum serah-terima alat.</p>@endif
    </section></div>
    @include('rental.partials.order-terms', ['customerFacing' => true])
    @include('rental.partials.customer-requests')
    @if((float)$order->cancellation_fee > 0)<p class="rental-alert">Biaya pembatalan yang ditetapkan: Rp {{ number_format($order->cancellation_fee, 0, ',', '.') }}. Saldo refund mengikuti dana diterima setelah potongan ini.</p>@endif
    @foreach($order->items->whereNotNull('late_fee_calculation') as $feeItem)<p class="rental-alert">Denda {{ $feeItem->product_name }}: terlambat {{ $feeItem->late_fee_calculation['late_days'] }} hari · setelah toleransi {{ $feeItem->late_fee_calculation['billable_days'] }} hari · ditetapkan Rp {{ number_format($feeItem->late_fee, 0, ',', '.') }}.@if($feeItem->late_fee_calculation['override_reason'] ?? null)<br>Alasan penyesuaian: {{ $feeItem->late_fee_calculation['override_reason'] }}@endif</p>@endforeach
    <section class="card" id="pembayaran"><h2>Pembayaran DP / pelunasan</h2><p class="muted">Pembayaran diproses manual melalui transfer dan verifikasi admin.</p>
        @if($order->customerRequests->where('kind', 'cancellation')->contains('status', 'pending'))<p class="rental-alert">Pengajuan pembatalan sedang ditinjau. Konfirmasikan kepada admin sebelum melakukan transfer baru. Bukti dana yang sudah terlanjur dikirim tetap dapat diunggah agar saldo dicocokkan.</p>@endif
        @if($order->payment_terms['payment_instructions'] ?? '')<div class="rental-summary">{{ $order->payment_terms['payment_instructions'] }}</div>@endif
        @if($order->paymentProofs->contains('status', 'pending'))<p class="rental-alert">Bukti Anda sedang menunggu verifikasi admin. Bukti baru dapat diajukan setelah peninjauan selesai.</p>
        @elseif($paymentSummary['payable'])
            <p class="muted">Transfer sesuai instruksi yang telah dikonfirmasi admin, lalu unggah bukti. Unggahan belum dihitung sebagai pembayaran sampai disetujui.</p>
            <form class="rental-form rental-grid" method="POST" enctype="multipart/form-data" action="{{ route('rental.portal.proof', $order->code) }}" data-payment-proof data-transaction-submit>@csrf
                <input type="hidden" name="entry_key" value="{{ old('entry_key', (string) \Illuminate\Support\Str::uuid()) }}">
                <label>Jenis pembayaran<select class="input" name="kind" data-proof-kind>@if($paymentSummary['dp_remaining'] > 0)<option value="dp" @selected(old('kind') === 'dp')>DP</option>@endif<option value="settlement" @selected(old('kind') === 'settlement')>Pelunasan sisa tagihan</option></select></label>
                <label>Nominal transfer (Rp)<input class="input" name="amount" type="number" min="1" max="{{ $paymentSummary['balance'] }}" step="1" value="{{ old('amount', $paymentSummary['dp_remaining'] ?: $paymentSummary['balance']) }}" data-proof-amount data-dp="{{ $paymentSummary['dp_remaining'] }}" data-balance="{{ $paymentSummary['balance'] }}" required></label>
                <label>Nama pembayar / pemilik rekening<input class="input" name="payer_name" maxlength="150" value="{{ old('payer_name', $order->customer_snapshot['name'] ?? '') }}" required></label>
                <label>Tanggal transfer<input class="input" type="date" name="payment_date" min="{{ $order->created_at->toDateString() }}" max="{{ today()->toDateString() }}" value="{{ old('payment_date', today()->toDateString()) }}" required></label>
                <label>Nomor referensi transfer (opsional)<input class="input" name="reference" maxlength="150" value="{{ old('reference') }}"></label>
                <label>Bukti transfer (JPG/PNG/WebP/PDF, maks. 5 MB)<input class="input" type="file" name="proof" accept="image/jpeg,image/png,image/webp,application/pdf" required></label>
                <label class="rental-wide">Catatan pembayaran (opsional)<textarea class="input" name="notes" maxlength="500">{{ old('notes') }}</textarea></label><button class="button button-primary rental-wide">Kirim Bukti untuk Verifikasi</button><p class="transaction-progress rental-wide" data-transaction-progress role="status"></p>
            </form>
        @else<p class="muted">{{ $order->status === 'pending' ? 'Tunggu konfirmasi pesanan dari admin sebelum melakukan pembayaran.' : 'Tidak ada tagihan yang dapat diajukan saat ini.' }}</p>@endif
        <h3>Riwayat bukti pembayaran</h3>
        @forelse($order->paymentProofs->sortByDesc('id') as $proof)<article class="rental-alert"><strong>{{ \App\Models\RentalPaymentProof::STATUSES[$proof->status] }} · Rp {{ number_format($proof->amount, 0, ',', '.') }}</strong><p>{{ $proof->kind === 'dp' ? 'DP' : 'Pelunasan' }} · {{ $proof->payer_name }} · {{ $proof->payment_date->format('d M Y') }}@if($proof->review_notes)<br>Catatan admin: {{ $proof->review_notes }}@endif</p><a href="{{ route('rental.portal.proof-file', [$order->code, $proof]) }}" target="_blank" rel="noopener noreferrer">Lihat bukti yang diunggah</a></article>@empty<p class="muted">Belum ada unggahan bukti pembayaran.</p>@endforelse
    </section>
    <section class="card"><h2>Jadwal antar-jemput</h2>@forelse($order->transportTasks as $task)<p><strong>{{ \App\Models\RentalTransportTask::TYPES[$task->type] }}</strong> · {{ \App\Models\RentalTransportTask::STATUSES[$task->status] }}<br>{{ $task->scheduled_start->timezone('Asia/Jakarta')->format('d M Y H:i') }} – {{ $task->scheduled_end->timezone('Asia/Jakarta')->format('H:i') }} WIB</p>@if($task->isActive() && $task->hasStaleSchedule($order))<p class="rental-alert">Admin sedang menyesuaikan jadwal penjemputan setelah perpanjangan.</p>@endif @empty<p class="muted">{{ $order->fulfilment === 'self' ? 'Pengambilan dan pengembalian dilakukan sendiri.' : 'Jadwal akan dikonfirmasi admin.' }}</p>@endforelse</section>
    <section class="card"><h2>Invoice & kuitansi</h2><p class="muted">Setiap dokumen mengikuti data saat diterbitkan. Tagihan terkini ditampilkan di atas.</p>@forelse($order->documents->sortByDesc('id') as $document)<p><a href="{{ route('rental.portal.document', [$order->code, $document]) }}">{{ \App\Models\RentalDocument::TYPES[$document->type] }} · {{ $document->number }}</a></p>@empty<p class="muted">Dokumen belum diterbitkan admin.</p>@endforelse</section>
    <section class="card"><h2>Ajukan perpanjangan</h2><p class="muted">Pengajuan tidak langsung mengubah jadwal atau menahan stok tambahan. Admin akan memeriksa ketersediaan dan menyetujui biaya sebelum memperpanjang.</p>
        @if($order->customerRequests->where('kind', 'cancellation')->contains('status', 'pending'))<p class="rental-alert">Selesaikan keputusan pembatalan sebelum mengajukan perpanjangan.</p>
        @elseif($order->extensionRequests->contains('status', 'pending'))<p class="rental-alert">Pengajuan Anda menunggu keputusan admin.</p>
        @elseif(in_array($order->status, ['reserved','rented']) && $order->items->whereNull('returned_at')->isNotEmpty())
            <form class="rental-form" data-rental-extension data-preview-url="{{ route('rental.portal.extension-preview', $order->code) }}" method="POST" action="{{ route('rental.portal.extension', $order->code) }}">@csrf<input type="hidden" name="entry_key" value="{{ \Illuminate\Support\Str::uuid() }}"><input type="hidden" name="extension_token" value="">
                <label>Tanggal kembali baru<input class="input" type="date" name="new_end_date" min="{{ max(today()->toDateString(), $order->end_date->copy()->addDay()->toDateString()) }}" required></label><label>Catatan pengajuan<textarea class="input" name="notes" minlength="5" maxlength="1000" required></textarea></label>
                <button class="button button-secondary" type="button" data-preview-extension>Cek Ketersediaan & Biaya</button><div class="rental-summary" data-extension-summary role="status">Pilih tanggal kembali baru.</div><button class="button button-primary" type="submit" data-save-extension disabled>Setujui Estimasi & Ajukan</button>
            </form>
        @else<p class="muted">Perpanjangan belum tersedia pada status pesanan saat ini.</p>@endif
        @foreach($order->extensionRequests->sortByDesc('id') as $entry)<p><strong>{{ \App\Models\RentalPaymentProof::STATUSES[$entry->status] }}</strong> · tanggal baru {{ $entry->new_end_date->format('d M Y') }} · estimasi tambahan Rp {{ number_format($entry->quoted_amount, 0, ',', '.') }}@if($entry->review_notes)<br>{{ $entry->review_notes }}@endif</p>@endforeach
    </section>
    <script src="{{ asset('js/rental-extension.js') }}" defer></script><script src="{{ asset('js/rental-portal.js') }}" defer></script>
@endsection
