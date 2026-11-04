@if(array_key_exists('service_total', $snapshot))
<section><p>Subtotal jasa laundry: <strong>Rp {{ number_format($snapshot['service_total'],0,',','.') }}</strong></p><p>Ongkir penjemputan: <strong>Rp {{ number_format($snapshot['pickup_fee'],0,',','.') }}</strong></p><p>Ongkir pengantaran: <strong>Rp {{ number_format($snapshot['delivery_fee'],0,',','.') }}</strong></p>
@foreach($snapshot['transport_pricing'] ?? [] as $kind => $quote)<p>{{ $kind === 'pickup' ? 'Jemput' : 'Antar' }}: @if($quote['waived'] ?? false) biaya dibebaskan · {{ $quote['approval_reference'] }} @else {{ number_format($quote['distance_milli']/1000,1,',','.') }} km · {{ $quote['distance_source'] === 'manual' ? 'jarak jalan manual' : 'garis lurus dari koordinat' }} · {{ $quote['address'] }} @endif</p>@endforeach
</section>
@endif
