<article class="card"><div class="card-body rental-form"><h3>{{ \App\Models\RentalCustomerRequest::KINDS[$entry->kind] }} #{{ $entry->id }} · <a href="{{ route('admin.rentals.orders.show', $entry->order) }}">{{ $entry->order->code }}</a></h3>
    <p>{{ $entry->order->customer_snapshot['name'] ?? $entry->order->customer->name }} · {{ \App\Models\RentalCustomerRequest::STATUSES[$entry->status] }} · diajukan {{ $entry->created_at->format('d M Y H:i') }} WIB.</p>
    <p>Alasan: {{ $entry->reason }}<br>Dana bersih saat pengajuan Rp {{ number_format($entry->estimate['received'], 0, ',', '.') }} · potongan Rp {{ number_format($entry->estimate['fee'], 0, ',', '.') }} · estimasi refund Rp {{ number_format($entry->estimate['refund'], 0, ',', '.') }}.</p>
    @if($entry->status === 'pending')
        <p class="muted">Periksa dana masuk dan bukti pembayaran. Menyetujui pembatalan akan membatalkan pesanan serta melepas reservasi. Persetujuan refund belum mencatat pengembalian dana.</p>
        <form class="rental-form" method="POST" action="{{ route('admin.rentals.customer-requests.review', [$entry->order, $entry]) }}">@csrf
            <label>Catatan keputusan untuk pelanggan<textarea class="input" name="review_notes" minlength="5" maxlength="1000" required></textarea></label>
            <div class="rental-actions"><button class="button button-primary" name="action" value="approve">Setujui Pengajuan #{{ $entry->id }}</button><button class="button button-secondary" name="action" value="reject">Tolak Pengajuan #{{ $entry->id }}</button></div>
        </form>
    @else<p>Keputusan: {{ $entry->review_notes }}<br>Refund disetujui: Rp {{ number_format($entry->approved_amount, 0, ',', '.') }}.</p>@endif
    @if($entry->status === 'approved' && $entry->approved_amount > 0)
        <h4>Catat refund yang sudah dikembalikan</h4><p class="muted">Nominal Rp {{ number_format($entry->approved_amount, 0, ',', '.') }}. Aplikasi mencatat bukti; transfer/pengembalian tunai dilakukan di luar aplikasi kepada penerima yang sudah dicocokkan.</p>
        <form class="rental-form rental-grid" enctype="multipart/form-data" method="POST" action="{{ route('admin.rentals.customer-requests.settle', [$entry->order, $entry]) }}">@csrf
            <label>Nama penerima refund<input class="input" name="party_name" maxlength="150" required></label><label>Metode refund<select class="input" name="method"><option value="transfer">Transfer</option><option value="cash">Tunai</option><option value="other">Lainnya</option></select></label>
            <label>Tanggal dana dikembalikan<input class="input" type="date" name="refund_date" min="{{ $entry->reviewed_at->toDateString() }}" max="{{ today()->toDateString() }}" value="{{ today()->toDateString() }}" required></label>
            <label>Referensi refund<input class="input" name="reference" maxlength="150" required></label>
            <label class="rental-wide">Bukti refund (JPG/PNG/WebP/PDF, maks. 5 MB)<input class="input" type="file" name="proof" accept="image/jpeg,image/png,image/webp,application/pdf" required></label>
            <label class="rental-check rental-wide"><input type="checkbox" name="refund_completed" value="1" required> Dana sesuai nominal sudah dikembalikan dan penerimanya sudah dicocokkan.</label>
            <button class="button button-primary rental-wide">Simpan Bukti & Kuitansi Refund #{{ $entry->id }}</button>
        </form>
    @endif
    @if($entry->status === 'settled')<p>Dana dikembalikan {{ $entry->refund_date->format('d M Y') }} · dicatat {{ $entry->settled_at->format('d M Y H:i') }} WIB.</p><a href="{{ route('admin.rentals.customer-requests.file', [$entry->order, $entry]) }}" target="_blank" rel="noopener noreferrer">Lihat bukti refund #{{ $entry->id }}</a>@endif
</div></article>
