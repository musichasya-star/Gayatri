<header class="topbar">
    <button class="button button-ghost mobile-toggle" type="button" data-sidebar-toggle aria-label="Buka menu" aria-controls="admin-sidebar" aria-expanded="false">
        <i data-lucide="menu"></i>
    </button>

    <form class="topbar-search" role="search" method="GET" action="{{ route('admin.search') }}">
        <label class="sr-only" for="global-search">Pencarian admin</label>
        <input id="global-search" type="search" name="q" value="{{ request()->routeIs('admin.search') ? request('q') : '' }}" placeholder="Cari data atau layanan…" minlength="2" maxlength="100" required>
        <button class="topbar-search-button" type="submit" aria-label="Cari data"><i data-lucide="search" aria-hidden="true"></i></button>
    </form>

    <div class="topbar-actions" style="display: flex; align-items: center; gap: .75rem;">
        <span class="badge {{ ($topbarAiEnabled ?? true) ? 'badge-gold' : 'badge-brown' }}" title="Status pengaturan balasan AI. Lihat koneksi gateway pada Monitoring WhatsApp."><i data-lucide="bot"></i> {{ ($topbarAiEnabled ?? true) ? 'Balasan AI Aktif' : 'Balasan AI Nonaktif' }}</span>
        <a class="button button-ghost" href="{{ route('admin.dashboard') }}#notifikasi-operasional" aria-label="Notifikasi operasional" style="position:relative;">
            <i data-lucide="bell"></i>
            @if(($topbarIncomingBookingCount ?? 0) > 0)
                <span class="badge badge-red" style="position:absolute;top:-.45rem;right:-.45rem;min-width:1.35rem;height:1.35rem;padding:0 .35rem;display:inline-flex;align-items:center;justify-content:center;font-size:.72rem;">{{ $topbarIncomingBookingCount > 99 ? '99+' : $topbarIncomingBookingCount }}</span>
            @endif
        </a>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="button button-ghost" type="submit" aria-label="Keluar dari akun"><i data-lucide="log-out"></i><span class="topbar-logout-label">Keluar</span></button>
        </form>
        <div class="avatar">{{ strtoupper(substr(auth()->user()?->name ?? 'GA', 0, 1)) }}</div>
    </div>
</header>
