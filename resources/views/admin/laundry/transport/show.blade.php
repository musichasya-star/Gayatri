@extends('layouts.app')
@section('title', 'Kunjungan Laundry #'.$task->id)
@section('content')
<x-ui.page-header eyebrow="Laundry Ibu & Bayi" title="{{ \App\Models\LaundryTransportTask::KINDS[$task->kind] }} #{{ $task->id }}" description="Jadwal, ongkir tersimpan, bukti, dan riwayat kunjungan laundry." />
@include('admin.laundry.nav')
<div class="laundry-grid"><section class="card"><div class="card-body">
    <span class="badge badge-gold">{{ \App\Models\LaundryTransportTask::STATUSES[$task->status] }}</span> @if($task->is_demo)<span class="badge badge-gold">DEMO</span> @endif
    <h2>{{ $task->customer_snapshot['name'] }}</h2><p>{{ $task->customer_snapshot['whatsapp_number'] ?: $task->customer_snapshot['phone'] }}</p>
    <p>{{ $task->scheduled_start->format('d M Y H:i') }}–{{ $task->scheduled_end->format('H:i') }} WIB<br>Petugas: {{ $task->courier->name }} · {{ $task->courier->phone }}</p><p class="laundry-preserve">{{ $task->address }}</p>
    @if($task->latitude !== null && $task->longitude !== null)<a href="https://www.google.com/maps?q={{ $task->latitude }},{{ $task->longitude }}" target="_blank" rel="noopener noreferrer">Buka titik lokasi di peta</a> @endif
    <p class="laundry-preserve">{{ $task->notes }}</p>
    @if($task->order)<a class="button button-secondary" href="{{ route('admin.laundry.orders.show', $task->order) }}">{{ $task->order->code }}</a> @else<p class="muted">Belum terhubung ke pesanan penerimaan. Setelah barang sampai, buat penerimaan melalui penjemputan untuk pelanggan yang sama, lalu hubungkan di halaman ini.</p><a class="button button-secondary" href="{{ route('admin.laundry.orders.create') }}">Buat Penerimaan Laundry</a> @endif
</div></section>
<section class="card"><div class="card-body"><h2>Kesepakatan ongkir</h2><div class="laundry-values"><div>Ongkir saat dijadwalkan<strong>Rp {{ number_format($task->quote_snapshot['fee'],0,',','.') }}</strong></div></div>
    <p>Jarak {{ number_format($task->quote_snapshot['distance_milli']/1000,1,',','.') }} km · {{ $task->quote_snapshot['distance_source'] === 'manual' ? 'Jarak jalan manual' : 'Garis lurus dari koordinat' }}</p><p>{{ $task->quote_snapshot['distance_note'] }}</p>
    <p>Disetujui {{ $task->quote_snapshot['approved_name'] }}<br>{{ $task->quote_snapshot['approval_reference'] }}</p>
    @if($task->order)<p>Ongkir jenis ini pada tagihan sekarang: <strong>Rp {{ number_format($task->order->{$task->kind.'_fee'},0,',','.') }}</strong></p> @endif
    <p class="laundry-notice">Penjadwalan ulang mempertahankan ongkir. Kunjungan gagal/batal tidak otomatis menghapus kesepakatan biaya. Pembebasan ongkir dicatat pada pesanan sebelum invoice/pembayaran.</p>
    @if($task->completed_at)<p>Selesai {{ $task->completed_at->format('d M Y H:i') }} WIB · {{ $task->receiver_name }}</p> @endif
    <p class="laundry-preserve">{{ $task->outcome_notes }}</p>@if($task->proof_path)<a href="{{ route('admin.laundry.transport.proof', $task) }}">Unduh bukti kunjungan privat</a> @endif
</div></section></div>
@if($task->status === 'planned')
<section class="card" style="margin-top:1rem"><div class="card-body"><h2>Mulai perjalanan</h2><p>Pengantaran berangkat setelah barang siap, QC lulus, dan tagihan termasuk ongkir lunas.</p><form method="POST" action="{{ route('admin.laundry.transport.action', $task) }}">@csrf<input type="hidden" name="revision" value="{{ $task->revision }}"><input type="hidden" name="action" value="start"><button class="button button-primary">Catat Petugas Berangkat</button></form></div></section>
@endif
@if($task->status === 'in_transit')
<section class="card" style="margin-top:1rem"><div class="card-body"><h2>Selesaikan kunjungan</h2>
<form class="laundry-form" method="POST" enctype="multipart/form-data" action="{{ route('admin.laundry.transport.action', $task) }}">@csrf<input type="hidden" name="revision" value="{{ $task->revision }}"><input type="hidden" name="action" value="complete">
    <div class="laundry-grid"><label>Nama penerima barang<input class="input" name="receiver_name" maxlength="150" required></label><label>Telepon penerima (opsional)<input class="input" name="receiver_phone" maxlength="30"></label></div>
    @if($task->kind === 'pickup')<label>Jumlah potong diterima dari pelanggan<input class="input" name="pieces" type="number" min="1" max="200000" step="1" required></label><p class="muted">Jumlah rinci dan penimbangan tetap diperiksa pada penerimaan di outlet.</p>
    @else
        @foreach($task->order->items as $index => $item)<fieldset class="laundry-bag"><legend>{{ $item->bag_label }} · {{ $item->bag_code }}</legend><input type="hidden" name="items[{{ $index }}][id]" value="{{ $item->id }}"><label>Jumlah potong diserahkan (harus {{ $item->actual_pieces }})<input class="input" type="number" name="items[{{ $index }}][returned_pieces]" value="{{ $item->actual_pieces }}" min="1" max="10000" step="1" required></label><label class="laundry-check"><input type="checkbox" name="items[{{ $index }}][label_confirmed]" value="1" required> Label kantong dan jumlah barang cocok dengan penerima pesanan ini.</label></fieldset>@endforeach
    @endif
    <label>Foto bukti kunjungan (opsional)<input class="input" type="file" name="proof" accept="image/jpeg,image/png"><span class="muted">JPG/PNG maksimal 5 MB. Disimpan privat.</span></label>
    <label>Catatan penyelesaian<textarea class="input" name="notes" minlength="5" maxlength="1500" required></textarea></label><label class="laundry-check"><input type="checkbox" name="confirmed" value="1" required> Barang benar-benar telah diterima dan nama penerimanya sesuai catatan.</label>
    <button class="button button-primary">{{ $task->kind === 'delivery' ? 'Selesaikan Pengantaran & Serah Terima' : 'Selesaikan Penjemputan' }}</button>
</form>
<details class="laundry-history"><summary>Kunjungan gagal</summary><form class="laundry-form" method="POST" action="{{ route('admin.laundry.transport.action', $task) }}">@csrf<input type="hidden" name="revision" value="{{ $task->revision }}"><input type="hidden" name="action" value="fail"><label>Alasan kunjungan gagal<textarea class="input" name="notes" minlength="5" maxlength="1500" required></textarea></label><button class="button button-ghost">Catat Kunjungan Gagal</button></form></details>
</div></section>
@endif
@if(in_array($task->status, ['planned','failed']))
<section class="card" style="margin-top:1rem"><div class="card-body"><h2>Penjadwalan ulang / ganti petugas</h2><form class="laundry-form laundry-grid" method="POST" action="{{ route('admin.laundry.transport.reschedule', $task) }}">@csrf<input type="hidden" name="revision" value="{{ $task->revision }}"><label>Mulai<input class="input" name="scheduled_start" type="datetime-local" value="{{ $task->scheduled_start->format('Y-m-d\TH:i') }}" required></label><label>Selesai<input class="input" name="scheduled_end" type="datetime-local" value="{{ $task->scheduled_end->format('Y-m-d\TH:i') }}" required></label><label>Petugas<select class="input" name="laundry_courier_id" required>@foreach($couriers as $courier)<option value="{{ $courier->id }}" @selected($task->laundry_courier_id === $courier->id)>{{ $courier->name }}</option>@endforeach</select></label><label>Alasan perubahan<textarea class="input" name="reason" minlength="5" maxlength="1500" required></textarea></label><button class="button button-secondary">Simpan Jadwal Ulang</button></form>
<details class="laundry-history"><summary>Batalkan kunjungan</summary><form class="laundry-form" method="POST" action="{{ route('admin.laundry.transport.action', $task) }}">@csrf<input type="hidden" name="revision" value="{{ $task->revision }}"><input type="hidden" name="action" value="cancel"><label>Alasan pembatalan<textarea class="input" name="notes" minlength="5" maxlength="1500" required></textarea></label><button class="button button-ghost">Batalkan Jadwal Kunjungan</button></form></details>
</div></section>
@endif
@if($task->kind === 'pickup' && $task->status === 'completed' && !$task->laundry_order_id)
<section class="card" style="margin-top:1rem"><div class="card-body"><h2>Hubungkan penjemputan ke penerimaan</h2><form class="laundry-form" data-laundry-attach method="POST" action="{{ route('admin.laundry.transport.attach', $task) }}">@csrf<input type="hidden" name="revision" value="{{ $task->revision }}"><input type="hidden" name="order_revision"><label>Pesanan pelanggan ini<select class="input" name="laundry_order_id" required><option value="">Pilih penerimaan melalui penjemputan</option>@foreach($orders as $order)<option value="{{ $order->id }}" data-order-revision="{{ $order->revision }}">{{ $order->code }} · {{ $order->received_at->format('d M Y H:i') }}</option>@endforeach</select></label><label class="laundry-check"><input type="checkbox" name="confirmed" value="1" required> Barang dari kunjungan ini sesuai penerimaan tersebut; ongkir dimasukkan satu kali ke tagihannya.</label><button class="button button-primary">Hubungkan & Masukkan Ongkir</button></form></div></section>
@endif
<section class="card" style="margin-top:1rem"><div class="card-body"><h2>Riwayat kunjungan</h2>@foreach(array_reverse($task->history) as $entry)<article class="laundry-history"><strong>{{ \App\Models\LaundryTransportTask::STATUSES[$entry['status']] }}</strong> · {{ \Carbon\Carbon::parse($entry['at'])->timezone(config('app.timezone'))->format('d M Y H:i') }} WIB · {{ $entry['actor'] }}<p>{{ $entry['courier'] }} · {{ \Carbon\Carbon::parse($entry['scheduled_start'])->format('d M Y H:i') }}–{{ \Carbon\Carbon::parse($entry['scheduled_end'])->format('H:i') }} WIB</p><p class="laundry-preserve">{{ $entry['details']['notes'] ?? '' }}</p>@if($entry['details']['pieces'] ?? null)<p>Diterima {{ $entry['details']['pieces'] }} potong</p> @endif</article>@endforeach</div></section>
@endsection
@push('scripts')<script src="{{ asset('js/laundry.js') }}" defer></script>@endpush
