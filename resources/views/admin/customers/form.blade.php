@extends('layouts.app')

@section('title', ($customer->exists ? 'Edit Customer' : 'Tambah Customer') . ' - Gayatri CRM')

@section('content')
    <x-ui.page-header
        eyebrow="CRM Master Data"
        title="{{ $customer->exists ? 'Edit Customer' : 'Tambah Customer' }}"
        description="Simpan data customer inti untuk inbox, booking, follow-up, dan campaign."
    >
        <x-slot name="actions">
            <a class="button button-secondary" href="{{ $customer->exists ? route('admin.customers.show', $customer) : route('admin.customers.index') }}"><i data-lucide="arrow-left"></i> Kembali</a>
        </x-slot>
    </x-ui.page-header>

    @if ($errors->any())
        <div class="card" style="margin-bottom: 1rem; border-color: rgba(201,122,106,.55);">
            <div class="card-body" style="color: #7a3228;">{{ $errors->first() }}</div>
        </div>
    @endif

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ $customer->exists ? route('admin.customers.update', $customer) : route('admin.customers.store') }}" class="grid grid-2">
                @csrf
                @if ($customer->exists)
                    @method('PUT')
                @endif

                <label>
                    <span class="muted" style="display: block; margin-bottom: .4rem; font-weight: 700;">Nama Customer</span>
                    <input class="input" type="text" name="name" value="{{ old('name', $customer->name) }}" required>
                </label>

                <label>
                    <span class="muted" style="display: block; margin-bottom: .4rem; font-weight: 700;">Cabang</span>
                    <select class="input" name="branch_id">
                        <option value="">Belum ditentukan</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((string) old('branch_id', $customer->branch_id) === (string) $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </label>

                <label>
                    <span class="muted" style="display: block; margin-bottom: .4rem; font-weight: 700;">Nomor Telepon</span>
                    <input class="input" type="text" name="phone" value="{{ old('phone', $customer->phone) }}">
                </label>

                <label>
                    <span class="muted" style="display: block; margin-bottom: .4rem; font-weight: 700;">Nomor WhatsApp</span>
                    <input class="input" type="text" name="whatsapp_number" value="{{ old('whatsapp_number', $customer->whatsapp_number) }}">
                </label>

                <label>
                    <span class="muted" style="display: block; margin-bottom: .4rem; font-weight: 700;">Email</span>
                    <input class="input" type="email" name="email" value="{{ old('email', $customer->email) }}">
                </label>

                <label>
                    <span class="muted" style="display: block; margin-bottom: .4rem; font-weight: 700;">Status</span>
                    <select class="input" name="status" required>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected(old('status', $customer->status) === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                        @endforeach
                    </select>
                </label>

                <label>
                    <span class="muted" style="display: block; margin-bottom: .4rem; font-weight: 700;">Tanggal Lahir Ibu</span>
                    <input class="input" type="date" name="birth_date" value="{{ old('birth_date', optional($customer->birth_date)->format('Y-m-d')) }}">
                </label>

                <label>
                    <span class="muted" style="display: block; margin-bottom: .4rem; font-weight: 700;">Nama Bayi</span>
                    <input class="input" type="text" name="baby_name" value="{{ old('baby_name', $customer->baby_name) }}">
                </label>

                <label>
                    <span class="muted" style="display: block; margin-bottom: .4rem; font-weight: 700;">Tanggal Lahir Bayi</span>
                    <input class="input" type="date" name="baby_birth_date" value="{{ old('baby_birth_date', optional($customer->baby_birth_date)->format('Y-m-d')) }}">
                </label>

                <label>
                    <span class="muted" style="display: block; margin-bottom: .4rem; font-weight: 700;">Kota</span>
                    <input class="input" type="text" name="city" value="{{ old('city', $customer->city) }}">
                </label>

                <label style="grid-column: 1 / -1;">
                    <span class="muted" style="display: block; margin-bottom: .4rem; font-weight: 700;">Alamat</span>
                    <textarea class="input" name="address" rows="3">{{ old('address', $customer->address) }}</textarea>
                </label>

                <label style="grid-column: 1 / -1;">
                    <span class="muted" style="display: block; margin-bottom: .4rem; font-weight: 700;">Tags</span>
                    <input class="input" type="text" name="tags" value="{{ old('tags', is_array($customer->tags) ? implode(', ', $customer->tags) : '') }}" placeholder="Pisahkan dengan koma, contoh: lead-baru, baby-spa">
                </label>

                <label style="grid-column: 1 / -1;">
                    <span class="muted" style="display: block; margin-bottom: .4rem; font-weight: 700;">Catatan</span>
                    <textarea class="input" name="notes" rows="4">{{ old('notes', $customer->notes) }}</textarea>
                </label>

                <div style="grid-column: 1 / -1; display: flex; justify-content: flex-end; gap: .75rem; flex-wrap: wrap;">
                    <a class="button button-secondary" href="{{ $customer->exists ? route('admin.customers.show', $customer) : route('admin.customers.index') }}">Batal</a>
                    <button class="button button-primary" type="submit"><i data-lucide="save"></i> Simpan</button>
                </div>
            </form>
        </div>
    </div>
@endsection
