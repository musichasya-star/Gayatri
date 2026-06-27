@extends('layouts.app')

@section('title', ($promo->exists ? 'Edit Promo' : 'Tambah Promo') . ' - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="Promo" title="{{ $promo->exists ? 'Edit Promo' : 'Tambah Promo' }}" description="Promo aktif dan belum expired bisa dipakai pada booking dan campaign.">
        <x-slot name="actions"><a class="button button-secondary" href="{{ route('admin.promos.index') }}"><i data-lucide="arrow-left"></i> Kembali</a></x-slot>
    </x-ui.page-header>
    @if($errors->any())<div class="card" style="margin-bottom:1rem;border-color:rgba(201,122,106,.55);"><div class="card-body" style="color:#7a3228;">{{ $errors->first() }}</div></div>@endif
    <div class="card"><div class="card-body"><form method="POST" action="{{ $promo->exists ? route('admin.promos.update', $promo) : route('admin.promos.store') }}" class="grid grid-2">@csrf @if($promo->exists) @method('PUT') @endif
        <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Nama Promo</span><input class="input" name="title" value="{{ old('title', $promo->title) }}" required></label>
        <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Kode Voucher</span><input class="input" name="code" value="{{ old('code', $promo->code) }}" placeholder="GAYATRI10"></label>
        <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Tipe</span><select class="input" name="type" required><option value="fixed" @selected(old('type', $promo->type)==='fixed')>Nominal</option><option value="percent" @selected(old('type', $promo->type)==='percent')>Persen</option></select></label>
        <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Nilai</span><input class="input" type="number" step="0.01" min="0" name="value" value="{{ old('value', $promo->value ?? 0) }}" required></label>
        <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Kuota</span><input class="input" type="number" min="1" name="quota" value="{{ old('quota', $promo->quota) }}" placeholder="Kosongkan untuk unlimited"></label>
        <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Aktif</span><select class="input" name="is_active"><option value="1" @selected(old('is_active', $promo->is_active) == 1)>Aktif</option><option value="0" @selected(old('is_active', $promo->is_active) == 0)>Nonaktif</option></select></label>
        <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Mulai</span><input class="input" type="datetime-local" name="start_at" value="{{ old('start_at', $promo->start_at?->format('Y-m-d\TH:i')) }}"></label>
        <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Berakhir</span><input class="input" type="datetime-local" name="end_at" value="{{ old('end_at', $promo->end_at?->format('Y-m-d\TH:i')) }}"></label>
        <label style="grid-column:1/-1;"><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Deskripsi</span><textarea class="input" name="description" rows="4">{{ old('description', $promo->description) }}</textarea></label>
        <div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:.75rem;"><a class="button button-secondary" href="{{ route('admin.promos.index') }}">Batal</a><button class="button button-primary" type="submit"><i data-lucide="save"></i> Simpan</button></div>
    </form></div></div>
@endsection
