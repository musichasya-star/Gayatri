@extends('layouts.app')

@section('title', ($therapist->exists ? 'Edit Terapis' : 'Tambah Terapis') . ' - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="CRM Master Data" title="{{ $therapist->exists ? 'Edit Terapis' : 'Tambah Terapis' }}" description="Atur identitas terapis dan hubungkan dengan akun login bila diperlukan.">
        <x-slot name="actions">
            <a class="button button-secondary" href="{{ route('admin.therapists.index') }}"><i data-lucide="arrow-left"></i> Kembali</a>
        </x-slot>
    </x-ui.page-header>

    @if ($errors->any())
        <div class="card" style="margin-bottom: 1rem; border-color: rgba(201,122,106,.55);">
            <div class="card-body" style="color: #7a3228;">{{ $errors->first() }}</div>
        </div>
    @endif

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ $therapist->exists ? route('admin.therapists.update', $therapist) : route('admin.therapists.store') }}" class="grid grid-2">
                @csrf
                @if ($therapist->exists)
                    @method('PUT')
                @endif

                <label><span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Nama Terapis</span><input class="input" type="text" name="name" value="{{ old('name', $therapist->name) }}" required></label>
                <label><span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Akun User</span>
                    <select class="input" name="user_id">
                        <option value="">Tanpa akun login</option>
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}" @selected((string) old('user_id', $therapist->user_id) === (string) $user->id)>{{ $user->name }} ({{ $user->email }})</option>
                        @endforeach
                    </select>
                </label>
                <label><span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Cabang</span>
                    <select class="input" name="branch_id">
                        <option value="">Belum ditentukan</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((string) old('branch_id', $therapist->branch_id) === (string) $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label><span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Telepon</span><input class="input" type="text" name="phone" value="{{ old('phone', $therapist->phone) }}"></label>
                <label><span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Spesialisasi</span><input class="input" type="text" name="specialization" value="{{ old('specialization', $therapist->specialization) }}"></label>
                <label><span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Status</span>
                    <select class="input" name="status" required>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected(old('status', $therapist->status) === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </select>
                </label>
                <label style="grid-column: 1 / -1;"><span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Catatan</span><textarea class="input" name="notes" rows="4">{{ old('notes', $therapist->notes) }}</textarea></label>
                <div style="grid-column: 1 / -1; display:flex; justify-content:flex-end; gap:.75rem; flex-wrap:wrap;">
                    <a class="button button-secondary" href="{{ route('admin.therapists.index') }}">Batal</a>
                    <button class="button button-primary" type="submit"><i data-lucide="save"></i> Simpan</button>
                </div>
            </form>
        </div>
    </div>
@endsection
