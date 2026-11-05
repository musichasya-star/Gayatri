<!doctype html>
<html lang="id"><head>
@include('partials.theme')<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>@yield('title', 'Laundry Ibu & Bayi — Gayatri')</title><link rel="stylesheet" href="{{ asset('css/laundry-customer.css').'?v='.config('gayatri_frontend.asset_version') }}"><link rel="stylesheet" href="{{ asset('css/gayatri-transactions.css').'?v='.config('gayatri_frontend.asset_version') }}"><script src="{{ asset('js/laundry-customer.js') }}" defer></script><script src="{{ asset('js/public-transactions.js') }}" defer></script></head>
<body><header class="site-head"><a class="brand" href="{{ route('laundry.index') }}"><span class="brand-icon">G</span><span>GAYATRI<small>LAUNDRY IBU & BAYI</small></span></a><a href="{{ route('landing.show') }}">Beranda Gayatri ↗</a></header><main>
@if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="notice error" role="alert"><strong>Periksa kembali</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')</main><footer>Gayatri Laundry · Perawatan pakaian ibu dan si kecil.<br>Butuh bantuan? Hubungi outlet yang tercantum pada pesanan Anda.</footer></body></html>
