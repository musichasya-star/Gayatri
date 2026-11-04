@php
    $byType = $sections->keyBy('type');
    $contact = $landing['contact'];
    $about = $byType->get('about');
    $gallery = app(\App\Services\Web\LandingContent::class)->gallery($byType->get('gallery'));
    $faq = app(\App\Services\Web\LandingContent::class)->faq($byType->get('faq'));
    $packages = $byType->has('packages') ? $services->filter(fn($service)=>$service->catalogueKind()==='package')->groupBy(fn($service)=>$service->service_group.'|'.$service->familyName())->take(3) : collect();
    $navItems = [['Beranda','#home'],['Layanan','#services']];
    if ($about) $navItems[] = ['Tentang','#about'];
    if ($packages->isNotEmpty()) $navItems[] = ['Paket','#packages'];
    if ($gallery->isNotEmpty()) $navItems[] = ['Galeri','#gallery'];
    if ($faq->isNotEmpty()) $navItems[] = ['FAQ','#faq'];
    $navItems[] = ['Kontak','#contact'];
@endphp
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('partials.public-seo')
    <link rel="preload" href="{{ asset('fonts/playfair-display-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('css/gayatri-public.css') }}">
    @if($landing['hero']['video'])
        <link rel="stylesheet" href="{{ asset('css/gayatri-hero-video.css') }}">
        <script src="{{ asset('js/gayatri-hero-video.js') }}" defer></script>
    @endif
    <script src="{{ asset('js/gayatri-public.js') }}" defer></script>
</head>
<body class="g-public">
<a class="gp-skip" href="#main-content">Lewati ke isi halaman</a>
<header class="gp-header" data-public-header>
    <div class="gp-container gp-header-inner">
        <a class="gp-brand" href="#home" aria-label="Gayatri — Beranda"><x-public.icon name="flower"/><span><strong>GAYATRI</strong><small>Mom &amp; Baby Care</small></span></a>
        <nav class="gp-navigation" aria-label="Menu utama">@foreach($navItems as [$label,$href])<a href="{{ $href }}">{{ $label }}</a>@endforeach</nav>
        <div class="gp-header-actions"><a class="gp-button gp-button-primary gp-header-cta" href="#services">Pilih layanan<x-public.icon name="arrow"/></a><button class="gp-menu-toggle" type="button" data-menu-toggle aria-label="Buka menu" aria-controls="public-menu" aria-expanded="false" aria-haspopup="dialog"><x-public.icon name="menu"/></button></div>
    </div>
</header>
<dialog class="gp-menu" id="public-menu" aria-labelledby="public-menu-title">
    <div class="gp-menu-top"><strong id="public-menu-title">Jelajahi Gayatri</strong><button class="gp-menu-close" type="button" data-menu-close aria-label="Tutup menu"><x-public.icon name="close"/></button></div>
    <nav aria-label="Menu ponsel">@foreach($navItems as [$label,$href])<a href="{{ $href }}">{{ $label }}<x-public.icon name="arrow"/></a>@endforeach</nav>
    <p>Perawatan dan kebutuhan keluarga, dalam pilihan layanan yang terpisah.</p>
    <a class="gp-button gp-button-primary" href="{{ route('catalogue.index') }}">Lihat katalog lengkap<x-public.icon name="arrow"/></a>
</dialog>
<main id="main-content" tabindex="-1">
    @if($landing['hero']['video'])
        @include('landing.partials.hero-video')
    @else
    <section class="gp-hero" id="home" aria-labelledby="hero-title">
        <div class="gp-container gp-hero-grid">
            <div class="gp-hero-copy"><p class="gp-eyebrow"><span></span>GAYATRI · MOM &amp; BABY CARE</p><h1 id="hero-title">{{ $landing['hero']['headline'] }}</h1><p class="gp-lead">{{ $landing['hero']['description'] }}</p><div class="gp-actions"><a class="gp-button gp-button-primary" href="#services">Temukan layanan<x-public.icon name="arrow"/></a><a class="gp-button gp-button-outline" href="{{ route('catalogue.index') }}">Jelajahi katalog</a></div><p class="gp-hero-note"><x-public.icon name="heart"/>Pilihan untuk setiap tahap keluarga Bunda.</p></div>
            <figure class="gp-hero-art"><img src="{{ $landing['hero']['image'] }}" alt="{{ $landing['hero']['alt'] }}" @if($landing['hero']['srcset']) srcset="{{ $landing['hero']['srcset'] }}" sizes="(min-width: 1000px) 640px, (min-width: 701px) 50vw, 100vw" @endif width="1536" height="1024" fetchpriority="high" decoding="async">@if($landing['hero']['illustration'])<figcaption>{{ $landing['hero']['caption'] }}</figcaption>@endif<div class="gp-hero-seal" aria-hidden="true"><x-public.icon name="flower"/><span>Untuk Bunda<br>&amp; si kecil</span></div></figure>
        </div>
    </section>
    @endif
    <div class="gp-reassurance"><div class="gp-container"><span><x-public.icon name="flower"/>Layanan sesuai kebutuhan</span><span><x-public.icon name="calendar"/>Jadwal dikonfirmasi tim</span><span><x-public.icon name="chat"/>Bantuan dari admin Gayatri</span></div></div>
    @include('landing.partials.home-services')
    @include('landing.partials.home-featured')
    @if($about)
    <section class="gp-section gp-about" id="about" aria-labelledby="about-title"><div class="gp-container gp-split">
        @php
            $aboutImage = \App\Services\Web\LandingPresentation::url($about->content['image_url'] ?? '');
            $aboutIllustrated = !$aboutImage || str_contains($aboutImage,'images.unsplash.com');
            $aboutPhoto = \App\Services\Web\LandingContent::image('lactation');
            if ($aboutIllustrated) $aboutImage = $aboutPhoto['image_url'];
            $aboutHeadline = $about->content['headline'] ?? '';
            if (!$aboutHeadline || $aboutHeadline==='Spa keluarga dengan standar pelayanan profesional') $aboutHeadline='Perhatian untuk setiap tahap keluarga.';
        @endphp
        @if($aboutImage)<figure class="gp-about-image"><img src="{{ $aboutImage }}" alt="{{ $aboutIllustrated ? $aboutPhoto['alt'] : ($about->content['image_alt'] ?? 'Gambar layanan Gayatri') }}" @if($aboutIllustrated) srcset="{{ $aboutPhoto['srcset'] }}" sizes="(min-width: 1000px) 550px, (min-width: 701px) 50vw, 100vw" @endif loading="lazy" decoding="async" width="720" height="540">@if($aboutIllustrated)<figcaption>Ilustrasi foto · AI</figcaption>@endif</figure>@endif
        <div><p class="gp-eyebrow">KENALI GAYATRI</p><h2 class="gp-heading" id="about-title">{{ $aboutHeadline }}</h2><p class="gp-lead">{{ $about->content['description'] ?? 'Temukan pilihan perawatan dan kebutuhan keluarga, lalu rencanakan kunjungan bersama tim Gayatri.' }}</p><ul class="gp-check-list"><li><x-public.icon name="check"/>Pilihan layanan ibu dan anak</li><li><x-public.icon name="check"/>Pemesanan melalui halaman layanan masing-masing</li><li><x-public.icon name="check"/>Rincian dikonfirmasi sebelum pelayanan</li></ul><a class="gp-text-link" href="#contact">Kenali layanan bersama tim kami<x-public.icon name="arrow"/></a></div>
    </div></section>
    @endif
    @if($packages->isNotEmpty())
    <section class="gp-section gp-packages" id="packages" aria-labelledby="packages-title"><div class="gp-container"><div class="gp-section-heading"><p class="gp-eyebrow">PAKET PERAWATAN</p><h2 class="gp-heading" id="packages-title">Pilihan yang bisa direncanakan bersama.</h2><p>Kenali isi dan variasi paket. Jumlah sesi serta jadwal dikonfirmasi oleh admin.</p></div><div class="gp-three-grid">
    @foreach($packages as $variants)@php($package=$variants->first())<article class="gp-package"><x-public.icon name="flower"/><span class="gp-tag">Konfirmasi admin</span><h3>{{ $package->familyName() }}</h3><p>{{ $variants->count() }} pilihan · {{ $package->groupLabel() }}</p>@php($minimum=$variants->whereNotNull('price')->min('price'))<strong>{{ $minimum !== null ? 'Mulai Rp '.number_format((float)$minimum,0,',','.') : 'Harga dikonfirmasi admin' }}</strong><a class="gp-button gp-button-outline" href="{{ route('catalogue.index',['group'=>$package->service_group,'search'=>$package->familyName()]) }}">Lihat pilihan paket<x-public.icon name="arrow"/></a></article>@endforeach
    </div></div></section>
    @endif
    @include('landing.partials.home-rental')
    <section class="gp-section gp-how" id="how_to_book" aria-labelledby="how-title"><div class="gp-container"><div class="gp-section-heading"><p class="gp-eyebrow">PEMESANAN</p><h2 class="gp-heading" id="how-title">Mulai dengan tiga langkah sederhana.</h2></div><ol class="gp-steps"><li><span>01</span><div><h3>Pilih layanan</h3><p>Temukan treatment, pendampingan, perlengkapan sewa, atau laundry.</p></div></li><li><span>02</span><div><h3>Lengkapi pilihan</h3><p>Tentukan rincian dan jadwal yang tersedia pada halaman layanan.</p></div></li><li><span>03</span><div><h3>Tunggu konfirmasi</h3><p>Tim memeriksa permintaan. Ikuti rincian pembayaran manual setelah dikonfirmasi.</p></div></li></ol></div></section>
    @if($gallery->isNotEmpty())
    <section class="gp-section gp-gallery" id="gallery" aria-labelledby="gallery-title"><div class="gp-container"><div class="gp-section-heading"><p class="gp-eyebrow">GALERI</p><h2 class="gp-heading" id="gallery-title">Nuansa hangat, perhatian yang dekat.</h2></div>@if($gallery->contains('illustration',true))<p class="gp-gallery-context">{{ $gallery->every(fn($item)=>$item['illustration']) ? 'Gambaran layanan melalui ilustrasi foto AI, bukan dokumentasi outlet atau tim Gayatri.' : 'Gambar ilustrasi diberi keterangan pada masing-masing foto.' }}</p>@endif<div class="gp-gallery-grid">@foreach($gallery as $item)<figure><img src="{{ $item['image_url'] }}" alt="{{ $item['alt'] }}" @if($item['srcset']) srcset="{{ $item['srcset'] }}" sizes="(min-width: 1000px) 300px, (min-width: 600px) 50vw, 100vw" @endif width="960" height="640" loading="lazy" decoding="async"><figcaption>{{ $item['title'] }}@if($item['caption'])<span>{{ $item['caption'] }}</span>@endif</figcaption></figure>@endforeach</div></div></section>
    @endif
    @foreach($sections->whereNotIn('type',['navigation','hero','services','about','packages','promo','booking','booking_inline_disabled','how_to_book','why_choose_us','benefits','gallery','testimonials','faq','location_contact','final_cta','footer']) as $section)
    <section class="gp-section" id="{{ \Illuminate\Support\Str::slug($section->type) }}"><div class="gp-container"><p class="gp-eyebrow">{{ $section->subtitle }}</p><h2 class="gp-heading">{{ $section->content['headline'] ?? $section->title }}</h2><p class="gp-lead">{{ $section->content['description'] ?? '' }}</p>@foreach($section->items ?: [] as $item)<article class="gp-card"><h3>{{ $item['title'] ?? $item['label'] ?? '' }}</h3><p>{{ $item['description'] ?? '' }}</p></article>@endforeach</div></section>
    @endforeach
    @if($faq->isNotEmpty())
    <section class="gp-section gp-faq" id="faq" aria-labelledby="faq-title"><div class="gp-container gp-faq-layout"><div><p class="gp-eyebrow">FAQ</p><h2 class="gp-heading" id="faq-title">Yang sering<br>ditanyakan.</h2><p>Masih ada yang ingin ditanyakan? Tim Gayatri siap membantu.</p>@if($contact['whatsapp'])<a class="gp-text-link" href="{{ $contact['whatsapp'] }}">Tanya admin<x-public.icon name="arrow"/></a>@endif</div><div class="gp-faq-list">@foreach($faq as $item)<details name="gayatri-faq"><summary>{{ $item['title'] ?? 'Informasi layanan' }}<x-public.icon name="plus"/></summary><p>{{ $item['description'] ?? '' }}</p></details>@endforeach</div></div></section>
    @endif
    <section class="gp-section gp-contact" id="contact" aria-labelledby="contact-title"><div class="gp-container"><div class="gp-contact-panel" id="booking"><div><p class="gp-eyebrow">RENCANAKAN BERSAMA GAYATRI</p><h2 class="gp-heading" id="contact-title">Kebutuhan Bunda,<br>kami bantu arahkan.</h2><p>Pilih layanan yang sesuai atau tanyakan rinciannya kepada tim kami sebelum memesan.</p><div class="gp-actions"><a class="gp-button gp-button-light" href="#services">Pilih layanan<x-public.icon name="arrow"/></a>@if($contact['whatsapp'])<a class="gp-button gp-button-on-dark" href="{{ $contact['whatsapp'] }}"><x-public.icon name="chat"/>Tanya lewat WhatsApp</a>@endif</div></div><div class="gp-contact-details"><x-public.icon name="pin"/><h3>Informasi kunjungan</h3><p>{{ $contact['address'] ?: 'Lokasi kunjungan dikonfirmasi sesuai layanan yang Bunda pilih.' }}</p>@if($contact['hours'])<p>{{ $contact['hours'] }}</p>@endif @if($contact['maps'])<a class="gp-text-link" href="{{ $contact['maps'] }}">Cari lokasi di Maps<x-public.icon name="arrow"/></a>@else<p class="gp-small">Lihat pilihan jadwal pada halaman pemesanan masing-masing layanan.</p>@endif</div></div></div></section>
</main>
<footer class="gp-footer"><div class="gp-container"><div class="gp-footer-grid"><div><a class="gp-brand" href="#home"><x-public.icon name="flower"/><span><strong>GAYATRI</strong><small>Mom &amp; Baby Care</small></span></a><p>{{ $page->site_name }}<br>Perawatan dan kebutuhan keluarga Bunda.</p></div><nav aria-label="Layanan Gayatri"><h2>Layanan</h2><a href="{{ route('landing.booking-page',['service_group'=>'spa']) }}">Pijat &amp; Spa</a><a href="{{ route('landing.booking-page',['service_group'=>'lactation']) }}">Laktasi &amp; ASI</a><a href="{{ route('rental.index') }}">Sewa Alat Bayi</a><a href="{{ route('laundry.index') }}">{{ $landing['laundryOpen'] ? 'Pesan Laundry' : 'Informasi Laundry' }}</a></nav><nav aria-label="Jelajahi Gayatri"><h2>Jelajahi</h2><a href="{{ route('catalogue.index') }}">Katalog lengkap</a><a href="{{ route('rental.cart.index') }}">Keranjang sewa</a><a href="#contact">Hubungi kami</a>@if($contact['instagram'])<a href="{{ $contact['instagram'] }}">Instagram</a>@endif @if($contact['whatsapp'])<a href="{{ $contact['whatsapp'] }}">WhatsApp</a>@endif</nav></div><div class="gp-footer-bottom"><small>© {{ date('Y') }} {{ $page->site_name }}</small><a href="{{ route('login') }}">Akses staf</a></div></div></footer>
</body>
</html>
