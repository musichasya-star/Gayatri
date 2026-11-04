@extends('admin.rentals.documents.layout')
@section('title', e($document->number.' · '.$snapshot['office']['name']))
@section('navigation')
    <a href="{{ route('admin.rentals.orders.show', $document->rental_order_id) }}">{{ $snapshot['order']['code'] }}</a>
@endsection
@section('actions')
    <button class="rd-button" type="button" onclick="window.print()">Cetak / Simpan PDF</button>
@endsection
@section('content')
    @php
        $invoice = $document->type === \App\Models\RentalDocument::TYPE_INVOICE;
        $refund = $document->type === \App\Models\RentalDocument::TYPE_REFUND_RECEIPT;
        $cancelled = $snapshot['order']['status'] === 'cancelled';
        $rupiah = static fn ($value) => 'Rp '.number_format((float) $value, 2, ',', '.');
        $date = static fn ($value) => \Carbon\CarbonImmutable::parse($value)->locale('id')->translatedFormat('d M Y');
        $issued = \Carbon\CarbonImmutable::parse($snapshot['issuance']['issued_at'])->locale('id');
        $totals = $snapshot['totals'];
        $methods = ['cash' => 'Tunai', 'transfer' => 'Transfer bank', 'other' => 'Lainnya'];
    @endphp
    <p class="rd-print-help rd-no-print">Pilih ukuran A4 pada dialog cetak, lalu pilih <strong>Simpan sebagai PDF</strong> untuk menyimpan salinan.</p>
    <article class="rd-paper {{ $refund ? 'rd-refund' : '' }} {{ $cancelled ? 'rd-cancelled' : '' }}" aria-labelledby="rd-document-title">
        <header class="rd-document-header">
            <div class="rd-office"><div class="rd-office-identity"><span class="rd-brand-mark" aria-hidden="true">G</span><strong>{{ $snapshot['office']['name'] }}</strong></div><p class="rd-preserve-lines">{{ $snapshot['office']['address'] ?: 'Alamat kantor belum dicatat saat penerbitan.' }}</p></div>
            <div class="rd-document-identity"><p class="rd-eyebrow">RENTAL PERLENGKAPAN</p><h1 id="rd-document-title">{{ $invoice ? 'INVOICE' : ($refund ? 'PENGEMBALIAN DANA' : 'KUITANSI') }}</h1><p class="rd-document-number">{{ $document->number }}</p><span class="rd-pill">{{ $invoice ? 'Tagihan sewa · Versi '.$document->version : ($refund ? 'Bukti pengembalian dana' : 'Bukti penerimaan pembayaran') }}</span></div>
        </header>
        <div class="rd-issued-strip"><span><strong>Data saat dokumen diterbitkan</strong> {{ $issued->translatedFormat('d F Y') }} · {{ $issued->format('H:i:s P') }}</span><span>{{ $snapshot['order']['status_label'] }}</span></div>
        <section class="rd-parties" aria-label="Pelanggan dan pesanan">
            <div><p class="rd-eyebrow">{{ $refund ? 'DIKEMBALIKAN KEPADA' : ($invoice ? 'DITAGIHKAN KEPADA' : 'DITERIMA DARI') }}</p><h2>{{ $snapshot['customer']['name'] }}</h2><p>{{ $snapshot['customer']['phone'] ?: 'Kontak belum dicatat' }}</p>@if($snapshot['order']['address'])<p class="rd-preserve-lines">{{ $snapshot['order']['address'] }}</p>@endif</div>
            <dl class="rd-order-facts"><div><dt>Pesanan</dt><dd>{{ $snapshot['order']['code'] }}</dd></div><div><dt>Periode sewa</dt><dd>{{ $date($snapshot['order']['start_date']) }} – {{ $date($snapshot['order']['end_date']) }}</dd></div><div><dt>Perhitungan</dt><dd>{{ $snapshot['order']['period_label'] }} · {{ $snapshot['order']['period_count'] }} periode</dd></div><div><dt>Layanan</dt><dd>{{ $snapshot['order']['fulfilment_label'] }}</dd></div></dl>
        </section>
        @if($snapshot['cancellation_note'])<aside class="rd-notice rd-cancellation"><strong>Pesanan dibatalkan · Tagihan {{ $rupiah($totals['total_due']) }}</strong><p>{{ $snapshot['cancellation_note'] }}</p></aside>@endif
        @if(!$invoice)
            <section class="rd-receipt-summary" aria-label="Pencatatan buku kas">
                <div class="rd-receipt-amount"><p class="rd-eyebrow">{{ $refund ? 'NOMINAL DANA DIKEMBALIKAN' : 'NOMINAL PEMBAYARAN DITERIMA' }}</p><p class="rd-amount">{{ $rupiah($snapshot['receipt']['amount']) }}</p><span>Satu entri buku kas #{{ $snapshot['receipt']['payment_id'] }}</span></div>
                <dl class="rd-order-facts"><div><dt>{{ $refund ? 'Penerima' : 'Pembayar' }} (pelanggan pesanan)</dt><dd>{{ $snapshot['receipt']['party_name'] }}</dd></div><div><dt>Metode</dt><dd>{{ $methods[$snapshot['receipt']['method']] ?? $snapshot['receipt']['method'] }}</dd></div><div><dt>Referensi</dt><dd>{{ $snapshot['receipt']['reference'] ?: 'Tidak dicatat' }}</dd></div><div><dt>Waktu pencatatan</dt><dd>{{ $snapshot['receipt']['recorded_at'] ? \Carbon\CarbonImmutable::parse($snapshot['receipt']['recorded_at'])->locale('id')->translatedFormat('d M Y H:i:s P') : 'Tidak dicatat' }}</dd></div></dl>
                <p class="rd-party-note">{{ ($snapshot['receipt']['party_basis'] ?? 'order_customer') === 'recorded_party' ? 'Identitas mengikuti nama yang dicatat pada pembayaran ini.' : 'Identitas mengacu pada pelanggan pesanan; nama pembayar/penerima terpisah belum dicatat pada entri ini.' }}</p>
                @if($snapshot['receipt']['notes'])<p class="rd-preserve-lines rd-receipt-notes"><strong>Catatan pencatatan:</strong> {{ $snapshot['receipt']['notes'] }}</p>@endif
            </section>
        @endif
        @if($invoice)
            <section class="rd-line-items" aria-labelledby="rd-items-title"><h2 id="rd-items-title" class="rd-section-title">Rincian sewa</h2>
                <div class="rd-table-scroll"><table class="rd-table rd-items-table">
                    <caption>{{ $cancelled ? 'Harga tersimpan untuk riwayat saja; seluruh rincian di bawah tidak dibebankan.' : 'Harga sewa setiap unit sudah termasuk perpanjangan yang tercatat.' }}</caption>
                    <thead><tr><th scope="col">Perlengkapan / unit</th><th scope="col">Akhir sewa</th><th scope="col" class="rd-money">Sewa</th><th scope="col" class="rd-money">Terlambat</th><th scope="col" class="rd-money">Kerusakan</th></tr></thead>
                    <tbody>@foreach($snapshot['items'] as $item)<tr><td><strong>{{ $item['product_name'] }}</strong><span>{{ $item['unit_code'] }}</span><small>Deposit awal {{ $rupiah($item['deposit_original']) }}</small></td><td>{{ $date($item['end_date']) }}</td><td class="rd-money rd-recorded-price">{{ $rupiah($item['rental_price']) }}</td><td class="rd-money rd-recorded-price">{{ $rupiah($item['late_fee']) }}</td><td class="rd-money rd-recorded-price">{{ $rupiah($item['damage_fee']) }}</td></tr>@endforeach</tbody>
                </table></div>
            </section>
        @else
            <p class="rd-unit-summary"><strong>Unit sewa:</strong> {{ collect($snapshot['items'])->map(fn ($item) => $item['product_name'].' ('.$item['unit_code'].')')->join(', ') }}</p>
        @endif
        <section class="rd-financial-grid" aria-label="Posisi keuangan saat penerbitan">
            <aside class="rd-document-notes">
                <h2 class="rd-section-title">{{ $invoice ? 'Keterangan tagihan' : 'Keterangan kuitansi' }}</h2>
                <div class="rd-notice"><strong>{{ $invoice ? 'Invoice ini bukan bukti pembayaran.' : ($refund ? 'Bukti satu pencatatan pengembalian dana.' : 'Bukti satu pencatatan pembayaran.') }}</strong><p>{{ $invoice ? 'Pembayaran dibuktikan dengan kuitansi atas setiap entri buku kas yang tercatat.' : 'Nominal dokumen ini hanya untuk entri di atas. Saldo di samping menunjukkan keseluruhan transaksi saat dokumen diterbitkan; kuitansi tidak otomatis berarti lunas.' }}</p></div>
                <p><strong>Deposit dapat dikembalikan.</strong> Deposit terpisah dari biaya jasa dan bukan pendapatan. Nilai awal tetap ditampilkan untuk riwayat.</p>
                <p>Deposit awal pesanan: <strong>{{ $rupiah($snapshot['original']['deposit_total']) }}</strong>.<br>Deposit terutang saat terbit: <strong>{{ $rupiah($totals['deposit_required']) }}</strong>.</p>
                @if(in_array($snapshot['order']['status'], ['completed', 'cancelled'], true))<p>Setelah selesai atau dibatalkan, deposit tidak ditambahkan ke total tagihan. Saldo pengembalian mengikuti penerimaan bersih dikurangi biaya jasa.</p>@endif
                @if($snapshot['order']['notes'])<p class="rd-preserve-lines"><strong>Catatan pesanan:</strong><br>{{ $snapshot['order']['notes'] }}</p>@endif
            </aside>
            <div class="rd-totals"><h2 class="rd-section-title">Posisi transaksi saat terbit</h2><dl>
                <div><dt>Sewa (termasuk perpanjangan)</dt><dd>{{ $rupiah($totals['rental_subtotal']) }}</dd></div>
                <div><dt>Pengantaran</dt><dd>{{ $rupiah($totals['delivery_fee']) }}</dd></div>
                <div><dt>Penjemputan</dt><dd>{{ $rupiah($totals['collection_fee']) }}</dd></div>
                <div><dt>Biaya keterlambatan</dt><dd>{{ $rupiah($totals['late_fee']) }}</dd></div>
                <div><dt>Biaya kerusakan</dt><dd>{{ $rupiah($totals['damage_fee']) }}</dd></div>
                @if((float)($totals['cancellation_fee'] ?? 0) > 0)<div><dt>Biaya pembatalan</dt><dd>{{ $rupiah($totals['cancellation_fee']) }}</dd></div>@endif
                <div class="rd-subtotal"><dt>Total biaya jasa</dt><dd>{{ $rupiah($totals['charges']) }}</dd></div>
                <div><dt>Deposit terutang (dapat dikembalikan)</dt><dd>{{ $rupiah($totals['deposit_required']) }}</dd></div>
                <div class="rd-total-due"><dt>Total tagihan</dt><dd>{{ $rupiah($totals['total_due']) }}</dd></div>
                <div><dt>Seluruh pembayaran diterima</dt><dd>{{ $rupiah($totals['payments']) }}</dd></div>
                <div><dt>Seluruh dana dikembalikan</dt><dd>{{ $rupiah($totals['refunds']) }}</dd></div>
                <div class="rd-subtotal"><dt>Penerimaan bersih</dt><dd>{{ $rupiah($totals['net_received']) }}</dd></div>
                <div class="rd-balance"><dt>Sisa tagihan</dt><dd>{{ $rupiah($totals['balance']) }}</dd></div>
                <div class="rd-refundable"><dt>Saldo dapat dikembalikan</dt><dd>{{ $rupiah($totals['refundable']) }}</dd></div>
            </dl></div>
        </section>
        @if($invoice && count($snapshot['extensions']))
            <section class="rd-extension-history"><h2 class="rd-section-title">Riwayat perpanjangan · sudah termasuk harga sewa</h2><p class="rd-small">Informasi historis; nominal berikut tidak ditambahkan lagi ke tagihan.{{ $cancelled ? ' Pesanan dibatalkan, sehingga biaya ini tidak dibebankan.' : '' }}</p>
                <ul>@foreach($snapshot['extensions'] as $extension)<li><strong>{{ $date($extension['old_end_date']) }} → {{ $date($extension['new_end_date']) }}</strong><span>{{ $rupiah($extension['additional_charge']) }} · {{ $extension['notes'] }}</span></li>@endforeach</ul>
            </section>
        @endif
        @if($invoice && count($snapshot['ledger']))
            <section class="rd-ledger"><h2 class="rd-section-title">Buku kas pada saat penerbitan</h2><div class="rd-table-scroll"><table class="rd-table"><thead><tr><th scope="col">Tanggal / entri</th><th scope="col">Jenis</th><th scope="col">Metode / referensi</th><th scope="col" class="rd-money">Nominal</th></tr></thead><tbody>@foreach($snapshot['ledger'] as $entry)<tr><td>{{ $entry['recorded_at'] ? $date($entry['recorded_at']) : '—' }}<small>#{{ $entry['id'] }}</small></td><td>{{ $entry['kind'] === 'refund' ? 'Pengembalian dana' : 'Pembayaran' }}</td><td>{{ $methods[$entry['method']] ?? $entry['method'] }}<small>{{ $entry['reference'] ?: 'Tanpa referensi' }}</small></td><td class="rd-money">{{ $rupiah($entry['amount']) }}</td></tr>@endforeach</tbody></table></div></section>
        @endif
        <footer class="rd-document-footer"><div><p class="rd-eyebrow">DITERBITKAN OLEH</p><strong>{{ $snapshot['issuance']['issuer_name'] }}</strong><p>Dicatat secara elektronik · {{ $issued->translatedFormat('d M Y H:i:s P') }}</p></div><p class="rd-snapshot-note">Data dan saldo mengikuti waktu penerbitan. Perubahan transaksi ditampilkan pada invoice versi berikutnya.</p></footer>
    </article>
@endsection
