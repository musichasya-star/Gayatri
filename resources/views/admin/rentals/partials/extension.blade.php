@if(in_array($order->status, ['reserved', 'rented']) && $order->items->whereNull('returned_at')->isNotEmpty() && !$order->customerRequests()->where('kind', 'cancellation')->where('status', 'pending')->exists())
    <section class="card" style="margin-top:1rem;"><div class="card-body">
        <h2>Perpanjangan Sewa</h2><p class="muted">Tanggal kembali saat ini {{ $order->end_date->format('d M Y') }}. Tarif mengikuti tarif awal dan dibulatkan sesuai periode sewa. Tambahan biaya hanya untuk unit yang belum kembali. Deposit serta ongkos antar-jemput tetap.</p>
        <form class="rental-form" data-rental-extension data-preview-url="{{ route('admin.rentals.orders.extension-preview', $order) }}" method="POST" action="{{ route('admin.rentals.orders.extend', $order) }}">@csrf
            <label>Tanggal kembali baru<input class="input" type="date" name="new_end_date" min="{{ max(today()->toDateString(), $order->end_date->copy()->addDay()->toDateString()) }}" required></label>
            <label>Alasan / catatan persetujuan perpanjangan<textarea class="input" name="notes" minlength="5" maxlength="3000" required></textarea></label>
            <input type="hidden" name="extension_token" value="">
            <button class="button button-secondary" type="button" data-preview-extension>Cek Jadwal & Tambahan Biaya</button>
            <div class="rental-summary" data-extension-summary role="status" aria-live="polite">Pilih tanggal baru untuk memeriksa ketersediaan dan tambahan biaya.</div>
            <button class="button button-primary" type="submit" data-save-extension disabled>Catat Perpanjangan</button>
        </form>
    </div></section>
@endif
@if($order->extensions->isNotEmpty())
    <section class="card" style="margin-top:1rem;"><div class="card-body"><h2>Riwayat Perpanjangan</h2>
        @foreach($order->extensions->sortByDesc('id') as $extension)<p><strong>{{ $extension->old_end_date->format('d M Y') }} → {{ $extension->new_end_date->format('d M Y') }}</strong><br>Tambahan Rp {{ number_format((float) $extension->additional_charge, 0, ',', '.') }} · {{ $extension->notes }}<br><span class="muted">{{ $extension->created_at->format('d M Y H:i') }}</span></p>@endforeach
    </div></section>
@endif
@once<script src="{{ asset('js/rental-extension.js') }}" defer></script>@endonce
