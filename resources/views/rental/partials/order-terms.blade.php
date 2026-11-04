<section class="card"><div class="card-body rental-form"><h2>Ketentuan sewa & persetujuan</h2>
    @if($order->terms_snapshot)
        <details><summary>Baca ketentuan yang tersimpan pada pesanan</summary>@include('rental.partials.policy-content', ['policy' => $order->terms_snapshot])</details>
        @if($order->terms_accepted_at)<p class="rental-alert">Disetujui {{ $order->terms_accepted_at->format('d M Y H:i') }} WIB melalui {{ $order->terms_acceptance_source === 'checkout' ? 'form pemesanan' : 'portal pribadi' }}.</p>
        @else<p class="rental-alert">Pelanggan belum menyetujui ketentuan. Persetujuan melalui portal pribadi diperlukan sebelum serah-terima.</p>
            @if($customerFacing ?? false)
                @if(in_array($order->status, ['pending','reserved']) && !$order->isExpired())<form method="POST" action="{{ route('rental.portal.terms', $order->code) }}">@csrf<input type="hidden" name="terms_hash" value="{{ $order->terms_snapshot['hash'] }}"><label class="rental-check"><input type="checkbox" name="terms_accepted" value="1" required> Saya telah membaca dan menyetujui ketentuan sewa pesanan ini.</label><button class="button button-primary">Simpan Persetujuan Ketentuan</button></form>@endif
            @endif
        @endif
        <p class="muted rental-small">Aturan pada pesanan ini tetap mengikuti salinan saat pemesanan. Versi {{ $order->terms_snapshot['version'] }} · {{ substr($order->terms_snapshot['hash'], 0, 12) }}.</p>
    @else<p class="muted">Pesanan lama belum memiliki salinan ketentuan dan persetujuan digital. Aturan baru tidak diterapkan otomatis pada pesanan ini.</p>@endif
</div></section>
