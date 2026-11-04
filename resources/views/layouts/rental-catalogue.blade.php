<x-public.page :title="trim($__env->yieldContent('title','Rental Gayatri'))" :description="trim($__env->yieldContent('description','Lihat pilihan sewa perlengkapan bayi di Gayatri.'))" page-class="gp-rental-detail">
<x-slot:head>
    <link rel="stylesheet" href="{{ asset('css/rental.css') }}">
    <link rel="stylesheet" href="{{ asset('css/gayatri-transactions.css') }}">
</x-slot:head>
<x-slot:scripts><script src="{{ asset('js/public-transactions.js') }}" defer></script></x-slot:scripts>
@if(session('status'))<p class="gp-info" role="status">{{ session('status') }}</p>@endif
@if($errors->any())<p class="gp-info" data-error role="alert">{{ $errors->first() }}</p>@endif
@yield('content')
</x-public.page>
