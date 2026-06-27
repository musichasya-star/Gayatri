@extends('layouts.app')

@section('title', ($service->exists ? 'Edit Layanan' : 'Tambah Layanan') . ' - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="CRM Master Data" title="{{ $service->exists ? 'Edit Layanan' : 'Tambah Layanan' }}" description="Atur layanan yang dapat ditawarkan admin, AI, dan flow booking.">
        <x-slot name="actions">
            <a class="button button-secondary" href="{{ route('admin.services.index') }}"><i data-lucide="arrow-left"></i> Kembali</a>
        </x-slot>
    </x-ui.page-header>

    @if ($errors->any())
        <div class="card" style="margin-bottom: 1rem; border-color: rgba(201,122,106,.55);">
            <div class="card-body" style="color: #7a3228;">{{ $errors->first() }}</div>
        </div>
    @endif

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ $service->exists ? route('admin.services.update', $service) : route('admin.services.store') }}" class="grid grid-2">
                @csrf
                @if ($service->exists)
                    @method('PUT')
                @endif

                <label><span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Nama Layanan</span><input class="input" type="text" name="name" value="{{ old('name', $service->name) }}" required></label>
                <label><span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Cabang</span>
                    <select class="input" name="branch_id">
                        <option value="">Semua cabang</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((string) old('branch_id', $service->branch_id) === (string) $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label><span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Kategori</span><input class="input" type="text" name="category" value="{{ old('category', $service->category) }}"></label>
                <label><span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Durasi (menit)</span><input class="input" type="number" min="15" name="duration_minutes" value="{{ old('duration_minutes', $service->duration_minutes) }}" required></label>
                <label><span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Harga</span><input class="input" type="number" min="0" step="0.01" name="price" value="{{ old('price', $service->price) }}" required></label>
                <label><span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Status</span>
                    <select class="input" name="is_active" required>
                        <option value="1" @selected((string) old('is_active', (int) $service->is_active) === '1')>Aktif</option>
                        <option value="0" @selected((string) old('is_active', (int) $service->is_active) === '0')>Nonaktif</option>
                    </select>
                </label>
                <label style="grid-column: 1 / -1;"><span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Deskripsi</span><textarea class="input" name="description" rows="4">{{ old('description', $service->description) }}</textarea></label>
                <div style="grid-column: 1 / -1; display:flex; justify-content:flex-end; gap:.75rem; flex-wrap:wrap;">
                    <a class="button button-secondary" href="{{ route('admin.services.index') }}">Batal</a>
                    <button class="button button-primary" type="submit"><i data-lucide="save"></i> Simpan</button>
                </div>
            </form>
        </div>
    </div>
@endsection
