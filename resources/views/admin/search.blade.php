@extends('layouts.app')
@section('title', 'Pencarian - Gayatri CRM')
@section('content')
    <x-ui.page-header eyebrow="Pencarian Admin" title="Hasil pencarian" description="Cari nama pelanggan, kode transaksi, nama layanan, produk, atau campaign sesuai hak akses Anda." />
    @if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
    @if($term === '')
        <div class="card"><div class="card-body">Masukkan sedikitnya dua karakter pada pencarian di atas.</div></div>
    @else
        <p role="status">Hasil untuk <strong>“{{ $term }}”</strong>. Maksimal 6 hasil per jenis; tambahkan kata yang lebih spesifik bila diperlukan.</p>
        <div class="search-results">
            @forelse(collect($groups)->filter(fn ($rows) => $rows->isNotEmpty()) as $label => $rows)
                <section class="card"><div class="card-body"><h2 class="section-title">{{ $label }}</h2><ul>
                    @foreach($rows as $row)<li><a class="search-result" href="{{ $row['url'] }}"><strong>{{ $row['title'] }}</strong><small class="muted">{{ $row['detail'] }}</small></a></li>@endforeach
                </ul></div></section>
            @empty
                <div class="card"><div class="card-body"><h2 class="section-title">Belum ada hasil.</h2><p>Periksa ejaan atau gunakan nama maupun kode yang lebih singkat.</p></div></div>
            @endforelse
        </div>
    @endif
@endsection
