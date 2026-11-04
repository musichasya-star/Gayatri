@extends('layouts.app')
@section('title', 'Kalender Sewa - Gayatri CRM')
@section('content')
    <x-ui.page-header eyebrow="Rental Perlengkapan" title="Kalender Ketersediaan" description="Pantau reservasi, unit keluar, jeda pembersihan, dan keterlambatan per unit fisik." />
    @include('admin.rentals.partials.navigation')
    <form method="GET" class="rental-form rental-grid" style="margin-bottom:1rem;"><label>Bulan<input class="input" type="month" name="month" value="{{ $month->format('Y-m') }}" required></label><label>Produk<select class="input" name="product_id"><option value="">Semua alat</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected(request('product_id') == $product->id)>{{ $product->name }}</option>@endforeach</select></label><button class="button button-secondary" style="align-self:end;">Tampilkan Kalender</button></form>
    <p class="muted">Biru: dipesan · Hijau: disewa · Jingga: jeda bersih/pemeriksaan · Merah: terlambat · Abu-abu: tidak siap · —: tersedia. Klik sel pesanan untuk membuka transaksi.</p>
    <div class="card"><div class="card-body rental-scroll"><table class="rental-calendar"><caption>{{ $month->translatedFormat('F Y') }}</caption><thead><tr><th scope="col">Unit fisik</th>@for($day = 1; $day <= $month->daysInMonth; $day++)<th scope="col">{{ $day }}</th>@endfor</tr></thead><tbody>
        @forelse($units as $unit)<tr><th scope="row">{{ $unit->code }}<br><small>{{ $unit->product->name }}</small></th>
            @for($day = 1; $day <= $month->daysInMonth; $day++)
                @php
                    $date = $month->copy()->day($day);
                    $allocation = $unit->items->first(function ($item) use ($date) {
                        if ($item->inspected_at || $item->order->isExpired()) return false;
                        return $item->order->start_date->lte($date) && ($item->reserved_until->gt($date) || (!$item->returned_at && $item->order->status === 'rented' && $item->order->end_date->lt(today()) && $date->gte(today())));
                    });
                    $cell = $allocation ? (in_array($allocation->order->status, ['pending', 'reserved']) ? 'reserved' : $allocation->order->status) : (in_array($unit->status, ['ready', 'rented']) ? '' : 'blocked');
                    if ($allocation && $date->gt($allocation->rental_end_date ?? $allocation->order->end_date)) $cell = $allocation->order->isOverdue() ? 'overdue' : 'buffer';
                @endphp
                <td class="{{ $cell }}">@if($allocation)<a href="{{ route('admin.rentals.orders.show', $allocation->order) }}" title="{{ $allocation->order->code }} · {{ \App\Models\RentalOrder::STATUSES[$allocation->order->status] }}">{{ $cell === 'overdue' ? '!' : ($cell === 'buffer' ? 'B' : '●') }}</a>@else<span title="{{ $cell ? \App\Models\RentalUnit::STATUSES[$unit->status] : 'Tersedia' }}">{{ $cell ? '×' : '—' }}</span>@endif</td>
            @endfor
        </tr>@empty<tr><td colspan="32">Tambahkan unit fisik untuk melihat kalender.</td></tr>@endforelse
    </tbody></table></div></div>{{ $units->links() }}
@endsection
