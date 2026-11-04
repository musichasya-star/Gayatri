@extends('layouts.rental-catalogue')
@section('title', $product->name.' - Rental Gayatri')
@section('description', $product->description ?: 'Lihat tarif, deposit, dan ketentuan sewa '.$product->name.' di Gayatri.')
@section('content')
    @php($needsConfirmation = $product->requiresConfirmation() || !$product->rateOptions() || !$product->units_count)
    <nav class="gp-breadcrumb" aria-label="Jejak halaman"><a href="{{ route('landing.show') }}">Beranda</a><span aria-hidden="true">/</span><a href="{{ \App\Services\Web\PublicCatalogue::backUrl(request('return_to'),route('rental.index')) }}">Kembali ke katalog</a><span aria-hidden="true">/</span><span>{{ $product->name }}</span></nav>
    <div class="gp-two-columns"><section class="gp-card"><img class="rental-photo" src="{{ $product->imageUrl() }}" alt="{{ $product->name }}" width="700" height="420"><p class="gp-eyebrow" style="margin-top:22px">{{ $product->category }}</p><h1>{{ $product->name }}</h1><p style="white-space:pre-line;">{{ $product->description }}</p>
        <div class="rental-facts">@foreach($product->rateOptions() as $period=>$rate)<div>{{ \App\Models\RentalOrder::PERIODS[$period] }}<strong>Rp {{ number_format((float)$rate,0,',','.') }}</strong></div>@endforeach<div>Deposit / unit<strong>{{ $product->depositLabel() }}</strong></div></div>
        <h2>Kelengkapan</h2><p style="white-space:pre-line;">{{ $product->components ?: 'Dikonfirmasi admin saat serah-terima.' }}</p><h2>Ketentuan sewa</h2><p style="white-space:pre-line;">{{ $product->terms ?: 'Periksa kondisi dan kelengkapan bersama petugas saat serah-terima dan pengembalian.' }}</p>
    </section><section class="gp-card"><p class="gp-eyebrow">RENCANAKAN PENYEWAAN</p><h2>{{ $needsConfirmation?'Konfirmasi rincian alat':'Sewa bersama alat lain' }}</h2>
        @if($settings['enabled'] && !$needsConfirmation)<form class="rental-form" method="POST" action="{{ route('rental.cart.add') }}">@csrf<input type="hidden" name="product_id" value="{{ $product->id }}"><label>Jumlah ke keranjang<input class="input" type="number" name="quantity" min="1" max="20" value="1" required></label><button class="button button-primary">Tambah ke Keranjang</button></form><p class="muted">Keranjang menyimpan pilihan alat. Stok dan harga diperiksa setelah tanggal dipilih.</p><hr><h2>Pesan alat ini langsung</h2>@endif
        @if(!$settings['enabled'])<p class="rental-alert">Pemesanan sementara dinonaktifkan.</p>@elseif($needsConfirmation)<p class="rental-alert">Konfirmasikan stok unit, deposit, dan persiapan alat kepada admin sebelum memesan. Tarif mengikuti periode yang tercantum pada rincian alat.</p><a class="button button-primary" href="{{ \App\Http\Controllers\CatalogueController::inquiry('sewa '.$product->name) }}">Konfirmasi alat ke admin</a>@else
        <form class="rental-form" data-rental-checkout data-transaction-submit method="POST" action="{{ route('rental.store') }}" data-quote-url="{{ route('rental.quote') }}">@csrf
            @include('rental.partials.checkout-fields', ['fixedProduct' => $product, 'customerCheckout' => true])
            <button class="button button-primary" type="submit" data-rental-submit disabled>Kirim Permintaan Sewa</button>
            <p class="transaction-progress" data-transaction-progress role="status"></p>
        </form>@endif
    </section></div>
@endsection
