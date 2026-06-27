@extends('layouts.app')

@section('title', 'Therapists - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="CRM Master Data" title="Terapis" description="Kelola profil terapis, spesialisasi, dan cabang aktif untuk kebutuhan scheduling.">
        <x-slot name="actions">
            <a class="button button-primary" href="{{ route('admin.therapists.create') }}"><i data-lucide="user-round-plus"></i> Tambah Terapis</a>
        </x-slot>
    </x-ui.page-header>

    @if (session('status'))
        <div class="card" style="margin-bottom: 1rem; border-color: rgba(143,174,139,.55);">
            <div class="card-body" style="color: #3f6d3d;">{{ session('status') }}</div>
        </div>
    @endif

    <div class="card" style="margin-bottom: 1rem;">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.therapists.index') }}" class="grid grid-4">
                <input class="input" type="search" name="search" value="{{ request('search') }}" placeholder="Cari nama, telepon, spesialisasi">
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
                        <th>Terapis</th>
                        <th>User Login</th>
                        <th>Cabang</th>
                        <th>Spesialisasi</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($therapists as $therapist)
                        <tr>
                            <td data-label="Terapis">{{ $therapist->name }}<br><span class="muted">{{ $therapist->phone ?: '-' }}</span></td>
                            <td data-label="User Login">{{ $therapist->user?->email ?: '-' }}</td>
                            <td data-label="Cabang">{{ $therapist->branch?->name ?: '-' }}</td>
                            <td data-label="Spesialisasi">{{ $therapist->specialization ?: '-' }}</td>
                            <td data-label="Status"><span class="badge {{ $therapist->status === 'active' ? 'badge-green' : 'badge-red' }}">{{ ucfirst($therapist->status) }}</span></td>
                            <td data-label="Aksi">
                                <div style="display: flex; gap: .4rem; flex-wrap: wrap;">
                                    <a class="button button-secondary" href="{{ route('admin.therapists.edit', $therapist) }}">Edit</a>
                                    @if ($therapist->status !== 'inactive')
                                        <form method="POST" action="{{ route('admin.therapists.archive', $therapist) }}">
                                            @csrf
                                            <button class="button button-ghost" type="submit">Arsipkan</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 2rem;">Belum ada terapis yang sesuai filter.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            <div style="margin-top: 1rem;">
                {{ $therapists->links() }}
            </div>
        </div>
    </div>
@endsection
