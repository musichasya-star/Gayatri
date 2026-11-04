@extends('layouts.app')

@section('title', ($knowledge->exists ? 'Edit Knowledge' : 'Tambah Knowledge') . ' - Gayatri CRM')

@section('content')
@include('partials.admin-workbench-assets')
    <x-ui.page-header eyebrow="AI Management" title="{{ $knowledge->exists ? 'Edit Knowledge' : 'Tambah Knowledge' }}" description="Isi hanya informasi resmi yang aman digunakan AI untuk menjawab customer.">
        <x-slot name="actions"><a class="button button-secondary" href="{{ \App\Support\AdminReviewContext::back(request(),'knowledge') }}"><i data-lucide="arrow-left"></i> Kembali</a></x-slot>
    </x-ui.page-header>
    @if ($errors->any())<div class="card" style="margin-bottom:1rem;border-color:rgba(201,122,106,.55);"><div class="card-body" style="color:#7a3228;">{{ $errors->first() }}</div></div>@endif
    @if($knowledge->is_demo)<div class="card" style="margin-bottom:1rem;"><div class="card-body">Contoh DEMO untuk simulator lokal. Konten ini tetap dikecualikan dari balasan WhatsApp pelanggan.</div></div>@endif
    <div class="card"><div class="card-body">
        <form data-guard-unsaved method="POST" enctype="multipart/form-data" action="{{ $knowledge->exists ? route('admin.ai.knowledge.update', $knowledge) : route('admin.ai.knowledge.store') }}" class="grid grid-2">
            @csrf @include('partials.admin-review-fields',['reviewModule'=>'knowledge'])
            @if($knowledge->exists)<input type="hidden" name="revision" value="{{ $knowledge->revision }}">@endif
            @if ($knowledge->exists) @method('PUT') @endif
            <div style="grid-column:1/-1;">
                <strong>Cakupan layanan</strong>
                <p class="muted">Knowledge lama perlu ditinjau. Hanya konten terbit, publik, aktif dan masih berlaku yang digunakan untuk FAQ pelanggan.</p>
                @foreach(\App\Support\AiDomain::LABELS as $domain => $label)
                    <label style="display:inline-flex;gap:.4rem;margin:.5rem 1rem .5rem 0;"><input type="checkbox" name="domains[]" value="{{ $domain }}" @checked(in_array($domain, old('domains', $knowledge->exists ? $knowledge->domains->pluck('domain')->all() : [])))>{{ $label }}</label>
                @endforeach
            </div>
            <label><span class="muted">Publikasi</span><select class="input" name="publication_status" required>
                @foreach(['draft'=>'Draft','needs_review'=>'Perlu ditinjau','published'=>'Terbit','archived'=>'Arsip'] as $value=>$label)
                    <option value="{{ $value }}" @selected(old('publication_status', $knowledge->publication_status ?? 'draft')===$value)>{{ $label }}</option>
                @endforeach
            </select><small class="muted">Penerbitan hanya oleh owner/manager. Admin dapat menyiapkan draft.</small></label>
            <label><span class="muted">Pembaca</span><select class="input" name="audience"><option value="customer_public" @selected(old('audience',$knowledge->audience)==='customer_public')>Publik pelanggan</option><option value="internal_only" @selected(old('audience',$knowledge->audience)==='internal_only')>Internal saja</option></select></label>
            <label style="grid-column:1/-1;"><span class="muted">Kata kunci FAQ (pisahkan dengan koma)</span><input class="input" name="keywords" maxlength="800" value="{{ old('keywords', implode(', ', (array) data_get($knowledge->meta,'keywords',[]))) }}"><small class="muted">Nominal harga diatur melalui katalog. Jangan masukkan data pelanggan, kredensial, atau tautan portal privat.</small></label>
            <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Judul</span><input class="input" name="title" value="{{ old('title', $knowledge->title) }}" required></label>
            <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Tipe</span><input class="input" name="type" value="{{ old('type', $knowledge->type ?? 'text') }}" required></label>
            <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Status</span><select class="input" name="status"><option value="active" @selected(old('status', $knowledge->status)==='active')>Aktif</option><option value="inactive" @selected(old('status', $knowledge->status)==='inactive')>Nonaktif</option></select></label>
            <div class="grid grid-2"><label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Mulai berlaku</span><input class="input" type="date" name="valid_from" value="{{ old('valid_from', $knowledge->valid_from?->format('Y-m-d')) }}"></label><label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Berlaku sampai</span><input class="input" type="date" name="valid_until" value="{{ old('valid_until', $knowledge->valid_until?->format('Y-m-d')) }}"></label></div>
            <label style="grid-column:1/-1;"><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Lampiran sumber</span><input class="input" type="file" name="file" accept=".txt,.md,.csv,.json"><small class="muted">Opsional. Maksimal 5 MB. Tipe: TXT, MD, CSV, JSON.</small></label>
            <label style="grid-column:1/-1;"><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Isi informasi</span><textarea class="input" rows="12" name="content">{{ old('content', $knowledge->content) }}</textarea></label>
            <div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:.75rem;"><a class="button button-secondary" href="{{ \App\Support\AdminReviewContext::back(request(),'knowledge') }}">Batal</a><button class="button button-primary" type="submit"><i data-lucide="save"></i> Simpan</button></div>
        </form>
    </div></div>
@endsection
