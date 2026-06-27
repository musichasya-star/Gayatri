@extends('layouts.app')

@section('title', ($knowledge->exists ? 'Edit Knowledge' : 'Tambah Knowledge') . ' - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="AI Management" title="{{ $knowledge->exists ? 'Edit Knowledge' : 'Tambah Knowledge' }}" description="Isi hanya informasi resmi yang aman digunakan AI untuk menjawab customer.">
        <x-slot name="actions"><a class="button button-secondary" href="{{ route('admin.ai.knowledge.index') }}"><i data-lucide="arrow-left"></i> Kembali</a></x-slot>
    </x-ui.page-header>
    @if ($errors->any())<div class="card" style="margin-bottom:1rem;border-color:rgba(201,122,106,.55);"><div class="card-body" style="color:#7a3228;">{{ $errors->first() }}</div></div>@endif
    <div class="card"><div class="card-body">
        <form method="POST" enctype="multipart/form-data" action="{{ $knowledge->exists ? route('admin.ai.knowledge.update', $knowledge) : route('admin.ai.knowledge.store') }}" class="grid grid-2">
            @csrf
            @if ($knowledge->exists) @method('PUT') @endif
            <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Judul</span><input class="input" name="title" value="{{ old('title', $knowledge->title) }}" required></label>
            <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Tipe</span><input class="input" name="type" value="{{ old('type', $knowledge->type ?? 'text') }}" required></label>
            <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Status</span><select class="input" name="status"><option value="active" @selected(old('status', $knowledge->status)==='active')>Active</option><option value="inactive" @selected(old('status', $knowledge->status)==='inactive')>Inactive</option></select></label>
            <div class="grid grid-2"><label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Valid From</span><input class="input" type="date" name="valid_from" value="{{ old('valid_from', $knowledge->valid_from?->format('Y-m-d')) }}"></label><label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Valid Until</span><input class="input" type="date" name="valid_until" value="{{ old('valid_until', $knowledge->valid_until?->format('Y-m-d')) }}"></label></div>
            <label style="grid-column:1/-1;"><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Upload File</span><input class="input" type="file" name="file" accept=".txt,.md,.csv,.json"><small class="muted">Opsional. Maksimal 5 MB. Tipe: TXT, MD, CSV, JSON.</small></label>
            <label style="grid-column:1/-1;"><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Content</span><textarea class="input" rows="12" name="content">{{ old('content', $knowledge->content) }}</textarea></label>
            <div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:.75rem;"><a class="button button-secondary" href="{{ route('admin.ai.knowledge.index') }}">Batal</a><button class="button button-primary" type="submit"><i data-lucide="save"></i> Simpan</button></div>
        </form>
    </div></div>
@endsection
