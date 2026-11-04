@extends('layouts.rental-catalogue')
@section('title', 'Keranjang Sewa - Gayatri')
@section('content')
    <nav class="gp-breadcrumb" aria-label="Jejak halaman">
        <a href="{{ route('rental.index') }}">Katalog Rental</a><span aria-hidden="true">/</span><span>Keranjang</span>
    </nav>
    <h1>Keranjang sewa</h1>
    <p class="muted">Pilih alat, tentukan tanggal, lalu periksa total sebelum mengirim permintaan.</p>

    <div class="transaction-checkout">
        <section aria-label="Alat di keranjang">
            @forelse($cartItems as $productId => $quantity)
                @php($cartProduct = $cartProducts->get($productId))
                <article class="gp-card rental-form">
                    @if($cartProduct)
                        <img src="{{ $cartProduct->imageUrl() }}" alt="{{ $cartProduct->name }}" loading="lazy" width="360" height="165">
                    @endif
                    <h2>{{ $cartProduct?->name ?? 'Alat tidak tersedia' }}</h2>
                    @if(!$cartProduct?->is_active)
                        <p class="rental-alert rental-error">Alat ini sudah tidak tersedia. Hapus dari keranjang sebelum melanjutkan.</p>
                    @endif
                    <form method="POST" action="{{ route('rental.cart.update', $productId) }}" class="rental-actions" data-transaction-submit>
                        @csrf
                        @method('PUT')
                        <label>Jumlah {{ $cartProduct?->name }}<input class="input" type="number" name="quantity" min="0" max="20" value="{{ $quantity }}" required></label>
                        <button class="button button-secondary">Perbarui jumlah</button>
                        <small>Isi 0 untuk menghapus.</small>
                    </form>
                </article>
            @empty
                <div class="gp-card"><h2>Keranjang masih kosong</h2><p>Tambahkan alat dari katalog untuk memulai.</p></div>
            @endforelse
            <a class="transaction-link" href="{{ route('rental.index') }}">Pilih alat dari katalog</a>
        </section>

        @if($cartItems && !$unavailable && $settings['enabled'])
            <section class="gp-card" id="rental-checkout">
                <h2>Periksa periode dan biaya</h2>
                <form class="rental-form" data-rental-checkout data-transaction-submit method="POST" action="{{ route('rental.store') }}" data-quote-url="{{ route('rental.quote') }}">
                    @csrf
                    @include('rental.partials.checkout-fields', ['fixedProduct' => null, 'customerCheckout' => true])
                    <button class="button button-primary" data-rental-submit disabled>Kirim Permintaan Sewa</button>
                    <p class="transaction-progress" data-transaction-progress role="status"></p>
                </form>
            </section>
        @elseif(!$settings['enabled'])
            <p class="rental-alert">Pemesanan sementara dinonaktifkan.</p>
        @endif
    </div>
@endsection
