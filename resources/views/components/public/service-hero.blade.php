@props(['role', 'eyebrow', 'title', 'lead'])
@php($photo = \App\Services\Web\LandingContent::image($role))
<section class="gp-service-hero"><div><p class="gp-eyebrow">{{ $eyebrow }}</p><h1>{{ $title }}</h1><p class="gp-lead">{{ $lead }}</p><div class="gp-actions">{{ $slot }}</div></div><figure><img src="{{ $photo['image_url'] }}" srcset="{{ $photo['srcset'] }}" sizes="(min-width:1000px) 440px, (min-width:701px) 40vw, 100vw" alt="{{ $photo['alt'] }}" width="960" height="640" fetchpriority="high"><figcaption>{{ $photo['caption'] }}</figcaption></figure></section>
