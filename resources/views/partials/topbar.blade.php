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
        <button class="button button-ghost" type="button" aria-label="Notifikasi">
            <i data-lucide="bell"></i>
        </button>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="button button-ghost" type="submit"><i data-lucide="log-out"></i> Logout</button>
        </form>
        <div class="avatar">{{ strtoupper(substr(auth()->user()?->name ?? 'GA', 0, 1)) }}</div>
    </div>
</header>
