@extends('layouts.app')

@section('title', 'Customers - Gayatri CRM')

@section('content')
    <x-ui.page-header
        eyebrow="CRM Master Data"
        title="Customer"
        description="Kelola database customer, cari lead dengan cepat, dan buka histori interaksi dari satu tempat."
    >
        <x-slot name="actions">
            <a class="button button-secondary" href="{{ route('admin.customers.export', request()->query()) }}"><i data-lucide="download"></i> Export CSV</a>
            <a class="button button-primary" href="{{ route('admin.customers.create') }}"><i data-lucide="user-plus"></i> Tambah Customer</a>
        </x-slot>
    </x-ui.page-header>

    @if (session('status'))
        <div class="card" style="margin-bottom: 1rem; border-color: rgba(143,174,139,.55);">
            <div class="card-body" style="color: #3f6d3d;">{{ session('status') }}</div>
        </div>
    @endif

    <div class="card" style="margin-bottom: 1rem;">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.customers.import') }}" enctype="multipart/form-data" class="grid grid-4" style="margin-bottom: 1rem;">
                @csrf
                <input class="input" type="file" name="csv_file" accept=".csv,text/csv">
                <div class="muted" style="align-self: center;">Header: `name,branch_code,phone,whatsapp_number,email,city,status,tags,notes`</div>
                <div></div>
                <button class="button button-secondary" type="submit"><i data-lucide="upload"></i> Import CSV</button>
            </form>

            <form method="GET" action="{{ route('admin.customers.index') }}" class="grid grid-4">
                <input class="input" type="search" name="search" value="{{ request('search') }}" placeholder="Cari nama, telepon, WhatsApp, email">
                <select class="input" name="status">
                    <option value="">Semua status</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                    @endforeach
                </select>
                <input class="input" type="text" name="tag" value="{{ request('tag') }}" placeholder="Filter tag, contoh: baby-spa">
                <button class="button button-secondary" type="submit"><i data-lucide="search"></i> Filter</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div style="overflow-x:auto;">
            <table class="table-card">
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Cabang</th>
                        <th>WhatsApp</th>
                        <th>Telepon</th>
                        <th>Status</th>
                        <th>Tag</th>
                        <th>Interaksi Terakhir</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($customers as $customer)
                        <tr>
                            <td data-label="Nama">
                                <strong>{{ $customer->name }}</strong><br>
                                <span class="muted">{{ $customer->email ?: ($customer->city ?: '-') }}</span>
                            </td>
                            <td data-label="Cabang">{{ $customer->branch?->name ?: '-' }}</td>
                            <td data-label="WhatsApp">
                                <strong>{{ $customer->whatsapp_display }}</strong>
                                @if($customer->normalized_whatsapp_number)
                                    <br><span class="muted">{{ $customer->normalized_whatsapp_number }}</span>
                                @endif
                            </td>
                            <td data-label="Telepon">{{ $customer->phone ?: '-' }}</td>
                            <td data-label="Status"><span class="badge badge-brown">{{ ucfirst(str_replace('_', ' ', $customer->status)) }}</span></td>
                            <td data-label="Tag">{{ filled($customer->tags) ? implode(', ', $customer->tags) : '-' }}</td>
                            <td data-label="Interaksi Terakhir">{{ $customer->last_interaction_at?->format('d M Y H:i') ?: '-' }}</td>
                            <td data-label="Aksi">
                                <div style="display: flex; gap: .4rem; flex-wrap: wrap;">
                                    <a class="button button-secondary" href="{{ route('admin.customers.show', $customer) }}">Detail</a>
                                    <a class="button button-ghost" href="{{ route('admin.customers.edit', $customer) }}">Edit</a>
                                    @if ($customer->status !== 'inactive')
                                        <form method="POST" action="{{ route('admin.customers.archive', $customer) }}">
                                            @csrf
                                            <button class="button button-ghost" type="submit">Arsipkan</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 2rem;">Belum ada customer yang sesuai filter.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            </div>

            <div style="margin-top:1rem; display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap;">
                <span class="muted">Menampilkan {{ $customers->firstItem() ?? 0 }}-{{ $customers->lastItem() ?? 0 }} dari {{ $customers->total() }} customer</span>
                <div style="display:flex; gap:.5rem; align-items:center;">
                    @if($customers->onFirstPage())<span class="button button-ghost" style="opacity:.45;pointer-events:none;">Sebelumnya</span>@else<a class="button button-ghost" href="{{ $customers->previousPageUrl() }}">Sebelumnya</a>@endif
                    <span class="muted">Halaman {{ $customers->currentPage() }} / {{ $customers->lastPage() }}</span>
                    @if($customers->hasMorePages())<a class="button button-ghost" href="{{ $customers->nextPageUrl() }}">Berikutnya</a>@else<span class="button button-ghost" style="opacity:.45;pointer-events:none;">Berikutnya</span>@endif
                </div>
            </div>
        </div>
    </div>
@endsection
