@if($featuredServices->isNotEmpty())
<section class="gp-section gp-featured" id="featured" aria-labelledby="featured-title"><div class="gp-container">
    <div class="gp-section-heading"><p class="gp-eyebrow">PILIHAN LAYANAN</p><h2 class="gp-heading" id="featured-title">Kenali perawatan yang sesuai.</h2><p>Beberapa pilihan treatment dan kelas dari katalog Gayatri. Pilih rincian yang Bunda perlukan sebelum menentukan jadwal.</p></div>
    <div class="gp-featured-grid">
        @foreach($featuredServices as $choice)
        @php($online = !$choice->requiresManualBooking() && $choice->duration_minutes>0 && $choice->price>=0)
        @php($art = config('gayatri_frontend.featured_illustrations.'.$choice->catalog_code, $choice->service_group === 'lactation' ? 'mother-baby' : 'care'))
        <article class="gp-featured-card gp-featured-illustrated" data-service-tone="{{ $choice->service_group === 'lactation' ? 'lactation' : 'spa' }}">
            <img class="gp-featured-art" src="{{ asset('images/landing/illustrations/'.$art.'.svg').'?v='.config('gayatri_frontend.asset_version') }}" width="180" height="180" alt="" aria-hidden="true" loading="lazy" decoding="async">
            <div class="gp-featured-top"><span class="gp-tag">{{ $choice->groupLabel() }}</span></div>
            <h3>{{ $choice->familyName() }}</h3>
            <div class="gp-featured-details">
            @if($choice->variant_label && mb_strtolower(trim($choice->variant_label)) !== mb_strtolower(trim($choice->durationLabel())))<p class="gp-featured-variant">{{ $choice->variant_label }}</p>@endif
            <p class="gp-featured-duration">{{ $choice->durationLabel() }}</p>
            </div>
            <div class="gp-featured-pricing"><strong class="gp-price">{{ $choice->priceLabel() }}</strong>
                @unless($online)<span class="gp-tag">Konfirmasi admin</span>@endunless
            </div>
            <a class="gp-text-link" href="{{ $online ? route('landing.booking-page',['service_group'=>$choice->service_group,'service_id'=>$choice->id]) : route('catalogue.index',['group'=>$choice->service_group,'search'=>$choice->familyName()]) }}">{{ $online ? 'Pilih jadwal' : 'Lihat rincian & konfirmasi' }}<x-public.icon name="arrow"/></a>
        </article>
        @endforeach
    </div>
    <p class="gp-featured-note">Harga untuk pilihan yang ditampilkan. Ketersediaan jadwal dan tambahan layanan diperiksa pada pemesanan.</p>
</div></section>
@endif
