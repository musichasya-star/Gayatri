<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Gayatri CRM</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/gayatri-theme.css') }}">
</head>
<body class="gayatri-app">
    <main class="login-shell">
        <section class="login-hero">
            <div class="brand-card" style="width: fit-content;">
                <div class="brand-mark"><i data-lucide="flower-2"></i></div>
                <div>
                    <p class="brand-title">Gayatri CRM</p>
                    <p class="brand-subtitle">WhatsApp AI Assistant</p>
                </div>
            </div>

            <div style="position: relative; z-index: 1; max-width: 40rem;">
                <p class="eyebrow" style="color: var(--soft-gold);">Brown Gold Classic Modern</p>
                <h1 class="page-title" style="color: var(--ivory);">Dashboard premium untuk Mom & Baby SPA.</h1>
                <p style="color: rgba(255,253,247,.76); line-height: 1.8; max-width: 34rem;">Kelola WhatsApp, customer, booking, reminder, follow-up, campaign, dan AI assistant Gayatri dalam pengalaman yang hangat, lembut, dan profesional.</p>
            </div>

            <ul class="login-feature-list">
                <li><i data-lucide="message-circle"></i> WhatsApp inbox dengan human takeover</li>
                <li><i data-lucide="bot"></i> AI persona, guardrail, dan knowledge base</li>
                <li><i data-lucide="calendar-check"></i> Booking, reminder, dan follow-up otomatis</li>
            </ul>
        </section>

        <section class="login-card">
            <p class="eyebrow">Selamat Datang</p>
            <h2 class="page-title" style="font-size: clamp(1.7rem, 4vw, 2.35rem);">Masuk ke Dashboard</h2>
            <p class="page-description">Gunakan akun owner, admin, sales, atau terapis untuk melanjutkan.</p>

            @if ($errors->any())
                <div style="margin-top: 1rem; padding: .85rem; border: 1px solid rgba(201,122,106,.45); border-radius: 1rem; color: #6e3128; background: rgba(201,122,106,.14);">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" style="display: grid; gap: 1rem; margin-top: 1.4rem;">
                @csrf
                <label>
                    <span class="muted" style="display: block; margin-bottom: .4rem; font-weight: 700;">Email</span>
                    <input class="input" type="email" name="email" value="{{ old('email') }}" placeholder="owner@gayatri.local" autocomplete="email" required autofocus>
                </label>

                <label>
                    <span class="muted" style="display: block; margin-bottom: .4rem; font-weight: 700;">Password</span>
                    <input class="input" type="password" name="password" placeholder="Masukkan password" autocomplete="current-password" required>
                </label>

                <div style="display: flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
                    <label style="display: inline-flex; align-items: center; gap: .5rem; color: var(--text-muted);">
                        <input type="checkbox" name="remember"> Ingat saya
                    </label>
                    <a href="#" style="color: var(--primary-brown); font-weight: 800;">Lupa password?</a>
                </div>

                <button class="button button-primary" type="submit" style="width: 100%;"><i data-lucide="log-in"></i> Masuk</button>
            </form>

            <div style="margin-top: 1rem; padding: .9rem; border: 1px solid rgba(216,195,165,.66); border-radius: 1rem; background: rgba(248,243,234,.72);">
                <strong>Demo account</strong>
                <p class="muted" style="margin: .3rem 0 0;">owner@gayatri.local / password</p>
            </div>
        </section>
    </main>

    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
    <script>
        if (window.lucide) {
            window.lucide.createIcons();
        }
    </script>
</body>
</html>
