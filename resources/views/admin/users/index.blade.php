@extends('layouts.app')

@section('title', 'Users & Roles - Gayatri CRM')

@section('content')
@include('partials.admin-workbench-assets')
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
            <form method="GET" action="{{ route('admin.users.index') }}" class="ops-filters">
                <label>Cari akun<input class="input" type="search" name="search" maxlength="150" value="{{ request('search') }}" placeholder="Cari nama, email, atau telepon"></label>
                <label>Peran<select class="input" name="role">
                    <option value="">Semua role</option>
                    @foreach ($roles as $role)
                        <option value="{{ $role }}" @selected(request('role') === $role)>{{ ucfirst($role) }}</option>
                    @endforeach
                </select></label>
                <label>Status akun<select class="input" name="status">
                    <option value="">Semua status</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select></label>
                <button class="button button-secondary" type="submit"><i data-lucide="search"></i> Filter</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <table class="table-card">
                <thead>
                    <tr>
                        <th>Akun</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Login Terakhir</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        <tr>
                            <td data-label="Akun"><strong>{{ $user->name }}</strong><span class="ops-subtitle">{{ $user->email }}<br>{{ $user->phone ?: 'Telepon belum diisi' }}</span></td>
                            <td data-label="Role"><span class="badge badge-brown">{{ ucfirst($user->role) }}</span></td>
                            <td data-label="Status">
                                <span class="badge {{ $user->status === 'active' ? 'badge-green' : 'badge-red' }}">{{ $user->status==='active' ? 'Aktif' : 'Nonaktif' }}</span>
                            </td>
                            <td data-label="Login Terakhir">{{ $user->last_login_at?->format('d M Y H:i') ?: '-' }}</td>
                            <td data-label="Aksi">
                                <div style="display: flex; gap: .4rem; flex-wrap: wrap;">
                                    <a class="button button-secondary" href="{{ route('admin.users.edit',array_merge(['user'=>$user],\App\Support\AdminReviewContext::carry(request(),'users'))) }}">Edit</a>
<details><summary>Ubah status akun</summary><div class="ops-actions">                                    @if ($user->status === 'active')
                                        <form method="POST" action="{{ route('admin.users.deactivate', $user) }}">
                                            @csrf @include('partials.admin-review-fields',['reviewModule'=>'users'])
                                            <button class="button button-ghost" type="submit">Nonaktifkan</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('admin.users.activate', $user) }}">
                                            @csrf @include('partials.admin-review-fields',['reviewModule'=>'users'])
                                            <button class="button button-secondary" type="submit">Aktifkan</button>
                                        </form>
                                    @endif
</div></details>                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 2rem;">Belum ada user yang sesuai filter.</td>
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
