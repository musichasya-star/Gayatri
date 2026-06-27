<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Gayatri CRM WhatsApp AI')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/gayatri-theme.css') }}">
</head>
<body class="gayatri-app">
    <div class="sidebar-backdrop" data-sidebar-backdrop></div>
    <div class="app-shell">
        @include('partials.sidebar')

        <main class="main-panel">
            @include('partials.topbar')

            <div class="page-content">
                @yield('content')
            </div>
        </main>
    </div>

    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
    <script src="{{ asset('js/gayatri-ui.js') }}"></script>
    <script>
        if (window.lucide) {
            window.lucide.createIcons();
        }
    </script>
    @stack('scripts')
</body>
</html>
