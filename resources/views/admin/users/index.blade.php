@extends('layouts.app')

@section('title', 'Users & Roles - Gayatri CRM')

@section('content')
    <x-ui.page-header
        eyebrow="Users & Roles"
        title="Manajemen User"
        description="Kelola akun owner, manager, admin, sales, dan terapis yang dapat mengakses dashboard Gayatri."
    >
        <x-slot name="actions">
            <a class="button button-primary" href="{{ route('admin.users.create') }}"><i data-lucide="user-plus"></i> Tambah User</a>
        </x-slot>
    </x-ui.page-header>

    @if (session('status'))
        <div class="card" style="margin-bottom: 1rem; border-color: rgba(143,174,139,.55);">
            <div class="card-body" style="color: #3f6d3d;">{{ session('status') }}</div>
        </div>
    @endif

    @if ($errors->any())
        <div class="card" style="margin-bottom: 1rem; border-color: rgba(201,122,106,.55);">
            <div class="card-body" style="color: #7a3228;">{{ $errors->first() }}</div>
        </div>
    @endif

    <div class="card" style="margin-bottom: 1rem;">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.users.index') }}" class="grid grid-4">
                <input class="input" type="search" name="search" value="{{ request('search') }}" placeholder="Cari nama, email, atau telepon">
                <select class="input" name="role">
                    <option value="">Semua role</option>
                    @foreach ($roles as $role)
                        <option value="{{ $role }}" @selected(request('role') === $role)>{{ ucfirst($role) }}</option>
                    @endforeach
                </select>
                <select class="input" name="status">
                    <option value="">Semua status</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
                <button class="button button-secondary" type="submit"><i data-lucide="search"></i> Filter</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <table class="table-card">
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Email</th>
                        <th>Telepon</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Login Terakhir</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        <tr>
                            <td data-label="Nama">{{ $user->name }}</td>
                            <td data-label="Email">{{ $user->email }}</td>
                            <td data-label="Telepon">{{ $user->phone ?: '-' }}</td>
                            <td data-label="Role"><span class="badge badge-brown">{{ ucfirst($user->role) }}</span></td>
                            <td data-label="Status">
                                <span class="badge {{ $user->status === 'active' ? 'badge-green' : 'badge-red' }}">{{ ucfirst($user->status) }}</span>
                            </td>
                            <td data-label="Login Terakhir">{{ $user->last_login_at?->format('d M Y H:i') ?: '-' }}</td>
                            <td data-label="Aksi">
                                <div style="display: flex; gap: .4rem; flex-wrap: wrap;">
                                    <a class="button button-secondary" href="{{ route('admin.users.edit', $user) }}">Edit</a>
                                    @if ($user->status === 'active')
                                        <form method="POST" action="{{ route('admin.users.deactivate', $user) }}">
                                            @csrf
                                            <button class="button button-ghost" type="submit">Nonaktifkan</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('admin.users.activate', $user) }}">
                                            @csrf
                                            <button class="button button-secondary" type="submit">Aktifkan</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 2rem;">Belum ada user yang sesuai filter.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            <div style="margin-top: 1rem;">
                {{ $users->links() }}
            </div>
        </div>
    </div>
@endsection
