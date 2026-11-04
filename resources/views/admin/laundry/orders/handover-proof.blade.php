@if($order->handed_over_at)
<section><h2>BUKTI SERAH TERIMA BARANG</h2><p>Barang diserahkan kepada <strong>{{ $order->receiver_name }}</strong><br>{{ $order->receiver_phone }}<br>{{ $order->handed_over_at->format('d M Y H:i') }} WIB</p>
    <ul>@foreach($order->items as $item)<li>{{ $item->bag_code }} · {{ $item->bag_label }} · {{ $item->actual_pieces }} potong</li>@endforeach</ul>
    <p class="laundry-preserve">{{ $order->handover_notes }}</p>
    <p>Jumlah dan label diperiksa saat serah terima. Bukti pembayaran menggunakan kuitansi masing-masing pembayaran.</p>
</section>
@endif
