@extends('layouts.app')

@section('title', ($user->exists ? 'Edit User' : 'Tambah User') . ' - Gayatri CRM')

@section('content')
    <x-ui.page-header
        eyebrow="Users & Roles"
        title="{{ $user->exists ? 'Edit User' : 'Tambah User' }}"
        description="Atur identitas, role, status, dan password user dashboard."
    >
        <x-slot name="actions">
            <a class="button button-secondary" href="{{ route('admin.users.index') }}"><i data-lucide="arrow-left"></i> Kembali</a>
        </x-slot>
    </x-ui.page-header>

    @if ($errors->any())
        <div class="card" style="margin-bottom: 1rem; border-color: rgba(201,122,106,.55);">
            <div class="card-body" style="color: #7a3228;">{{ $errors->first() }}</div>
        </div>
    @endif

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ $user->exists ? route('admin.users.update', $user) : route('admin.users.store') }}" class="grid grid-2">
                @csrf
                @if ($user->exists)
                    @method('PUT')
                @endif

                <label>
                    <span class="muted" style="display: block; margin-bottom: .4rem; font-weight: 700;">Nama</span>
                    <input class="input" type="text" name="name" value="{{ old('name', $user->name) }}" required>
                </label>

                <label>
                    <span class="muted" style="display: block; margin-bottom: .4rem; font-weight: 700;">Email</span>
                    <input class="input" type="email" name="email" value="{{ old('email', $user->email) }}" required>
                </label>

                <label>
                    <span class="muted" style="display: block; margin-bottom: .4rem; font-weight: 700;">Telepon</span>
                    <input class="input" type="text" name="phone" value="{{ old('phone', $user->phone) }}">
                </label>

                <label>
                    <span class="muted" style="display: block; margin-bottom: .4rem; font-weight: 700;">Role</span>
                    <select class="input" name="role" required>
                        @foreach ($roles as $role)
                            <option value="{{ $role }}" @selected(old('role', $user->role) === $role)>{{ ucfirst($role) }}</option>
                        @endforeach
                    </select>
                </label>

                <label>
                    <span class="muted" style="display: block; margin-bottom: .4rem; font-weight: 700;">Status</span>
                    <select class="input" name="status" required>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected(old('status', $user->status) === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </select>
                </label>

                <div></div>

                <label>
                    <span class="muted" style="display: block; margin-bottom: .4rem; font-weight: 700;">Password</span>
                    <input class="input" type="password" name="password" @required(! $user->exists)>
                    @if ($user->exists)
                        <small class="muted">Kosongkan jika tidak ingin mengganti password.</small>
                    @endif
                </label>

                <label>
                    <span class="muted" style="display: block; margin-bottom: .4rem; font-weight: 700;">Konfirmasi Password</span>
                    <input class="input" type="password" name="password_confirmation" @required(! $user->exists)>
                </label>

                <div style="grid-column: 1 / -1; display: flex; justify-content: flex-end; gap: .75rem; flex-wrap: wrap;">
                    <a class="button button-secondary" href="{{ route('admin.users.index') }}">Batal</a>
                    <button class="button button-primary" type="submit"><i data-lucide="save"></i> Simpan</button>
                </div>
            </form>
        </div>
    </div>
@endsection
