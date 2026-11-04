@php($paymentTermsSummary = app(\App\Services\CRM\RentalPaymentTerms::class)->summary($order))
<section class="card"><div class="card-body rental-form"><h2>Portal Pelanggan & Pembayaran</h2>
    <p>DP minimum Rp {{ number_format($paymentTermsSummary['minimum_dp'], 0, ',', '.') }} · Kekurangan DP Rp {{ number_format($paymentTermsSummary['dp_remaining'], 0, ',', '.') }}.<br>Batas DP: {{ $order->dp_due_at?->format('d M Y H:i') ?: '-' }} WIB · Batas pelunasan: {{ $order->balance_due_at?->format('d M Y H:i') ?: '-' }} WIB.</p>
    @if($paymentTermsSummary['dp_overdue'] || $paymentTermsSummary['balance_overdue'])<p class="rental-alert rental-error">Ada tenggat pembayaran yang terlewati. Tinjau bukti transfer sebelum menindaklanjuti pesanan.</p>@endif
    <p class="muted">{{ ($order->payment_terms['require_full_payment'] ?? false) ? 'Pelunasan terverifikasi wajib sebelum serah-terima.' : 'Serah-terima mengikuti keputusan admin tanpa syarat pelunasan otomatis.' }}</p>
    <form method="POST" action="{{ route('admin.rentals.portal.issue', $order) }}">@csrf<button class="button button-secondary">Buat / Ganti Tautan Portal</button><p class="muted">Tautan berlaku minimal 90 hari. Mengganti tautan menonaktifkan tautan dan sesi portal lama. Berikan tautan hanya kepada penyewa pesanan ini.</p></form>
    @if((int)session('portal_order_id') === $order->id && session('portal_link'))<div class="rental-alert"><label>Tautan pribadi baru<input class="input" value="{{ session('portal_link') }}" readonly></label><a href="{{ session('portal_link') }}" target="_blank" rel="noopener noreferrer">Buka Portal Pelanggan</a></div>@endif
    <h3>WhatsApp pembayaran & portal</h3>
    <p class="muted">Tujuan ini khusus untuk pesanan ini. Cocokkan nomor dengan penyewa sebelum mengirim tagihan atau tautan pribadi. Mengubah tujuan yang sudah diverifikasi mengganti tautan portal dan menonaktifkan akses dari tautan lama.</p>
    @if($order->notification_verified_at)<p class="rental-alert">Tujuan terverifikasi admin: {{ $order->notification_phone }} · {{ $order->notification_verified_at->format('d M Y H:i') }} WIB.</p>@else<p class="rental-alert">Tujuan pembayaran dan portal belum dicocokkan admin.</p>@endif
    <form class="rental-form rental-grid" method="POST" action="{{ route('admin.rentals.messaging.recipient', $order) }}">@csrf
        <label>Nomor WhatsApp tujuan pesanan<input class="input" name="notification_phone" maxlength="30" value="{{ $order->notification_phone ?: ($order->customer_snapshot['whatsapp_number'] ?? $order->customer->whatsapp_number) }}" required></label>
        <label class="rental-check"><input type="checkbox" name="recipient_confirmed" value="1" required> Saya sudah mencocokkan nomor tujuan ini dengan penyewa pesanan.</label>
        <button class="button button-secondary">Simpan Tujuan WhatsApp Pesanan</button>
    </form>
    <form method="POST" action="{{ route('admin.rentals.messaging.portal', $order) }}">@csrf<button class="button button-secondary">Antrekan Tautan Portal ke WhatsApp</button></form>
    <p class="muted rental-small">WhatsApp Rental, jenis pesan Tautan Portal, antrean, dan koneksi gateway perlu aktif. Tautan dikirim paling banyak sekali per versi/tujuan/hari. Untuk tautan lama yang belum dapat dikirim, gunakan Buat / Ganti Tautan Portal terlebih dahulu. Konfirmasi pesanan juga mengantrekan tautan jika tujuan dan pengaturan sudah siap.</p>
    <p><a href="{{ route('admin.rentals.payment-reviews') }}">Semua verifikasi pembayaran & pengajuan</a></p>
</div></section>
@foreach($order->paymentProofs()->with('order')->latest()->get() as $proof)@include('admin.rentals.partials.proof-review')@endforeach
@foreach($order->extensionRequests()->with('order')->latest()->get() as $entry)@include('admin.rentals.partials.extension-request-review')@endforeach
