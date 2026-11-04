<section class="section landing-rental" id="rental" aria-labelledby="rental-title">
    <div class="landing-rental-intro">
        <div>
            <p class="eyebrow">SEWA PERLENGKAPAN BAYI & BALITA</p>
            <h2 class="section-title" id="rental-title">Temani tumbuhnya,<br>sewa kebutuhannya.</h2>
            <p class="lead">Pilih perlengkapan untuk si kecil tanpa harus membeli. Cek alat dan tanggal sewa, lalu ajukan pesanan langsung dari website.</p>
            <div class="landing-rental-actions">
                <a class="btn btn-primary" href="{{ route('rental.index') }}"><i data-lucide="shopping-bag"></i>{{ $rentalSettings['enabled'] ? 'Pilih Alat & Pesan Sewa' : 'Lihat Katalog Alat' }}</a>
                <a class="btn btn-soft" href="{{ route('rental.cart.index') }}"><i data-lucide="shopping-cart"></i>Keranjang Sewa @if(array_sum(session('rental_cart', []))) ({{ array_sum(session('rental_cart', [])) }}) @endif</a>
            </div>
        </div>
        <aside class="landing-rental-payment" aria-label="Cara pembayaran rental">
            <span class="landing-rental-badge"><i data-lucide="wallet"></i>Pembayaran manual</span>
            <h3>Pesan dulu, tunggu konfirmasi admin.</h3>
            <p>Setelah pesanan dikonfirmasi, transfer DP atau pelunasan sesuai instruksi admin. Unggah bukti di portal pelanggan untuk diverifikasi.</p>
            <p class="landing-rental-note">Simpan tautan portal pribadi yang muncul setelah memesan untuk melihat status dan tagihan.</p>
        </aside>
    </div>
    @if(!$rentalSettings['enabled'])
        <p class="card" role="status">Pemesanan rental sementara dinonaktifkan. Bunda tetap dapat melihat katalog dan informasi alat.</p>
    @endif
    <div class="landing-rental-products">
        @forelse($rentalProducts as $rentalProduct)
            <article class="card landing-rental-product">
                <a class="landing-rental-image" href="{{ route('rental.show', $rentalProduct) }}" tabindex="-1" aria-hidden="true"><img src="{{ $rentalProduct->imageUrl() }}" alt="" loading="lazy" width="400" height="240"></a>
                <div class="landing-rental-product-info">
                    <p class="eyebrow">{{ $rentalProduct->category ?: 'Perlengkapan bayi' }}</p>
                    <h3><a href="{{ route('rental.show', $rentalProduct) }}">{{ $rentalProduct->name }}</a></h3>
                    <p class="landing-rental-rate">{{ $rentalProduct->startingRateLabel() }}</p>
                    <p class="landing-rental-note">{{ $rentalProduct->depositLabel() }} · Ketersediaan mengikuti tanggal sewa.</p>
                    <a class="btn btn-soft" href="{{ route('rental.show', $rentalProduct) }}">Lihat Detail & Tanggal<i data-lucide="arrow-up-right"></i></a>
                </div>
            </article>
        @empty
            <div class="card landing-rental-empty"><i data-lucide="baby"></i><h3>Katalog sedang disiapkan</h3><p class="lead">Pilihan perlengkapan akan tampil di sini setelah diterbitkan admin. Silakan cek kembali untuk melihat alat yang bisa disewa.</p></div>
        @endforelse
    </div>
    <ol class="landing-rental-steps" aria-label="Langkah pemesanan rental">
        <li><span>01</span><div><h3>Pilih alat & tanggal</h3><p>Pesan satu alat atau gabungkan beberapa alat di keranjang. Cek stok, harga, dan deposit sebelum mengirim permintaan.</p></div></li>
        <li><span>02</span><div><h3>Konfirmasi & transfer manual</h3><p>Isi data penyewa dan setujui ketentuan. Tunggu konfirmasi admin, lalu ikuti instruksi pembayaran di portal pribadi.</p></div></li>
        <li><span>03</span><div><h3>Unggah bukti & ambil alat</h3><p>Admin memverifikasi bukti pembayaran sebelum serah-terima. {{ $rentalSettings['delivery_enabled'] ? 'Pilih ambil sendiri atau layanan antar-jemput sesuai jangkauan.' : 'Ambil dan kembalikan alat di lokasi Gayatri sesuai jadwal yang dikonfirmasi.' }}</p></div></li>
    </ol>
</section>
