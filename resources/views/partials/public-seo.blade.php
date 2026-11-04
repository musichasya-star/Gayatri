<title>{{ $meta['title'] }}</title>
<meta name="description" content="{{ $meta['description'] }}">
@if($meta['canonical'])
    <link rel="canonical" href="{{ $meta['canonical'] }}">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="id_ID">
    <meta property="og:site_name" content="{{ $meta['name'] }}">
    <meta property="og:url" content="{{ $meta['canonical'] }}">
    <meta property="og:title" content="{{ $meta['title'] }}">
    <meta property="og:description" content="{{ $meta['description'] }}">
    <meta property="og:image" content="{{ $meta['image'] }}">
    <meta property="og:image:alt" content="{{ $meta['imageAlt'] }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $meta['title'] }}">
    <meta name="twitter:description" content="{{ $meta['description'] }}">
    <meta name="twitter:image" content="{{ $meta['image'] }}">
    <meta name="twitter:image:alt" content="{{ $meta['imageAlt'] }}">
@else
    <meta name="robots" content="noindex, follow">
@endif
@if(!empty($meta['schema']))
    <script type="application/ld+json">{!! $meta['schema'] !!}</script>
@endif
