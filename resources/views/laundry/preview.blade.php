@extends('layouts.laundry-customer')
@section('title','Periksa Permintaan — Gayatri Laundry')
@section('content')
@php($rp = fn($amount) => $amount === null ? 'Dikonfirmasi admin' : 'Rp '.number_format($amount,0,',','.'))
<div class="hero">
    <p class="eyebrow">ESTIMASI AWAL</p>
    <h1>Periksa permintaan laundry</h1>
    <p>Tagihan akhir mengikuti hasil timbang dan ongkir yang Anda setujui di portal pesanan.</p>
</div>

<div class="grid">
    <section class="card accent">
        <h2>Layanan pilihan</h2>
        @foreach($preview['items'] as $line)
            @php($unit = $line['tariff']['unit'] === 'kg' ? 'kg' : 'potong')
            <div class="line">
                <strong>{{ $line['tariff']['name'] }}</strong>
                <div class="summary-row">
                    <span>{{ $line['quantity_milli'] === null ? 'Berat belum diketahui' : number_format($line['quantity_milli']/1000, $unit === 'kg' ? 3 : 0, ',', '.').' '.$unit }}</span>
                    <strong>{{ $line['amount'] === null ? 'Menunggu timbang' : $rp($line['amount']) }}</strong>
                </div>
                <small>{{ $rp($line['tariff']['price']) }}/{{ $unit }} · minimum {{ $line['tariff']['minimum_milli']/1000 }} · kelipatan {{ $line['tariff']['increment_milli']/1000 }}</small>
            </div>
        @endforeach
        <h3>Penyerahan pakaian</h3>
        <p>{{ $preview['data']['fulfilment'] === 'pickup' ? 'Permintaan jemput' : 'Diantar ke outlet' }}{{ !empty($preview['data']['delivery_required']) ? ' · minta pengantaran saat selesai' : ' · diambil di outlet' }}</p>
        @if(!empty($preview['data']['preferred_at']))
            <p>Usulan waktu {{ \Illuminate\Support\Carbon::parse($preview['data']['preferred_at'])->format('d M Y H:i') }} WIB. Admin akan mengonfirmasi jadwal.</p>
        @endif
        @if(!empty($preview['estimate']['transport']))
            <p class="preserve">{{ $preview['data']['address'] ?? '' }}</p>
            <p class="note">Estimasi ongkir memakai jarak garis lurus saat koordinat tersedia. Admin mengonfirmasi titik dan ongkir akhir sebelum persetujuan.</p>
        @endif
    </section>

    <section class="card">
        <h2>Ringkasan estimasi</h2>
        <p>{{ $preview['data']['name'] }}<br>{{ $preview['data']['whatsapp_number'] }}</p>
        <dl class="transaction-recap">
            <div><dt>Estimasi jasa</dt><dd>{{ $rp($preview['estimate']['service_total']) }}</dd></div>
            @foreach(['pickup' => 'Estimasi jemput', 'delivery' => 'Estimasi antar'] as $key => $label)
                @if(array_key_exists($key, $preview['estimate']['transport']))
                    <div><dt>{{ $label }}</dt><dd>{{ $rp($preview['estimate']['transport'][$key]['fee'] ?? null) }}</dd></div>
                @endif
            @endforeach
            <div><dt>Estimasi total</dt><dd>{{ $rp($preview['estimate']['total']) }}</dd></div>
        </dl>
        <form class="form-stack" method="POST" action="{{ route('laundry.store') }}" data-transaction-submit>
            @csrf
            <input type="hidden" name="token" value="{{ $preview['token'] }}">
            <p class="note">Admin akan mengonfirmasi permintaan melalui WhatsApp. Pembayaran dilakukan setelah rincian akhir disetujui.</p>
            <label class="check"><input type="checkbox" name="confirmed" value="1" required> Data dan layanan pilihan sudah sesuai. Saya akan memeriksa hasil timbang dan tagihan akhir di portal.</label>
            <button>Kirim permintaan Laundry</button>
            <p class="transaction-progress" data-transaction-progress role="status"></p>
            <a href="{{ route('laundry.index') }}#laundry-order">← Ubah data dan layanan</a>
        </form>
    </section>
</div>
@endsection
