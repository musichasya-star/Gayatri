@php($featuredSelection = old('page_settings.featured_catalog_codes', data_get($page->page_settings, 'featured_catalog_codes', config('gayatri_frontend.featured_catalog_codes', []))))
<section class="card" style="margin:1rem 0"><div class="card-body">
    <h3 style="margin-top:0">Layanan pilihan di beranda</h3>
    <p class="muted">Pilih hingga enam treatment atau kelas sesuai urutan tampil. Harga, durasi, serta tindakan pemesanan mengikuti katalog saat ini. Pilihan yang sama ditampilkan sekali; kosongkan semua untuk menyembunyikan bagian ini.</p>
    <form data-guard-unsaved method="POST" action="{{ route('admin.landing.settings.update') }}" class="ops-fields">
        @csrf<input type="hidden" name="site_name" value="{{ $page->site_name }}">
        @for($position=0;$position<6;$position++)
            @php($selectedCode = is_array($featuredSelection) ? ($featuredSelection[$position] ?? '') : '')
            <label>Layanan pilihan {{ $position+1 }}<select class="input" name="page_settings[featured_catalog_codes][]"><option value="">Tidak ditampilkan</option>
                @if($selectedCode && !$featuredCandidates->contains('catalog_code',$selectedCode))<option value="{{ $selectedCode }}" selected>{{ $selectedCode }} · tidak tersedia, pilih ulang</option>@endif
                @foreach($featuredCandidates->groupBy('service_group') as $group=>$choices)<optgroup label="{{ \App\Support\ServiceGroup::LABELS[$group] }}">@foreach($choices as $choice)<option value="{{ $choice->catalog_code }}" @selected($selectedCode===$choice->catalog_code)>{{ $choice->name }} · {{ $choice->priceLabel() }}{{ $choice->requiresManualBooking() ? ' · konfirmasi admin' : '' }}</option>@endforeach</optgroup>@endforeach
            </select></label>
        @endfor
        <div class="ops-actions" style="grid-column:1/-1"><button class="button button-primary">Simpan layanan pilihan</button><a class="button button-secondary" href="{{ route('admin.landing.preview',['device'=>'mobile']) }}" target="_blank" rel="noopener">Pratinjau ponsel</a></div>
    </form>
</div></section>
