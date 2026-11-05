<x-public.page title="Laundry Ibu & Bayi — Gayatri" page-class="gp-laundry">
<x-slot:head><link rel="stylesheet" href="{{ asset('css/gayatri-transactions.css').'?v='.config('gayatri_frontend.asset_version') }}"></x-slot:head>
@if(session('status'))<p class="gp-info" role="status">{{ session('status') }}</p>@endif
@if($errors->any())<div class="gp-info" data-error role="alert"><strong>Periksa kembali</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')
<x-slot:scripts>
    <script src="{{ asset('js/laundry-customer.js') }}" defer></script>
    <script src="{{ asset('js/public-transactions.js') }}" defer></script>
</x-slot:scripts>
</x-public.page>
