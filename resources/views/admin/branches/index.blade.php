@extends('layouts.app')

@section('title', 'Branches - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="CRM Master Data" title="Cabang" description="Kelola lokasi operasional, kode cabang, dan distribusi layanan Gayatri.">
        <x-slot name="actions">
            <a class="button button-primary" href="{{ route('admin.branches.create') }}"><i data-lucide="building-2"></i> Tambah Cabang</a>
        </x-slot>
    </x-ui.page-header>

    @if (session('status'))
        <div class="card" style="margin-bottom: 1rem; border-color: rgba(143,174,139,.55);">
            <div class="card-body" style="color: #3f6d3d;">{{ session('status') }}</div>
        </div>
    @endif

    <div class="card" style="margin-bottom: 1rem;">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.branches.index') }}" class="grid grid-4">
                <input class="input" type="search" name="search" value="{{ request('search') }}" placeholder="Cari nama, kode, kota">
                <select class="input" name="status">
                    <option value="">Semua status</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
                <div></div>
                <button class="button button-secondary" type="submit"><i data-lucide="search"></i> Filter</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <table class="table-card">
                <thead>
                    <tr>
                        <th>Cabang</th>
                        <th>Kode</th>
                        <th>Kota</th>
                        <th>Status</th>
                        <th>Relasi</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($branches as $branch)
                        <tr>
                            <td data-label="Cabang">{{ $branch->name }}</td>
                            <td data-label="Kode">{{ $branch->code }}</td>
                            <td data-label="Kota">{{ $branch->city ?: '-' }}</td>
                            <td data-label="Status"><span class="badge {{ $branch->status === 'active' ? 'badge-green' : 'badge-red' }}">{{ ucfirst($branch->status) }}</span></td>
                            <td data-label="Relasi">{{ $branch->customers_count }} customer, {{ $branch->services_count }} layanan, {{ $branch->therapists_count }} terapis</td>
                            <td data-label="Aksi">
                                <div style="display: flex; gap: .4rem; flex-wrap: wrap;">
                                    <a class="button button-secondary" href="{{ route('admin.branches.edit', $branch) }}">Edit</a>
                                    @if ($branch->status !== 'inactive')
                                        <form method="POST" action="{{ route('admin.branches.archive', $branch) }}">
                                            @csrf
                                            <button class="button button-ghost" type="submit">Arsipkan</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 2rem;">Belum ada cabang yang sesuai filter.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            <div style="margin-top: 1rem;">
                {{ $branches->links() }}
            </div>
        </div>
    </div>
@endsection
