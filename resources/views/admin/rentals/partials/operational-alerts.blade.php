@if($alerts->isNotEmpty())
    <section class="rental-alert" aria-label="Peringatan operasional rental"><h2>{{ $alerts->count() }} peringatan operasional</h2>
        @foreach($alerts->take($alertLimit ?? 5) as $alert)<details style="padding:.8rem 0;border-bottom:1px solid #d8c3a5;"><summary>
            <strong>{{ $alert['severity'] === 'critical' ? 'Perlu tindakan' : 'Perlu dipantau' }} · {{ $alert['type'] }}</strong>@if($alert['order']) · {{ $alert['order']->code }}@endif</summary>
            <p>{{ $alert['message'] }}<br>{{ $alert['action'] }}</p>
            <div class="rental-actions">@if($alert['order'])<a class="button button-secondary" href="{{ route('admin.rentals.orders.show', $alert['order']) }}">{{ $alert['order']->code }}</a>@endif @if($alert['blocking_order'])<a class="button button-secondary" href="{{ route('admin.rentals.orders.show', $alert['blocking_order']) }}">Transaksi asal {{ $alert['blocking_order']->code }}</a>@endif @if(!empty($alert['url']))<a class="button button-secondary" href="{{ $alert['url'] }}">Tindak lanjuti</a>@endif</div>
        </details>@endforeach
        @if($alerts->count() > ($alertLimit ?? 5))<p><a href="{{ route('admin.rentals.operations') }}">Lihat semua peringatan</a></p>@endif
    </section>
@endif
