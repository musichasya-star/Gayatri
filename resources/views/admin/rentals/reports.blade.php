@extends('layouts.app')
@section('title', request()->routeIs('admin.rentals.dashboard') ? 'Dashboard Rental - Gayatri' : 'Laporan Rental - Gayatri')
@section('content')
@include('partials.admin-workbench-assets')
    @php
        $dashboard = request()->routeIs('admin.rentals.dashboard');
        $money = fn ($cents) => 'Rp '.number_format($cents / 100, $cents % 100 ? 2 : 0, ',', '.');
        $filters = ['start_date'=>$start->toDateString(), 'end_date'=>$end->toDateString(), 'include_demo'=>$includeDemo ? 1 : 0];
    @endphp
    <x-ui.page-header eyebrow="Rental Perlengkapan" title="{{ $dashboard ? 'Dashboard Rental' : 'Laporan Rental' }}" description="Pantau transaksi, penerimaan dana, deposit, biaya perawatan, serta pemakaian alat." />
    @include('admin.rentals.partials.navigation', ['compactNavigation'=>true])
    <nav class="wb-tabs" aria-label="Ringkasan atau rincian Rental"><a class="button button-secondary" href="{{ route($dashboard ? 'admin.rentals.reports' : 'admin.rentals.dashboard',$filters) }}">{{ $dashboard ? 'Buka laporan rinci' : 'Buka dashboard' }}</a></nav>
    <form method="GET" class="card"><div class="card-body rental-form rental-report-filters">
        <label>Periode mulai<input class="input" type="date" name="start_date" value="{{ $start->toDateString() }}" max="{{ today()->toDateString() }}" required></label>
        <label>Periode akhir<input class="input" type="date" name="end_date" value="{{ $end->toDateString() }}" max="{{ today()->toDateString() }}" required></label>
        <label class="rental-check"><input type="checkbox" name="include_demo" value="1" @checked($includeDemo)> Sertakan data demo</label><button class="button button-primary">Tampilkan Laporan</button>
    </div></form>
    @if($includeDemo)<p class="rental-alert">Data demo disertakan untuk peninjauan. Transaksi simulasi dapat memengaruhi seluruh angka yang ditampilkan.</p>@endif
    @if($dashboard)
    <div class="wb-heading"><div><h2>Perlu ditindaklanjuti sekarang</h2><p>Semua tanggal, mengikuti pilihan data demo. Periode laporan berada di bawah.</p></div></div>
    <div class="wb-tasks">
    @foreach([['Bukti transfer',$report['current']['pending_proofs'],route('admin.rentals.payment-reviews')],['Pembatalan & refund',$report['current']['pending_requests'],route('admin.rentals.customer-requests.index')],['Terlambat kembali',$report['current']['overdue_orders'],route('admin.rentals.operations')]] as [$label,$count,$url])<a class="wb-task" href="{{ $url }}"><span>{{ $label }}</span><strong>{{ $count }}</strong><small>Buka daftar untuk meninjau dan menindaklanjuti.</small></a>@endforeach
    </div>
    @endif
    <h2>Ringkasan periode {{ $start->format('d M Y') }} – {{ $end->format('d M Y') }}</h2>
    <div class="rental-report-cards">
        @foreach(['paid'=>'Dana masuk tercatat','refunds'=>'Refund tercatat','net'=>'Penerimaan bersih','charges'=>'Nilai tagihan jasa','care'=>'Biaya perawatan tercatat'] as $key=>$label)<article class="card"><div class="card-body"><p class="muted">{{ $label }}</p><strong class="rental-report-number">{{ $money($report['period'][$key]) }}</strong></div></article>@endforeach
        <article class="card"><div class="card-body"><p class="muted">Pesanan mulai periode</p><strong class="rental-report-number">{{ $report['period']['orders'] }}</strong><p class="muted rental-small">{{ $report['period']['expired'] }} permintaan kedaluwarsa.</p></div></article>
    </div>
    <p class="muted rental-small">Dana masuk dan refund memakai tanggal pencatatan buku kas (WIB), termasuk deposit. Nilai tagihan jasa memakai tanggal mulai pesanan dan status terkini: sewa, ongkir, denda, kerusakan, atau potongan pembatalan; permintaan pending dikecualikan. Biaya perawatan mengikuti tanggal pekerjaan. Bukti transfer yang belum diverifikasi belum menjadi dana masuk.</p>
<details class="ops-section"><summary>Tren penerimaan & refund bulanan</summary><div class="ops-section-body"><h2>Penerimaan & refund bulanan</h2>
        @php($peak = max(1, ...array_map(fn($m)=>max($m['paid'],$m['refunds']),$report['months'])))
        <div class="rental-report-chart" role="img" aria-label="Grafik penerimaan dan refund tercatat per bulan; angka lengkap pada tabel di bawah.">
            @foreach($report['months'] as $month)<div class="rental-report-column"><div class="rental-report-bars"><span class="rental-report-bar rental-report-paid" style="height:{{ $month['paid'] * 100 / $peak }}%" title="Dana masuk {{ $money($month['paid']) }}"></span><span class="rental-report-bar rental-report-refund" style="height:{{ $month['refunds'] * 100 / $peak }}%" title="Refund {{ $money($month['refunds']) }}"></span></div><small>{{ $month['month'] }}</small></div>@endforeach
        </div><p class="muted rental-small">● Hijau: dana masuk · ● Cokelat: refund. Angka mencakup deposit.</p>
        <div class="rental-scroll"><table class="rental-report-table"><thead><tr><th>Bulan</th><th>Dana masuk</th><th>Refund</th><th>Bersih</th><th>Biaya perawatan</th><th>Tagihan jasa</th></tr></thead><tbody>
            @foreach($report['months'] as $month)<tr><th>{{ $month['month'] }}</th>@foreach(['paid','refunds','net','care','charges'] as $key)<td>{{ $money($month[$key]) }}</td>@endforeach</tr>@endforeach
        </tbody></table></div>
</div></details>
<details class="ops-section" @if(!$dashboard) open @endif><summary>Saldo, deposit & operasional saat ini</summary><div class="ops-section-body"><h2>Saldo & operasional saat ini</h2><p class="muted rental-small">Posisi saat halaman dibuka, {{ now()->format('d M Y H:i') }} WIB. Bagian ini mencakup seluruh periode transaksi sesuai pilihan data demo.</p>
    <div class="rental-report-cards">@foreach(['balance'=>'Sisa tagihan termasuk deposit','deposit_held'=>'Deposit ditahan pada sewa aktif','refundable'=>'Saldo siap dikembalikan','approved_refunds'=>'Refund disetujui belum dicatat'] as $key=>$label)<article class="card"><div class="card-body"><p class="muted">{{ $label }}</p><strong class="rental-report-number">{{ $money($report['current'][$key]) }}</strong></div></article>@endforeach</div>
    <p class="muted rental-small">Deposit ditahan adalah dana bersih di atas tagihan jasa pada sewa aktif, maksimal deposit pesanan. Saldo siap dikembalikan tersedia setelah selesai/dibatalkan. Refund disetujui merupakan bagian dari saldo pengembalian tersebut.</p>
    <div class="rental-grid"><section class="card"><div class="card-body"><h3>Perlu ditindaklanjuti</h3><p><a href="{{ route('admin.rentals.payment-reviews') }}">{{ $report['current']['pending_proofs'] }} bukti transfer menunggu verifikasi</a></p><p><a href="{{ route('admin.rentals.customer-requests.index') }}">{{ $report['current']['pending_requests'] }} pengajuan pembatalan/refund menunggu keputusan</a></p><p><a href="{{ route('admin.rentals.operations') }}">{{ $report['current']['overdue_orders'] }} pesanan terlambat kembali</a></p></div></section>
        <section class="card"><div class="card-body"><h3>Status unit saat ini</h3><div class="rental-facts">@foreach(\App\Models\RentalUnit::STATUSES as $key=>$label)<div>{{ $label }}<strong>{{ $report['unit_counts'][$key] ?? 0 }}</strong></div>@endforeach</div></div></section>
    </div>
</div></details>
    <section class="card"><div class="card-body"><h2>Tagihan & saldo pengembalian terbesar</h2><p class="muted rental-small">{{ $dashboard ? 5 : 10 }} teratas. Ekspor Saldo Saat Ini memuat seluruh pesanan yang memiliki tagihan, deposit ditahan, atau saldo pengembalian.</p>
        <div class="rental-scroll"><table class="rental-report-table"><thead><tr><th>Pesanan</th><th>Pelanggan</th><th>Sisa tagihan</th><th>Siap dikembalikan</th></tr></thead><tbody>@forelse(array_slice($report['attention'],0,$dashboard ? 5 : 10) as $row)<tr><td><a href="{{ route('admin.rentals.orders.show',$row['id']) }}">{{ $row['code'] }}</a></td><td>{{ $row['name'] }}</td><td>{{ $money($row['balance']) }}</td><td>{{ $money($row['refundable']) }}</td></tr>@empty<tr><td colspan="4">Tidak ada saldo yang perlu ditindaklanjuti.</td></tr>@endforelse</tbody></table></div>
    </div></section>
<details class="ops-section" @if(!$dashboard) open @endif><summary>Produk & pemakaian unit pada periode</summary><div class="ops-section-body">    <section class="card"><div class="card-body"><h2>Produk & pemakaian unit</h2><p class="muted rental-small">Nilai sewa mengikuti pesanan terkonfirmasi yang mulai pada periode terpilih. Hari terpakai dihitung dari serah-terima sampai pengembalian aktual, dipotong sesuai periode. Indikasi utilisasi membandingkan hari terpakai dengan hari kalender unit sejak tercatat, termasuk unit arsip; waktu perawatan belum dikurangi.</p>
        <div class="rental-scroll"><table class="rental-report-table"><thead><tr><th>Produk</th><th>Unit tercatat</th><th>Unit dalam pesanan</th><th>Nilai sewa</th><th>Denda / kerusakan</th><th>Hari terpakai / kapasitas</th><th>Indikasi utilisasi</th></tr></thead><tbody>
            @forelse($dashboard ? array_slice($report['products'],0,5) : $report['products'] as $product)<tr><td>{{ $product['name'] }}<br><small>{{ $product['sku'] }}</small></td><td>{{ $product['units'] }}</td><td>{{ $product['rented_units'] }}</td><td>{{ $money($product['rental']) }}</td><td>{{ $money($product['late_fee']+$product['damage_fee']) }}</td><td>{{ $product['used_days'] }} / {{ $product['capacity_days'] }}</td><td>{{ $product['utilization'] !== null ? number_format($product['utilization'],1,',','.').'%' : '—' }}</td></tr>@empty<tr><td colspan="7">Belum ada data unit/penyewaan pada pilihan ini.</td></tr>@endforelse
        </tbody></table></div>@if($dashboard)<p><a href="{{ route('admin.rentals.reports',$filters) }}">Buka seluruh rincian laporan</a></p>@endif
    </div></section>
</div></details>
<details class="ops-section"><summary>Ekspor laporan CSV · mengikuti periode & pilihan demo</summary><div class="ops-section-body">    <section class="card"><div class="card-body"><h2>Ekspor laporan CSV</h2><p class="muted rental-small">Dapat dibuka di Excel. Ekspor Saldo Saat Ini memakai posisi terbaru; ekspor lainnya mengikuti periode terpilih.</p><div class="rental-actions">
        @foreach(['monthly'=>'Ringkasan Bulanan','orders'=>'Transaksi Sewa','cash'=>'Buku Kas','care'=>'Biaya Perawatan','products'=>'Produk & Pemakaian','balances'=>'Saldo Saat Ini'] as $type=>$label)<a class="button button-secondary" href="{{ route('admin.rentals.reports.export',[...$filters,'type'=>$type]) }}">{{ $label }}</a>@endforeach
    </div></div></section>
</div></details>
    @if(!$dashboard)<section class="card"><div class="card-body"><h2>Pesanan mulai periode terpilih</h2><p class="muted rental-small">Status dan saldo mengikuti posisi saat ini. Pending belum termasuk tagihan jasa.</p><div class="rental-scroll"><table class="rental-report-table"><thead><tr><th>Pesanan</th><th>Pelanggan</th><th>Status</th><th>Mulai – kembali</th><th>Tagihan jasa</th><th>Diterima bersih</th><th>Sisa tagihan</th></tr></thead><tbody>
        @forelse($orders as $order)@php($f=$reports->figures($order))<tr><td><a href="{{ route('admin.rentals.orders.show',$order) }}">{{ $order->code }}</a>@if($order->is_demo)<br><small>DEMO</small>@endif</td><td>{{ $order->customer_snapshot['name'] ?? $order->customer?->name }}</td><td>{{ $order->isExpired() ? 'Permintaan kedaluwarsa' : \App\Models\RentalOrder::STATUSES[$order->status] }}</td><td>{{ $order->start_date->format('d M Y') }} – {{ $order->end_date->format('d M Y') }}</td><td>{{ $money($f['charges']) }}</td><td>{{ $money($f['net_received']) }}</td><td>{{ $money($f['balance']) }}</td></tr>@empty<tr><td colspan="7">Tidak ada pesanan pada periode ini.</td></tr>@endforelse
    </tbody></table></div>{{ $orders->links() }}</div></section>@endif
@endsection
