@extends('layouts.app')

@section('title', 'Services - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="CRM Master Data" title="Layanan" description="Kelola daftar treatment aktif, durasi, harga, dan distribusi per cabang.">
        <x-slot name="actions">
            <a class="button button-primary" href="{{ route('admin.services.create') }}"><i data-lucide="sparkles"></i> Tambah Layanan</a>
        </x-slot>
    </x-ui.page-header>

    @if (session('status'))
        <div class="card" style="margin-bottom: 1rem; border-color: rgba(143,174,139,.55);">
            <div class="card-body" style="color: #3f6d3d;">{{ session('status') }}</div>
        </div>
    @endif

    <div class="card" style="margin-bottom: 1rem;">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.services.index') }}" class="grid grid-4">
                <input class="input" type="search" name="search" value="{{ request('search') }}" placeholder="Cari nama atau kategori">
                <select class="input" name="branch_id">
                    <option value="">Semua cabang</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((string) request('branch_id') === (string) $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
                <select class="input" name="active">
                    <option value="">Semua status</option>
                    <option value="1" @selected(request('active') === '1')>Aktif</option>
                    <option value="0" @selected(request('active') === '0')>Nonaktif</option>
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
                        <th>Layanan</th>
                        <th>Cabang</th>
                        <th>Kategori</th>
                        <th>Durasi</th>
                        <th>Harga</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($services as $service)
                        <tr>
                            <td data-label="Layanan">{{ $service->name }}</td>
                            <td data-label="Cabang">{{ $service->branch?->name ?: 'Semua cabang' }}</td>
                            <td data-label="Kategori">{{ $service->category ?: '-' }}</td>
                            <td data-label="Durasi">{{ $service->duration_minutes }} menit</td>
                            <td data-label="Harga">Rp {{ number_format((float) $service->price, 0, ',', '.') }}</td>
                            <td data-label="Status"><span class="badge {{ $service->is_active ? 'badge-green' : 'badge-red' }}">{{ $service->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                            <td data-label="Aksi">
                                <div style="display: flex; gap: .4rem; flex-wrap: wrap;">
                                    <a class="button button-secondary" href="{{ route('admin.services.edit', $service) }}">Edit</a>
                                    @if ($service->is_active)
                                        <form method="POST" action="{{ route('admin.services.archive', $service) }}">
                                            @csrf
                                            <button class="button button-ghost" type="submit">Arsipkan</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 2rem;">Belum ada layanan yang sesuai filter.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            <div style="margin-top: 1rem;">
                {{ $services->links() }}
            </div>
        </div>
    </div>
@endsection
