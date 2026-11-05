<!doctype html>
<html lang="id"><head>
@include('partials.theme')<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $document->number }} — {{ \App\Models\LaundryDocument::TYPES[$document->type] }}</title><link rel="stylesheet" href="{{ asset('css/laundry.css').'?v='.config('gayatri_frontend.asset_version') }}"></head>
<body class="laundry-receipt"><main>
    <div class="laundry-actions laundry-no-print"><button type="button" data-laundry-print>Cetak / Simpan PDF</button><a href="{{ $backUrl ?? route('admin.laundry.orders.show', $order) }}">Kembali ke Pesanan</a></div>
    <header><p class="laundry-eyebrow">{{ strtoupper(\App\Models\LaundryDocument::TYPES[$document->type]) }}</p>
        <h1>{{ $snapshot['outlet']['outlet_name'] }}</h1><p class="laundry-preserve">{{ $snapshot['outlet']['address'] }}<br>WhatsApp {{ $snapshot['outlet']['whatsapp_number'] }}<br>{{ $snapshot['outlet']['opening_hours'] }}</p>
        <h2>{{ $document->number }}</h2><p>Pesanan {{ $snapshot['order_code'] }} · Terbit {{ $document->issued_at->format('d M Y H:i') }} WIB · Petugas {{ $snapshot['issuer'] }} @if($snapshot['is_demo']) · DATA DEMO @endif</p>
    </header>
    @if($document->voided_at)<section class="laundry-document-void"><h2>DOKUMEN DIBATALKAN</h2><p>{{ $document->voided_at->format('d M Y H:i') }} WIB · {{ $document->void_reason }}</p><p>Dokumen ini dipertahankan untuk riwayat dan tidak berlaku sebagai tagihan/kuitansi aktif.</p></section> @endif
    <section><strong>{{ $snapshot['customer']['name'] }}</strong><p>{{ $snapshot['customer']['whatsapp_number'] ?: $snapshot['customer']['phone'] }}</p></section>
    @if($document->type === 'receipt')
    <section class="laundry-document-total"><h2>Telah diterima: Rp {{ number_format($snapshot['payment']['amount'], 0, ',', '.') }}</h2><p>Dari {{ $snapshot['payment']['payer_name'] }}<br>Metode {{ \App\Models\LaundryPayment::METHODS[$snapshot['payment']['method']] }}<br>Tanggal pembayaran {{ \Carbon\Carbon::parse($snapshot['payment']['paid_at'])->timezone(config('app.timezone'))->format('d M Y H:i') }} WIB<br>Referensi {{ $snapshot['payment']['reference'] ?: '—' }}</p><p class="laundry-preserve">{{ $snapshot['payment']['notes'] }}</p></section>
    @endif
    <table><thead><tr><th>Kantong / layanan</th><th>Hasil timbang</th><th>Dasar tagih / tarif</th><th>Nilai jasa</th></tr></thead><tbody>
        @foreach($snapshot['items'] as $line)<tr><td><strong>{{ $line['bag_label'] }}</strong><br>{{ $line['bag_code'] }}<br>{{ $line['name'] }}</td><td>{{ number_format($line['quantity_milli'] / 1000, $line['unit'] === 'kg' ? 3 : 0, ',', '.') }} {{ $line['unit'] === 'kg' ? 'kg' : 'potong' }}</td><td>{{ number_format($line['billable_milli'] / 1000, $line['unit'] === 'kg' ? 3 : 0, ',', '.') }} {{ $line['unit'] === 'kg' ? 'kg' : 'potong' }}<br>Rp {{ number_format($line['price'], 0, ',', '.') }}/{{ $line['unit'] === 'kg' ? 'kg' : 'potong' }}</td><td>Rp {{ number_format($line['amount'], 0, ',', '.') }}</td></tr>@endforeach
    </tbody></table>
    @include('admin.laundry.document-charges')
<section class="laundry-document-total"><p>Total tagihan laundry: <strong>Rp {{ number_format($snapshot['total'], 0, ',', '.') }}</strong></p><p>Akumulasi pembayaran saat dokumen terbit: <strong>Rp {{ number_format($snapshot['paid'], 0, ',', '.') }}</strong></p><p>Sisa tagihan saat dokumen terbit: <strong>Rp {{ number_format($snapshot['balance'], 0, ',', '.') }}</strong></p></section>
    <section><p>{{ $document->type === 'invoice' ? 'Invoice adalah rincian tagihan jasa dan ongkir yang tercantum. Pembayaran dibuktikan dengan kuitansi untuk setiap pembayaran yang dicatat.' : 'Kuitansi ini mencatat pembayaran di atas. Status pelunasan mengikuti sisa tagihan, dan bukan hanya keberadaan kuitansi.' }}</p><p>Rincian dan saldo merupakan salinan pada waktu dokumen terbit. Koreksi pembayaran setelahnya dicatat dalam riwayat pesanan. Minimum dan pembulatan berlaku per kantong/baris. Dokumen ini mencakup jasa laundry dan ongkir yang tercantum pada pesanan tersebut.</p></section>
</main><script src="{{ asset('js/laundry.js') }}" defer></script></body></html>
