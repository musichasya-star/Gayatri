<section class="card" style="margin-top:1rem"><div class="card-body">
    <h2 class="section-title">Riwayat pesanan laundry</h2>
    @foreach($order->events->sortByDesc('id') as $event)
    <article class="laundry-history">
        <strong>{{ \App\Models\LaundryOrder::EVENT_LABELS[$event->kind] ?? $event->kind }}</strong> · Revisi {{ $event->revision }}
        <p class="muted">{{ $event->created_at->format('d M Y H:i') }} WIB · {{ $event->actor?->name ?: (in_array($event->kind,['proof_uploaded']) || ($event->details['channel'] ?? '') === 'portal' ? 'Pelanggan melalui portal' : 'Admin terdahulu') }}</p>
        @if(isset($event->details['actual_total']))<p>Nilai jasa hasil timbang Rp {{ number_format($event->details['actual_total'], 0, ',', '.') }}</p> @endif
        @if($event->kind === 'approved')<p>{{ $event->details['approved_name'] }} menyetujui Rp {{ number_format($event->details['amount'], 0, ',', '.') }} · {{ $event->details['reference'] }}</p>
        @elseif(isset($event->details['amount']))<p>Nominal Rp {{ number_format($event->details['amount'], 0, ',', '.') }}</p> @endif
        @if(isset($event->details['from']))<p>{{ \App\Models\LaundryOrder::WORKFLOW[$event->details['from']] }} → {{ \App\Models\LaundryOrder::WORKFLOW[$event->details['to']] }}</p> @endif
        @if(isset($event->details['document_number']))<p>{{ $event->details['document_number'] }}</p> @endif
        @if(isset($event->details['receiver_name']))<p>Diterima {{ $event->details['receiver_name'] }} · {{ $event->details['receiver_phone'] }}</p> @endif
        @if($event->details['reason'] ?? null)<p class="laundry-preserve">{{ $event->details['reason'] }}</p> @endif
        @if($event->details['notes'] ?? null)<p class="laundry-preserve">{{ $event->details['notes'] }}</p> @endif
        <details><summary>Lihat salinan rincian revisi</summary><div class="laundry-history-detail">
            @if(isset($event->details['items']))
                @foreach($event->details['items'] as $line)
                    <p><strong>{{ $line['bag_label'] }}</strong> · {{ $line['bag_code'] }}<br>
                    @if(array_key_exists('received_pieces', $line))
                        Diterima {{ $line['received_pieces'] }} potong @if($line['actual_pieces'] !== null) · Aktual {{ $line['actual_pieces'] }} potong @endif<br>
                        {{ $line['actual_milli'] === null ? 'Belum ditimbang' : number_format($line['actual_milli'] / 1000, 3, ',', '.').' '.($line['tariff_snapshot']['unit'] === 'kg' ? 'kg' : 'potong') }} · {{ $line['actual_amount'] === null ? 'Nilai aktual belum tersedia' : 'Rp '.number_format($line['actual_amount'], 0, ',', '.') }}<br>{{ $line['weighing_notes'] }}
                    @elseif(isset($line['checked_pieces']))Diperiksa {{ $line['checked_pieces'] }} / {{ $line['expected_pieces'] }} potong<br>{{ $line['notes'] }}
                    @elseif(isset($line['returned_pieces']))Diserahkan {{ $line['returned_pieces'] }} potong · Label cocok
                    @endif</p>
                @endforeach
            @elseif(isset($event->details['steps']))<p>Rencana: {{ collect($event->details['steps'])->map(fn ($step) => \App\Models\LaundryOrder::STEPS[$step])->join(' → ') }}</p>
            @else<p>{{ $event->details['reference'] ?? $event->details['reason'] ?? 'Perubahan tersimpan pada revisi ini.' }}</p>
            @endif
        </div></details>
    </article>
    @endforeach
</div></section>
