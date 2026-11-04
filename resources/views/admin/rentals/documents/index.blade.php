@extends('layouts.app')
@section('title', 'Invoice & Kuitansi Rental')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/rental-documents.css') }}">
@endpush
@section('content')
    <x-ui.page-header eyebrow="Arsip Transaksi Sewa" title="Invoice & Kuitansi" description="Data sesuai keadaan transaksi saat dokumen diterbitkan."><x-slot name="actions"><span class="badge badge-gold">{{ number_format($documents->total(), 0, ',', '.') }} dokumen terbit</span></x-slot></x-ui.page-header>
    @include('admin.rentals.partials.navigation')
    <section class="card rd-archive"><div class="card-body">
        <form class="rd-filters" action="{{ route('admin.rentals.documents.index') }}" method="GET" aria-label="Filter dokumen">
            <label>Kode pesanan<input name="order_code" value="{{ $filters['order_code'] ?? '' }}" placeholder="RENT-…" maxlength="40"></label>
            <label>ID pesanan<input name="order_id" type="number" min="1" value="{{ $filters['order_id'] ?? '' }}" placeholder="Semua pesanan"></label>
            <label>Jenis dokumen<select name="type"><option value="">Semua dokumen</option>@foreach(\App\Models\RentalDocument::TYPES as $type => $label)<option value="{{ $type }}" @selected(($filters['type'] ?? '') === $type)>{{ $label }}</option>@endforeach</select></label>
            <label>Terbit mulai<input name="from" type="date" value="{{ $filters['from'] ?? '' }}"></label>
            <label>Sampai tanggal<input name="to" type="date" value="{{ $filters['to'] ?? '' }}"></label>
            <div class="rd-filter-actions"><button class="rd-button" type="submit">Tampilkan</button><a class="rd-button rd-button-quiet" href="{{ route('admin.rentals.documents.index') }}">Reset</a></div>
        </form>
        <p class="muted" id="archive-scroll-help">Pada layar kecil, geser tabel untuk melihat semua kolom.</p>
        <div class="table-scroll" role="region" aria-label="Daftar invoice dan kuitansi rental" aria-describedby="archive-scroll-help" tabindex="0">
            <table class="rd-table rd-archive-table">
                <caption class="rd-sr-only">Daftar invoice dan kuitansi rental sesuai filter</caption>
                <thead><tr><th scope="col">Dokumen</th><th scope="col">Pesanan / pelanggan</th><th scope="col">Diterbitkan</th><th scope="col" class="rd-money">Nominal</th><th scope="col">Pratinjau</th></tr></thead>
                <tbody>
                    @forelse($documents as $document)
                        @php($saved = $document->snapshot)
                        <tr>
                            <td><span class="rd-type">{{ \App\Models\RentalDocument::TYPES[$document->type] }}</span><strong>{{ $document->number }}</strong>@if($document->version)<span class="rd-muted">Versi {{ $document->version }}</span>@endif</td>
                            <td><strong>{{ $saved['order']['code'] }}</strong><span>{{ $saved['customer']['name'] }}</span></td>
                            <td>{{ \Carbon\CarbonImmutable::parse($saved['issuance']['issued_at'])->locale('id')->translatedFormat('d M Y') }}<span class="rd-muted">{{ \Carbon\CarbonImmutable::parse($saved['issuance']['issued_at'])->format('H:i P') }}</span></td>
                            <td class="rd-money"><strong>Rp {{ number_format((float) ($saved['receipt']['amount'] ?? $saved['totals']['total_due']), 2, ',', '.') }}</strong><span class="rd-muted">{{ $document->type === \App\Models\RentalDocument::TYPE_INVOICE ? 'Total tagihan saat terbit' : ($document->type === \App\Models\RentalDocument::TYPE_REFUND_RECEIPT ? 'Dana dikembalikan' : 'Pembayaran diterima') }}</span></td>
                            <td><a class="rd-button rd-button-quiet" href="{{ route('admin.rentals.documents.show', ['order' => $document->rental_order_id, 'document' => $document->id]) }}">Lihat dokumen<span class="rd-sr-only"> {{ $document->number }}</span></a></td>
                        </tr>
                    @empty
                        <tr><td class="rd-empty" colspan="5"><strong>Belum ada dokumen sesuai filter.</strong><p>Terbitkan invoice atau kuitansi dari halaman transaksi sewa.</p></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $documents->links() }}
        <p class="rd-archive-note">Dokumen yang telah terbit tidak dapat diedit atau dihapus. Perubahan transaksi menghasilkan versi invoice berikutnya; satu pencatatan buku kas memiliki satu kuitansi tetap.</p>
    </div></section>
@endsection
