@extends('layouts.app')

@section('title', ($service->exists ? 'Edit Layanan' : 'Tambah Layanan') . ' - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="CRM Master Data" title="{{ $service->exists ? 'Edit Layanan' : 'Tambah Layanan' }}" description="Atur layanan yang dapat ditawarkan admin, AI, dan flow booking.">
        <x-slot name="actions">
            <a class="button button-secondary" href="{{ route('admin.services.index') }}"><i data-lucide="arrow-left"></i> Kembali</a>
        </x-slot>
    </x-ui.page-header>

    @if ($errors->any())
        <div class="card" style="margin-bottom: 1rem; border-color: rgba(201,122,106,.55);">
            <div class="card-body" style="color: #7a3228;">{{ $errors->first() }}</div>
        </div>
    @endif

    @php
        $addonRows = old('addons');
        if ($addonRows === null) {
            $addonRows = $service->exists
                ? $service->addOns->map(fn ($addon) => [
                    'addon_service_id' => $addon->addon_service_id,
                    'duration_minutes' => $addon->duration_minutes,
                    'price_adjustment' => $addon->price_adjustment,
                    'is_active' => (int) $addon->is_active,
                ])->values()->all()
                : [];
        }
        if ($addonRows === []) {
            $addonRows = [['addon_service_id' => '', 'duration_minutes' => 0, 'price_adjustment' => 0, 'is_active' => 1]];
        }
    @endphp

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ $service->exists ? route('admin.services.update', $service) : route('admin.services.store') }}" class="grid grid-2">
                @csrf
                @if ($service->exists)
                    @method('PUT')
                @endif

                <label><span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Nama Layanan</span><input class="input" type="text" name="name" value="{{ old('name', $service->name) }}" required></label>
                <label><span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Cabang</span>
                    <select class="input" name="branch_id">
                        <option value="">Semua cabang</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((string) old('branch_id', $service->branch_id) === (string) $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label><span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Kategori</span><input class="input" type="text" name="category" value="{{ old('category', $service->category) }}"></label>
                <label><span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Durasi (menit)</span><input class="input" type="number" min="15" name="duration_minutes" value="{{ old('duration_minutes', $service->duration_minutes) }}" required></label>
                <label><span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Harga</span><input class="input" type="number" min="0" step="0.01" name="price" value="{{ old('price', $service->price) }}" required></label>
                <label><span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Status</span>
                    <select class="input" name="is_active" required>
                        <option value="1" @selected((string) old('is_active', (int) $service->is_active) === '1')>Aktif</option>
                        <option value="0" @selected((string) old('is_active', (int) $service->is_active) === '0')>Nonaktif</option>
                    </select>
                </label>
                <label style="grid-column: 1 / -1;"><span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Deskripsi</span><textarea class="input" name="description" rows="4">{{ old('description', $service->description) }}</textarea></label>

                <section style="grid-column:1/-1; border-top:1px solid rgba(216,195,165,.55); padding-top:1rem;">
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; margin-bottom:.75rem;">
                        <div>
                            <h3 style="margin:0; font-size:1.05rem;">Addon / Tambah Layanan</h3>
                            <p class="muted" style="margin:.25rem 0 0;">Pilih layanan tambahan yang dapat ditawarkan pada workflow booking, beserta tambahan durasi dan harga.</p>
                        </div>
                        <button class="button button-secondary" type="button" data-add-addon><i data-lucide="plus"></i> Tambah Addon</button>
                    </div>
                    <div data-addon-list style="display:grid; gap:.75rem;">
                        @foreach ($addonRows as $index => $addon)
                            <div class="grid grid-4" data-addon-row style="align-items:end; border:1px solid rgba(216,195,165,.55); border-radius:.75rem; padding:.85rem;">
                                <label><span class="muted" style="display:block; margin-bottom:.35rem; font-weight:700;">Layanan tambahan</span>
                                    <select class="input" name="addons[{{ $index }}][addon_service_id]">
                                        <option value="">Pilih addon</option>
                                        @foreach ($addonServices as $addonService)
                                            <option value="{{ $addonService->id }}" @selected((string) ($addon['addon_service_id'] ?? '') === (string) $addonService->id)>{{ $addonService->name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label><span class="muted" style="display:block; margin-bottom:.35rem; font-weight:700;">Tambahan durasi</span><input class="input" type="number" min="0" name="addons[{{ $index }}][duration_minutes]" value="{{ $addon['duration_minutes'] ?? 0 }}"></label>
                                <label><span class="muted" style="display:block; margin-bottom:.35rem; font-weight:700;">Penyesuaian harga</span><input class="input" type="number" min="0" step="0.01" name="addons[{{ $index }}][price_adjustment]" value="{{ $addon['price_adjustment'] ?? 0 }}"></label>
                                <div style="display:flex; gap:.5rem; align-items:end;">
                                    <label style="flex:1;"><span class="muted" style="display:block; margin-bottom:.35rem; font-weight:700;">Status</span>
                                        <select class="input" name="addons[{{ $index }}][is_active]">
                                            <option value="1" @selected((string) ($addon['is_active'] ?? 1) === '1')>Aktif</option>
                                            <option value="0" @selected((string) ($addon['is_active'] ?? 1) === '0')>Nonaktif</option>
                                        </select>
                                    </label>
                                    <button class="button button-ghost" type="button" data-remove-addon><i data-lucide="trash"></i></button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>

                <div style="grid-column: 1 / -1; display:flex; justify-content:flex-end; gap:.75rem; flex-wrap:wrap;">
                    <a class="button button-secondary" href="{{ route('admin.services.index') }}">Batal</a>
                    <button class="button button-primary" type="submit"><i data-lucide="save"></i> Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <template data-addon-template>
        <div class="grid grid-4" data-addon-row style="align-items:end; border:1px solid rgba(216,195,165,.55); border-radius:.75rem; padding:.85rem;">
            <label><span class="muted" style="display:block; margin-bottom:.35rem; font-weight:700;">Layanan tambahan</span>
                <select class="input" data-name="addon_service_id">
                    <option value="">Pilih addon</option>
                    @foreach ($addonServices as $addonService)
                        <option value="{{ $addonService->id }}">{{ $addonService->name }}</option>
                    @endforeach
                </select>
            </label>
            <label><span class="muted" style="display:block; margin-bottom:.35rem; font-weight:700;">Tambahan durasi</span><input class="input" type="number" min="0" value="0" data-name="duration_minutes"></label>
            <label><span class="muted" style="display:block; margin-bottom:.35rem; font-weight:700;">Penyesuaian harga</span><input class="input" type="number" min="0" step="0.01" value="0" data-name="price_adjustment"></label>
            <div style="display:flex; gap:.5rem; align-items:end;">
                <label style="flex:1;"><span class="muted" style="display:block; margin-bottom:.35rem; font-weight:700;">Status</span>
                    <select class="input" data-name="is_active"><option value="1">Aktif</option><option value="0">Nonaktif</option></select>
                </label>
                <button class="button button-ghost" type="button" data-remove-addon><i data-lucide="trash"></i></button>
            </div>
        </div>
    </template>

    <script>
        (() => {
            const list = document.querySelector('[data-addon-list]');
            const template = document.querySelector('[data-addon-template]');
            const add = document.querySelector('[data-add-addon]');

            const syncNames = () => {
                list.querySelectorAll('[data-addon-row]').forEach((row, index) => {
                    row.querySelectorAll('[data-name]').forEach((field) => {
                        field.name = `addons[${index}][${field.dataset.name}]`;
                    });
                });
            };

            add?.addEventListener('click', () => {
                list.append(template.content.firstElementChild.cloneNode(true));
                syncNames();
                window.lucide?.createIcons();
            });
            list?.addEventListener('click', (event) => {
                if (! event.target.closest('[data-remove-addon]')) {
                    return;
                }
                event.target.closest('[data-addon-row]')?.remove();
                syncNames();
            });
            syncNames();
        })();
    </script>
@endsection
