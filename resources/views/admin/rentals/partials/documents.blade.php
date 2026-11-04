@once<link rel="stylesheet" href="{{ asset('css/rental-documents.css') }}">@endonce
@php
    // Always paginate independently of any eager-loaded documents relation.
    $documentHistory = \App\Models\RentalDocument::where('rental_order_id', $order->id)->orderByDesc('id')->paginate(8, ['*'], 'documents_page')->withQueryString();
    $receiptHistory = \App\Models\RentalDocument::where('rental_order_id', $order->id)->whereNotNull('rental_payment_id')->get()->keyBy('rental_payment_id');
    $order->loadMissing('payments');
@endphp
<section class="rd-module" aria-labelledby="rental-document-heading">
    <header class="rd-module-heading"><div><p class="rd-eyebrow">DOKUMEN TRANSAKSI</p><h2 id="rental-document-heading">Invoice & Kuitansi</h2></div><a class="rd-button rd-button-quiet" href="{{ route('admin.rentals.documents.index', ['order_id' => $order->id]) }}">Arsip dokumen pesanan</a></header>
    <p>Data saat dokumen diterbitkan tersimpan tetap. Dokumen yang telah terbit dapat dibuka dan dicetak kembali.</p>
    @if(in_array($order->status, \App\Models\RentalDocument::INVOICE_STATUSES, true) && !$order->isExpired())
        <form method="POST" action="{{ route('admin.rentals.orders.invoice', $order) }}">@csrf<button class="rd-button" type="submit">Terbitkan / Buka Invoice</button><p class="rd-small">Jika data sama, invoice yang ada akan dibuka. Perubahan transaksi menghasilkan versi baru. Invoice bukan bukti pembayaran.{{ $order->status === 'cancelled' ? ' Invoice pembatalan menampilkan tagihan nol dan saldo pengembalian.' : '' }}</p></form>
    @else
        <p class="rd-notice">Konfirmasi pesanan terlebih dahulu untuk menerbitkan invoice. Permintaan pending atau kedaluwarsa belum dapat ditagihkan.</p>
    @endif
    <h3>Kuitansi per pencatatan buku kas</h3>
    <div class="rd-table-scroll"><table class="rd-table"><thead><tr><th scope="col">Entri / tanggal</th><th scope="col">Jenis / metode</th><th scope="col" class="rd-money">Nominal</th><th scope="col">Kuitansi</th></tr></thead><tbody>
        @forelse($order->payments->sortByDesc('id') as $payment)
            <tr><td>#{{ $payment->id }}<small>{{ $payment->created_at?->format('d M Y H:i') }} · {{ $payment->reference ?: 'Tanpa referensi' }}</small></td><td>{{ $payment->kind === 'refund' ? 'Pengembalian dana' : 'Pembayaran' }}<small>{{ ['cash' => 'Tunai', 'transfer' => 'Transfer bank', 'other' => 'Lainnya'][$payment->method] ?? $payment->method }}</small></td><td class="rd-money">Rp {{ number_format((float) $payment->amount, 2, ',', '.') }}</td><td>
                @if($receiptHistory->has($payment->id))
                    <a class="rd-button rd-button-quiet" href="{{ route('admin.rentals.documents.show', ['order' => $order->id, 'document' => $receiptHistory->get($payment->id)->id]) }}">Buka {{ $payment->kind === 'refund' ? 'bukti refund' : 'kuitansi' }}</a>
                @else
                    <form method="POST" action="{{ route('admin.rentals.orders.receipt', [$order, $payment]) }}">@csrf<button class="rd-button rd-button-quiet" type="submit">Terbitkan {{ $payment->kind === 'refund' ? 'bukti refund' : 'kuitansi' }}</button></form>
                @endif
            </td></tr>
        @empty<tr><td colspan="4" class="rd-empty">Belum ada pembayaran atau pengembalian dana yang tercatat.</td></tr>@endforelse
    </tbody></table></div>
    <h3>Riwayat dokumen terbit</h3>
    <div class="rd-document-history">@forelse($documentHistory as $issuedDocument)<article><div><span class="rd-type">{{ \App\Models\RentalDocument::TYPES[$issuedDocument->type] }}</span><strong>{{ $issuedDocument->number }}</strong><small>{{ \Carbon\CarbonImmutable::parse($issuedDocument->snapshot['issuance']['issued_at'])->format('d M Y H:i:s P') }} · {{ $issuedDocument->snapshot['issuance']['issuer_name'] }}</small></div><a class="rd-button rd-button-quiet" href="{{ route('admin.rentals.documents.show', ['order' => $order->id, 'document' => $issuedDocument->id]) }}">Lihat / Cetak</a></article>@empty<p class="rd-muted">Belum ada dokumen terbit untuk pesanan ini.</p>@endforelse</div>
    {{ $documentHistory->fragment('rental-document-heading')->links('admin.rentals.documents.pagination') }}
</section>
