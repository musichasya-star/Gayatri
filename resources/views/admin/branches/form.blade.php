@extends('layouts.app')

@section('title', ($branch->exists ? 'Edit Cabang' : 'Tambah Cabang') . ' - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="CRM Master Data" title="{{ $branch->exists ? 'Edit Cabang' : 'Tambah Cabang' }}" description="Atur identitas cabang untuk kebutuhan customer, layanan, dan booking.">
        <x-slot name="actions">
            <a class="button button-secondary" href="{{ route('admin.branches.index') }}"><i data-lucide="arrow-left"></i> Kembali</a>
        </x-slot>
    </x-ui.page-header>

    @if ($errors->any())
        <div class="card" style="margin-bottom: 1rem; border-color: rgba(201,122,106,.55);">
            <div class="card-body" style="color: #7a3228;">{{ $errors->first() }}</div>
        </div>
    @endif

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ $branch->exists ? route('admin.branches.update', $branch) : route('admin.branches.store') }}" class="grid grid-2">
                @csrf
                @if ($branch->exists)
                    @method('PUT')
                @endif

                <label><span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Nama Cabang</span><input class="input" type="text" name="name" value="{{ old('name', $branch->name) }}" required></label>
                <label><span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Kode Cabang</span><input class="input" type="text" name="code" value="{{ old('code', $branch->code) }}" required></label>
                <label><span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Telepon</span><input class="input" type="text" name="phone" value="{{ old('phone', $branch->phone) }}"></label>
                <label><span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Kota</span><input class="input" type="text" name="city" value="{{ old('city', $branch->city) }}"></label>
                <label><span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Timezone</span><input class="input" type="text" name="timezone" value="{{ old('timezone', $branch->timezone) }}" required></label>
                <label><span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Status</span>
                    <select class="input" name="status" required>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected(old('status', $branch->status) === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </select>
                </label>
                <label style="grid-column: 1 / -1;"><span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Alamat</span><textarea class="input" name="address" rows="4">{{ old('address', $branch->address) }}</textarea></label>
                <div style="grid-column: 1 / -1; display:flex; justify-content:flex-end; gap:.75rem; flex-wrap:wrap;">
                    <a class="button button-secondary" href="{{ route('admin.branches.index') }}">Batal</a>
                    <button class="button button-primary" type="submit"><i data-lucide="save"></i> Simpan</button>
                </div>
            </form>
        </div>
    </div>
@endsection
