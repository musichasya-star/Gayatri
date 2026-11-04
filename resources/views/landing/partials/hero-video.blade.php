@php($heroVideo = $landing['hero']['video'])
<section class="gp-hero gp-hero-motion" id="home" aria-labelledby="hero-title" data-hero-motion>
    <div class="gp-motion-background" aria-hidden="true">
        <picture>
            <source media="(max-width: 700px)" srcset="{{ asset($heroVideo['poster_mobile']) }}" width="360" height="640">
            <img src="{{ asset($heroVideo['poster_desktop']) }}" width="1280" height="720" alt="" fetchpriority="high" decoding="async">
        </picture>
        <video id="hero-ambient-video" data-desktop-src="{{ asset($heroVideo['desktop']) }}" data-mobile-src="{{ asset($heroVideo['mobile']) }}"
            muted loop playsinline preload="none" disablepictureinpicture tabindex="-1" aria-hidden="true"></video>
    </div>
    <div class="gp-container gp-motion-content">
        <div class="gp-hero-copy">
            <p class="gp-eyebrow"><span></span>GAYATRI · MOM &amp; BABY CARE</p>
            <h1 id="hero-title">{{ $landing['hero']['headline'] }}</h1>
            <p class="gp-lead">{{ $landing['hero']['description'] }}</p>
            <div class="gp-actions">
                <a class="gp-button gp-button-primary" href="#services">Temukan layanan<x-public.icon name="arrow"/></a>
                <a class="gp-button gp-button-outline" href="{{ route('catalogue.index') }}">Jelajahi katalog</a>
            </div>
            <p class="gp-hero-note"><x-public.icon name="heart"/>Pilihan untuk setiap tahap keluarga Bunda.</p>
        </div>
    </div>
    <div class="gp-motion-footer gp-container">
        <span class="gp-motion-caption">Ilustrasi suasana spa</span>
        <button class="gp-motion-toggle" type="button" data-motion-toggle aria-controls="hero-ambient-video" hidden>
            <svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><path class="gp-motion-play" d="m7 4 9 6-9 6z" fill="currentColor"/><path class="gp-motion-pause" d="M6 4v12M14 4v12" stroke="currentColor" stroke-width="3"/></svg>
            <span data-motion-label>Putar animasi</span>
        </button>
    </div>
</section>
