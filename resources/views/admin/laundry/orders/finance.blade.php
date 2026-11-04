@php($paid = $order->paidAmount())
@php($balance = $order->balance())
<section class="card" style="margin-top:1rem" id="pembayaran"><div class="card-body">
    <h2 class="section-title">Pembayaran manual & dokumen</h2>
    <div class="laundry-grid"><div class="laundry-values"><div>{{ $order->status === 'cancelled' ? 'Riwayat nilai jasa' : 'Total tagihan (jasa + ongkir)' }}<strong>{{ $order->actual_total === null ? 'Belum ditimbang' : 'Rp '.number_format($order->totalDue(), 0, ',', '.') }}</strong></div><div>Pembayaran tercatat<strong>Rp {{ number_format($paid, 0, ',', '.') }}</strong></div></div><div class="laundry-values"><div>Sisa tagihan<strong>{{ $balance === null ? 'Belum tersedia' : 'Rp '.number_format($balance, 0, ',', '.') }}</strong></div><div>Status pembayaran<strong>{{ $order->status === 'cancelled' ? 'Dibatalkan' : ($balance === null ? 'Belum tersedia' : ($balance === 0 ? 'Lunas' : ($paid > 0 ? 'Dibayar sebagian / DP' : 'Belum dibayar'))) }}</strong></div></div></div>
    <p class="muted">Pencatatan dilakukan admin setelah memeriksa uang tunai atau transfer yang benar-benar diterima. DP dan pelunasan dicatat terpisah setelah nilai jasa disetujui. Tagihan mencakup jasa laundry dan ongkir yang sudah disepakati pada pesanan ini.</p>
    @if($order->paymentProofs()->where('status','pending')->exists())<p class="laundry-alert">Ada bukti pelanggan yang menunggu pemeriksaan. <a href="{{ route('admin.laundry.requests.proofs',['status'=>'pending','include_demo'=>$order->is_demo ? 1 : 0]) }}">Periksa bukti pembayaran</a> sebelum menambah pembayaran.</p>@endif
    @if($order->status === 'approved' && $balance > 0 && !$order->paymentProofs()->where('status','pending')->exists())
    <details class="laundry-history"><summary>Catat DP / pembayaran / pelunasan</summary>
        <form class="laundry-form laundry-grid" method="POST" enctype="multipart/form-data" data-laundry-payment action="{{ route('admin.laundry.payments.store', $order) }}">@csrf
            <input type="hidden" name="revision" value="{{ $order->revision }}"><input type="hidden" name="entry_key" value="{{ old('entry_key', (string) \Illuminate\Support\Str::uuid()) }}">
            <label>Nominal diterima (Rp)<input class="input" type="number" name="amount" min="1" max="{{ $balance }}" step="1" value="{{ old('amount') }}" required></label>
            <label>Metode pembayaran<select class="input" name="method" required>@foreach(\App\Models\LaundryPayment::METHODS as $key => $label)<option value="{{ $key }}" @selected(old('method') === $key)>{{ $label }}</option>@endforeach</select></label>
            <label>Waktu pembayaran<input class="input" type="datetime-local" name="paid_at" value="{{ old('paid_at', now()->format('Y-m-d\TH:i')) }}" min="{{ $order->received_at->format('Y-m-d\TH:i') }}" max="{{ now()->format('Y-m-d\TH:i') }}" required></label>
            <label>Nama pembayar<input class="input" name="payer_name" maxlength="150" value="{{ old('payer_name', $order->customer_snapshot['name']) }}" required></label>
            <label>Referensi transfer / penerimaan<input class="input" name="reference" maxlength="150" value="{{ old('reference') }}"><span class="muted">Wajib untuk transfer bank, minimal 3 karakter.</span></label>
            <label>Bukti pembayaran<input class="input" type="file" name="proof" accept="image/jpeg,image/png,application/pdf"><span class="muted">JPG, PNG, atau PDF maksimal 5 MB. Wajib untuk transfer; tersimpan privat.</span></label>
            <label class="laundry-wide">Catatan pembayaran<textarea class="input" name="notes" maxlength="1500">{{ old('notes') }}</textarea></label>
            <label class="laundry-check laundry-wide"><input type="checkbox" name="confirmed" value="1" required> Saya sudah memeriksa dan memastikan pembayaran ini benar-benar diterima.</label>
            <button class="button button-primary">Simpan Pembayaran & Kuitansi</button>
        </form>
    </details>
    @endif
    @if($order->payments->isNotEmpty())
    <h3>Riwayat pembayaran</h3><table class="table-card"><thead><tr><th>Waktu / pembayar</th><th>Nominal / metode</th><th>Referensi / catatan</th><th>Bukti & kuitansi</th><th>Koreksi</th></tr></thead><tbody>
        @foreach($order->payments->sortByDesc('id') as $payment)<tr>
            <td data-label="Waktu / pembayar">{{ $payment->paid_at->format('d M Y H:i') }} WIB<br>{{ $payment->payer_name }}<br><span class="muted">Dicatat {{ $payment->creator?->name ?: 'Admin terdahulu' }}</span></td>
            <td data-label="Nominal / metode"><strong>Rp {{ number_format($payment->amount, 0, ',', '.') }}</strong><br>{{ \App\Models\LaundryPayment::METHODS[$payment->method] }}<br>{{ $payment->voided_at ? 'DIKOREKSI — tidak dihitung' : 'Pembayaran tercatat' }}</td>
            <td data-label="Referensi / catatan">{{ $payment->reference }}<p class="laundry-preserve">{{ $payment->notes }}</p>@if($payment->voided_at)<p class="laundry-preserve">Koreksi {{ $payment->voided_at->format('d M Y H:i') }} WIB: {{ $payment->void_reason }}</p> @endif</td>
            <td data-label="Bukti & kuitansi">@if($payment->proof_path)<a href="{{ route('admin.laundry.payments.proof', [$order, $payment]) }}">Unduh bukti privat</a><br> @endif @if($payment->document)<a href="{{ route('admin.laundry.documents.show', [$order, $payment->document]) }}" target="_blank" rel="noopener">{{ $payment->document->number }}</a> @endif</td>
            <td data-label="Koreksi">@if(!$payment->voided_at && $order->status !== 'cancelled')<details><summary>Koreksi pencatatan</summary><form class="laundry-form" method="POST" action="{{ route('admin.laundry.payments.void', [$order, $payment]) }}">@csrf<input type="hidden" name="revision" value="{{ $order->revision }}"><label>Alasan koreksi<textarea class="input" name="reason" minlength="5" maxlength="1500" required></textarea></label><label class="laundry-check"><input type="checkbox" name="confirmed" value="1" required> Batalkan pencatatan dan kuitansinya. Tindakan ini tidak mengembalikan uang.</label><button class="button button-ghost">Simpan Koreksi Pembayaran</button></form></details> @else — @endif</td>
        </tr>@endforeach
    </tbody></table>
    @endif
    <h3>Invoice dan kuitansi</h3>
    @if($order->status === 'approved')
    <form method="POST" action="{{ route('admin.laundry.documents.invoice', $order) }}">@csrf<input type="hidden" name="revision" value="{{ $order->revision }}"><button class="button button-secondary">{{ $order->documents->where('type', 'invoice')->whereNull('voided_at')->isNotEmpty() ? 'Buka Invoice Aktif' : 'Terbitkan Invoice' }}</button></form>
    @endif
    <p class="muted">Dokumen menyimpan rincian dan saldo pada saat terbit. Kuitansi dibuat untuk setiap pembayaran yang tercatat. Nomor dokumen dan riwayat pembatalannya tetap disimpan.</p>
    @forelse($order->documents->sortByDesc('id') as $document)<article class="laundry-history">
        <a href="{{ route('admin.laundry.documents.show', [$order, $document]) }}" target="_blank" rel="noopener"><strong>{{ $document->number }}</strong> — {{ \App\Models\LaundryDocument::TYPES[$document->type] }}</a>
        <p>{{ $document->issued_at->format('d M Y H:i') }} WIB · {{ $document->voided_at ? 'DIBATALKAN' : 'Aktif' }}</p>
        @if($document->voided_at)<p class="laundry-preserve">{{ $document->void_reason }}</p>
        @elseif($document->type === 'invoice' && $order->status !== 'cancelled')
            <details><summary>Batalkan invoice ini</summary><form class="laundry-form" method="POST" action="{{ route('admin.laundry.documents.void', [$order, $document]) }}">@csrf<input type="hidden" name="revision" value="{{ $order->revision }}"><label>Alasan pembatalan invoice<textarea class="input" name="reason" minlength="5" maxlength="1500" required></textarea></label><label class="laundry-check"><input type="checkbox" name="confirmed" value="1" required> Invoice dibatalkan dan dipertahankan sebagai riwayat.</label><button class="button button-ghost">Batalkan Invoice</button></form></details>
        @endif
    </article>@empty<p class="muted">Belum ada dokumen keuangan yang diterbitkan.</p>@endforelse
</div></section>
