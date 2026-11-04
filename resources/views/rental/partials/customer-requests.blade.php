<section class="card"><h2>Pembatalan & refund</h2>
    <p class="muted">Pengajuan menunggu keputusan admin. Refund disetujui belum berarti dana sudah dikembalikan; bukti dan kuitansi tersedia setelah admin mencatat pengembalian dana.</p>
    @if($requestPreview)
        @php($estimate = $requestPreview['quote']['estimate'])
        <h3>{{ \App\Models\RentalCustomerRequest::KINDS[$requestPreview['quote']['kind']] }}</h3>
        <p>Dana diterima bersih: Rp {{ number_format($estimate['received'], 0, ',', '.') }}<br>Estimasi potongan pembatalan: Rp {{ number_format($estimate['fee'], 0, ',', '.') }}<br><strong>Estimasi dana dikembalikan: Rp {{ number_format($estimate['refund'], 0, ',', '.') }}</strong></p>
        <form class="rental-form" method="POST" action="{{ route('rental.portal.requests', $order->code) }}">@csrf
            <input type="hidden" name="entry_key" value="{{ \Illuminate\Support\Str::uuid() }}"><input type="hidden" name="request_token" value="{{ $requestPreview['token'] }}"><input type="hidden" name="kind" value="{{ $requestPreview['quote']['kind'] }}">
            <label>Alasan pengajuan<textarea class="input" name="reason" minlength="5" maxlength="1000" required></textarea></label>
            <label class="rental-check"><input type="checkbox" name="estimate_accepted" value="1" required> Saya memahami rincian estimasi dan mengajukan peninjauan admin.</label>
            <button class="button button-secondary">Kirim Pengajuan {{ $requestPreview['quote']['kind'] === 'cancellation' ? 'Pembatalan' : 'Refund' }}</button>
        </form>
    @else<p class="muted">Pengajuan baru tersedia jika status/saldo memenuhi syarat dan tidak ada pengajuan lain yang menunggu penyelesaian.</p>@endif
    <h3>Riwayat pengajuan</h3>
    @forelse($order->customerRequests->sortByDesc('id') as $entry)
        <article class="rental-alert"><strong>{{ \App\Models\RentalCustomerRequest::KINDS[$entry->kind] }} #{{ $entry->id }} · {{ \App\Models\RentalCustomerRequest::STATUSES[$entry->status] }}</strong>
            <p>{{ $entry->created_at->format('d M Y H:i') }} WIB · {{ $entry->reason }}<br>Estimasi potongan Rp {{ number_format($entry->estimate['fee'], 0, ',', '.') }} · estimasi pengembalian Rp {{ number_format($entry->estimate['refund'], 0, ',', '.') }}.</p>
            @if($entry->review_notes)<p>Keputusan admin: {{ $entry->review_notes }}<br>Refund disetujui Rp {{ number_format($entry->approved_amount, 0, ',', '.') }}.</p>@endif
            @if($entry->status === 'approved' && $entry->approved_amount > 0)<p>Pengembalian dana menunggu pencatatan admin.</p>@endif
            @if($entry->status === 'settled')<p>Tanggal dana dikembalikan: {{ $entry->refund_date->format('d M Y') }}.</p><a href="{{ route('rental.portal.refund-file', [$order->code, $entry]) }}" target="_blank" rel="noopener noreferrer">Lihat bukti refund</a>@endif
        </article>
    @empty<p class="muted">Belum ada pengajuan pembatalan/refund.</p>@endforelse
</section>
