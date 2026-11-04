@php
    $selected=$addonServices->firstWhere('id', $addon['addon_service_id'] ?? null);
    $selectedLabel=$selected?->name ?: ($addon['label'] ?? ($addon['addon_name'] ?? 'Belum dipilih'));
@endphp
<article class="catalogue-addon-row" data-addon-row>
    <input type="hidden" data-field="id" name="addons[{{ $index }}][id]" value="{{ $addon['id'] ?? '' }}">
    <input type="hidden" data-field="addon_service_id" name="addons[{{ $index }}][addon_service_id]" value="{{ $addon['addon_service_id'] ?? '' }}">
    <div class="catalogue-addon-heading"><div><small>Tambahan layanan</small><strong data-choice-label>{{ $selectedLabel ?: 'Belum dipilih' }}</strong><small data-choice-detail>{{ $selected ? $selected->durationLabel().' · '.$selected->priceLabel() : '' }}</small></div><div class="catalogue-actions"><button class="button button-secondary" type="button" data-open-picker>Cari / ganti</button><button class="button button-ghost" type="button" data-remove-addon aria-label="Lepaskan tambahan {{ $selectedLabel }}">Lepaskan</button></div></div>
    <div class="catalogue-fields">
        <label>Nama tambahan khusus<input class="input" data-field="addon_name" name="addons[{{ $index }}][addon_name]" value="{{ $addon['addon_name'] ?? '' }}" maxlength="255" placeholder="Isi bila tidak memilih dari katalog"></label>
        <label>Publikasi tambahan<select class="input" data-field="is_active" name="addons[{{ $index }}][is_active]"><option value="1" @selected((string)($addon['is_active'] ?? 1)==='1')>Aktif</option><option value="0" @selected((string)($addon['is_active'] ?? 1)==='0')>Nonaktif</option></select></label>
        <label>Tambahan durasi (menit)<input class="input" data-field="duration_minutes" name="addons[{{ $index }}][duration_minutes]" type="number" min="0" max="600" value="{{ $addon['duration_minutes'] ?? '' }}"><small>0 berarti tidak menambah durasi; kosong bila belum diketahui.</small></label>
        <label>Tambahan harga (Rp)<input class="input" data-field="price_adjustment" name="addons[{{ $index }}][price_adjustment]" type="number" min="0" step="0.01" value="{{ $addon['price_adjustment'] ?? '' }}"><small>Isi biaya tambahan, bukan total harga treatment utama.</small></label>
        <label class="catalogue-check wide"><input type="hidden" data-field="requires_confirmation" name="addons[{{ $index }}][requires_confirmation]" value="0"><input type="checkbox" data-field="requires_confirmation" name="addons[{{ $index }}][requires_confirmation]" value="1" @checked($addon['requires_confirmation'] ?? true)> Harga atau durasi masih perlu konfirmasi admin</label>
    </div>
</article>
