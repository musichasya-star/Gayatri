<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>@yield('title', 'Dokumen Rental')</title>
    <link rel="stylesheet" href="{{ asset('css/rental-documents.css') }}">
</head>
<body class="rd-page">
    <a class="rd-skip rd-no-print" href="#document-content">Lewati ke isi dokumen</a>
    <header class="rd-toolbar rd-no-print">
        <nav class="rd-toolbar-links" aria-label="Navigasi dokumen rental">
            <a class="rd-brand-link" href="{{ route('admin.rentals.documents.index') }}"><span class="rd-brand-mark" aria-hidden="true">G</span> Dokumen Rental</a>
            <a href="{{ route('admin.rentals.orders.index') }}">Transaksi sewa</a>
            @yield('navigation')
        </nav>
        @yield('actions')
    </header>
    @if(session('status'))<div class="rd-flash rd-no-print" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rd-flash rd-flash-error rd-no-print" role="alert">{{ $errors->first() }}</div>@endif
    <main id="document-content" class="rd-content" tabindex="-1">@yield('content')</main>
</body>
</html>
