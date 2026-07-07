<header class="topbar">
    <button class="button button-ghost mobile-toggle" type="button" data-sidebar-toggle aria-label="Buka menu">
        <i data-lucide="menu"></i>
    </button>

    <label class="topbar-search">
        <i data-lucide="search"></i>
        <input type="search" placeholder="Cari customer, booking, campaign, atau percakapan">
    </label>

    <div style="display: flex; align-items: center; gap: .75rem;">
        <span class="badge badge-gold"><i data-lucide="bot"></i> AI Aktif</span>
        <a class="button button-ghost" href="{{ route('admin.dashboard') }}#notifikasi-operasional" aria-label="Notifikasi operasional" style="position:relative;">
            <i data-lucide="bell"></i>
            @if(($topbarIncomingBookingCount ?? 0) > 0)
                <span class="badge badge-red" style="position:absolute;top:-.45rem;right:-.45rem;min-width:1.35rem;height:1.35rem;padding:0 .35rem;display:inline-flex;align-items:center;justify-content:center;font-size:.72rem;">{{ $topbarIncomingBookingCount > 99 ? '99+' : $topbarIncomingBookingCount }}</span>
            @endif
        </a>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="button button-ghost" type="submit"><i data-lucide="log-out"></i> Logout</button>
        </form>
        <div class="avatar">{{ strtoupper(substr(auth()->user()?->name ?? 'GA', 0, 1)) }}</div>
    </div>
</header>
