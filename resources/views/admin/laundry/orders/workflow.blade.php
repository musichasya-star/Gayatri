<section class="card" style="margin-top:1rem" id="pengerjaan"><div class="card-body">
    <h2 class="section-title">Pengerjaan, kualitas & serah terima</h2>
    <p><strong class="badge {{ in_array($order->workflow_status, ['ready', 'handed_over']) ? 'badge-green' : 'badge-gold' }}">{{ \App\Models\LaundryOrder::WORKFLOW[$order->workflow_status] }}</strong></p>
    @if($order->deadlineWarning())<p class="laundry-alert {{ $order->estimated_ready_at->isPast() ? 'laundry-error' : '' }}">{{ $order->deadlineWarning() }} · {{ $order->estimated_ready_at->format('d M Y H:i') }} WIB</p> @endif
    @if($order->process_steps)
        <ol class="laundry-progress"><li @class(['laundry-current' => $order->workflow_status === 'queued'])>Antrean</li>@foreach($order->process_steps as $step)<li @class(['laundry-current' => $order->workflow_status === $step])>{{ \App\Models\LaundryOrder::STEPS[$step] }}</li>@endforeach<li @class(['laundry-current' => $order->workflow_status === 'quality_check'])>QC</li><li @class(['laundry-current' => $order->workflow_status === 'ready'])>Siap diambil</li><li @class(['laundry-current' => $order->workflow_status === 'handed_over'])>Diserahkan</li></ol>
        <p class="muted">Rencana tersimpan sejak {{ $order->processing_started_at->format('d M Y H:i') }} WIB. Tahap dan petugas tercatat dalam riwayat.</p>
    @endif
    @if($order->status === 'approved' && $order->workflow_status === 'pending')
        <form class="laundry-form" method="POST" action="{{ route('admin.laundry.orders.start', $order) }}">@csrf
            <input type="hidden" name="revision" value="{{ $order->revision }}">
            <p>Pilih tahapan sesuai layanan. Urutan mengikuti daftar ini. Kemas wajib, proses cuci diikuti pengeringan. Tarif dan hasil timbang dikunci setelah masuk antrean.</p>
            <div class="laundry-grid">@foreach(\App\Models\LaundryOrder::STEPS as $step => $label)<label class="laundry-check"><input type="checkbox" name="steps[]" value="{{ $step }}" @checked(in_array($step, old('steps', array_keys(\App\Models\LaundryOrder::STEPS)), true))> {{ $label }}</label>@endforeach</div>
            <label>Catatan pengerjaan<textarea class="input" name="notes" maxlength="1500">{{ old('notes') }}</textarea></label>
            <button class="button button-primary">Masukkan ke Antrean Pengerjaan</button>
        </form>
    @elseif($nextStage)
        <form class="laundry-form" method="POST" action="{{ route('admin.laundry.orders.advance', $order) }}">@csrf
            <input type="hidden" name="revision" value="{{ $order->revision }}"><input type="hidden" name="next_stage" value="{{ $nextStage }}">
            <label>Catatan tahap / petugas<textarea class="input" name="notes" maxlength="1500">{{ old('notes') }}</textarea></label>
            <p class="muted">Petugas pencatat: {{ auth()->user()->name }}. Pastikan tahap sebelumnya sudah selesai sebelum melanjutkan.</p>
            <button class="button button-primary">Lanjut ke {{ \App\Models\LaundryOrder::WORKFLOW[$nextStage] }}</button>
        </form>
    @elseif($order->workflow_status === 'quality_check')
        <form class="laundry-form" data-laundry-quality method="POST" action="{{ route('admin.laundry.orders.quality', $order) }}">@csrf<input type="hidden" name="revision" value="{{ $order->revision }}">
            <p>Periksa setiap kantong secara langsung. Jumlah mengikuti hasil timbang yang disetujui. Seluruh checklist dan jumlah harus sesuai untuk lulus.</p>
            @foreach($order->items as $index => $item)<fieldset class="laundry-bag"><legend>{{ $item->bag_label }} · {{ $item->bag_code }}</legend>
                <input type="hidden" name="items[{{ $index }}][id]" value="{{ $item->id }}">
                <p>Jumlah yang harus lengkap: <strong>{{ $item->actual_pieces }} potong</strong>. Kondisi saat diterima: {{ $item->condition_notes }}</p>
                <label>Jumlah potong diperiksa<input class="input" name="items[{{ $index }}][checked_pieces]" type="number" min="0" max="10000" step="1" value="{{ old('items.'.$index.'.checked_pieces', $item->actual_pieces) }}" required></label>
                <div class="laundry-grid">@foreach(\App\Models\LaundryQualityCheck::CHECKS as $key => $label)<label class="laundry-check"><input type="hidden" name="items[{{ $index }}][{{ $key }}]" value="0"><input type="checkbox" name="items[{{ $index }}][{{ $key }}]" value="1" @checked(old('items.'.$index.'.'.$key, false))> {{ $label }}</label>@endforeach</div>
                <label>Catatan pemeriksaan kantong<textarea class="input" name="items[{{ $index }}][notes]" maxlength="1500">{{ old('items.'.$index.'.notes') }}</textarea></label>
            </fieldset>@endforeach
            <div class="laundry-grid">
                <label>Hasil pemeriksaan<select class="input" name="result" required><option value="pass" @selected(old('result') === 'pass')>Lulus — siap diambil</option><option value="rework" @selected(old('result') === 'rework')>Perlu perbaikan / cuci ulang</option></select></label>
                <label>Ulangi dari tahap (jika perbaikan)<select class="input" name="restart_stage"><option value="">Pilih tahap perbaikan</option>@foreach($order->process_steps as $step)<option value="{{ $step }}" @selected(old('restart_stage') === $step)>{{ \App\Models\LaundryOrder::STEPS[$step] }}</option>@endforeach</select></label>
            </div>
            <label>Catatan hasil QC<textarea class="input" name="notes" maxlength="1500" placeholder="Wajib minimal 5 karakter jika perlu perbaikan.">{{ old('notes') }}</textarea></label>
            <button class="button button-primary">Simpan Pemeriksaan Kualitas</button>
        </form>
    @elseif($order->workflow_status === 'ready')
        @php($deliveryPending = $order->transportTasks->where('kind','delivery')->whereIn('status',['planned','in_transit','failed'])->isNotEmpty())
        @if($deliveryPending)<p class="laundry-alert">Ada pengantaran aktif. Buka kunjungan pada bagian Antar-Jemput untuk mencatat serah terima di alamat pelanggan.</p> @endif
        <p>QC lulus pada {{ $order->ready_at->format('d M Y H:i') }} WIB.</p>
        @if($order->balance() !== 0)<p class="laundry-alert laundry-error">Sisa pembayaran Rp {{ number_format($order->balance() ?? 0, 0, ',', '.') }}. Selesaikan pelunasan sebelum serah terima.</p> @endif
        <form class="laundry-form" method="POST" action="{{ route('admin.laundry.orders.handover', $order) }}">@csrf<input type="hidden" name="revision" value="{{ $order->revision }}">
            <div class="laundry-grid"><label>Nama penerima barang<input class="input" name="receiver_name" value="{{ old('receiver_name') }}" maxlength="150" required></label><label>Nomor telepon penerima (opsional)<input class="input" name="receiver_phone" value="{{ old('receiver_phone') }}" maxlength="30"></label></div>
            @foreach($order->items as $index => $item)<fieldset class="laundry-bag"><legend>{{ $item->bag_label }} · {{ $item->bag_code }}</legend><input type="hidden" name="items[{{ $index }}][id]" value="{{ $item->id }}">
                <label>Jumlah potong diserahkan (harus {{ $item->actual_pieces }})<input class="input" type="number" name="items[{{ $index }}][returned_pieces]" min="1" max="10000" step="1" value="{{ old('items.'.$index.'.returned_pieces', $item->actual_pieces) }}" required></label>
                <label class="laundry-check"><input type="checkbox" name="items[{{ $index }}][label_confirmed]" value="1" required> Label kantong dan barang sesuai penerima pesanan ini.</label>
            </fieldset>@endforeach
            <label>Catatan serah terima<textarea class="input" name="notes" maxlength="1500">{{ old('notes') }}</textarea></label>
            <label class="laundry-check"><input type="checkbox" name="confirmed" value="1" required> Barang benar-benar telah diterima oleh penerima yang dicatat.</label>
            <button class="button button-primary" @disabled($order->balance() !== 0 || $deliveryPending)>Catat Serah Terima Barang</button>
        </form>
    @elseif($order->workflow_status === 'handed_over')
        <p class="laundry-alert">Diserahkan kepada <strong>{{ $order->receiver_name }}</strong> pada {{ $order->handed_over_at->format('d M Y H:i') }} WIB.<br>{{ $order->receiver_phone }}<br>{{ $order->handover_notes }}</p>
        @if($order->balance() > 0)<p class="laundry-alert laundry-error">Ada sisa tagihan setelah koreksi pembayaran: Rp {{ number_format($order->balance(), 0, ',', '.') }}. Riwayat serah terima tetap tersimpan.</p> @endif
    @elseif($order->status !== 'cancelled')
        <p class="muted">Selesaikan penimbangan dan catat persetujuan pelanggan untuk memulai pengerjaan.</p>
    @endif
</div></section>
@if($order->qualityChecks->isNotEmpty())
<section class="card" style="margin-top:1rem"><div class="card-body"><h2 class="section-title">Riwayat pemeriksaan kualitas</h2>
    @foreach($order->qualityChecks->sortByDesc('id') as $check)<details class="laundry-history"><summary>{{ $check->passed ? 'QC LULUS' : 'PERLU PERBAIKAN' }} · {{ $check->created_at->format('d M Y H:i') }} WIB · {{ $check->actor?->name ?: 'Admin terdahulu' }}</summary>
        <p class="laundry-preserve">{{ $check->notes }}</p>@if($check->restart_stage)<p>Perbaikan dari {{ \App\Models\LaundryOrder::WORKFLOW[$check->restart_stage] }}</p> @endif
        @foreach($check->items as $line)<div class="laundry-notice"><strong>{{ $line['bag_label'] }} · {{ $line['bag_code'] }}</strong><p>Jumlah diperiksa {{ $line['checked_pieces'] }} / {{ $line['expected_pieces'] }} potong</p><ul>@foreach($line['checks'] as $key => $value)<li>{{ \App\Models\LaundryQualityCheck::CHECKS[$key] }}: {{ $value ? 'Sesuai' : 'Belum sesuai' }}</li>@endforeach</ul><p class="laundry-preserve">{{ $line['notes'] }}</p></div>@endforeach
    </details>@endforeach
</div></section>
@endif
