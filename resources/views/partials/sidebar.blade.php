@php
    $menu = [
        ['label' => 'Dashboard', 'icon' => 'layout-dashboard', 'route' => 'admin.dashboard'],
        ['label' => 'Customers', 'icon' => 'users', 'route' => 'admin.customers.index'],
        ['label' => 'WhatsApp Inbox', 'icon' => 'message-circle', 'route' => 'admin.inbox'],
        ['label' => 'Bookings', 'icon' => 'calendar-check', 'route' => 'admin.bookings.index'],
        ['label' => 'Jadwal Tersedia', 'icon' => 'calendar-plus', 'route' => 'admin.availability.index'],
        ['label' => 'Reminders', 'icon' => 'bell-ring', 'route' => 'admin.reminders.index'],
        ['label' => 'Follow-Up', 'icon' => 'phone-forwarded', 'route' => 'admin.followups.index'],
        ['label' => 'Feedback', 'icon' => 'star', 'route' => 'admin.feedback.index'],
        ['label' => 'Customer Retention', 'icon' => 'heart-handshake', 'route' => 'admin.retention'],
        ['label' => 'Promo & Voucher', 'icon' => 'badge-percent', 'route' => 'admin.promos.index'],
        ['label' => 'Campaigns', 'icon' => 'megaphone', 'route' => 'admin.campaigns.index'],
        ['label' => 'Branches', 'icon' => 'building-2', 'route' => 'admin.branches.index'],
        ['label' => 'Services', 'icon' => 'sparkles', 'route' => 'admin.services.index'],
        ['label' => 'Landing Page CMS', 'icon' => 'layout-template', 'route' => 'admin.landing.index'],
        ['label' => 'Therapists', 'icon' => 'user-round-check', 'route' => 'admin.therapists.index'],
    ];

    $aiMenu = [
        ['label' => 'Persona', 'icon' => 'bot', 'route' => 'admin.ai.personas.index'],
        ['label' => 'Knowledge Base', 'icon' => 'book-open', 'route' => 'admin.ai.knowledge.index'],
        ['label' => 'Scope & Guardrail', 'icon' => 'shield-check', 'route' => 'admin.ai.guardrails.index'],
        ['label' => 'Data Automation', 'icon' => 'workflow', 'route' => 'admin.ai.data-automation.index'],
        ['label' => 'Simulator', 'icon' => 'messages-square', 'route' => 'admin.ai.simulator'],
        ['label' => 'AI Logs', 'icon' => 'scroll-text', 'route' => 'admin.ai.logs.index'],
    ];

    $bottomMenu = [
        ['label' => 'Reports', 'icon' => 'bar-chart-3', 'route' => 'admin.reports.index'],
        ['label' => 'WhatsApp Gateway', 'icon' => 'qr-code', 'route' => 'admin.whatsapp.session'],
        ['label' => 'Settings', 'icon' => 'settings', 'route' => 'admin.settings.index'],
        ['label' => 'Users & Roles', 'icon' => 'users-round', 'route' => 'admin.users.index'],
    ];
@endphp

<aside class="sidebar" data-sidebar>
    <div class="brand-card">
        <div class="brand-mark"><i data-lucide="flower-2"></i></div>
        <div>
            <p class="brand-title">Gayatri CRM</p>
            <p class="brand-subtitle">Mom & Baby SPA</p>
        </div>
    </div>

    <nav class="nav-section" aria-label="Menu utama">
        <p class="nav-label">Operasional</p>
        @foreach ($menu as $item)
            <a class="nav-link {{ request()->routeIs($item['route']) ? 'is-active' : '' }}" href="{{ Route::has($item['route']) ? route($item['route']) : '#' }}">
                <i data-lucide="{{ $item['icon'] }}"></i>
                <span>{{ $item['label'] }}</span>
            </a>
        @endforeach

        <p class="nav-label">AI Management</p>
        @foreach ($aiMenu as $item)
            <a class="nav-link {{ request()->routeIs($item['route']) ? 'is-active' : '' }}" href="{{ Route::has($item['route']) ? route($item['route']) : '#' }}">
                <i data-lucide="{{ $item['icon'] }}"></i>
                <span>{{ $item['label'] }}</span>
            </a>
        @endforeach

        <p class="nav-label">Bisnis</p>
        @foreach ($bottomMenu as $item)
            <a class="nav-link {{ request()->routeIs($item['route']) ? 'is-active' : '' }}" href="{{ Route::has($item['route']) ? route($item['route']) : '#' }}">
                <i data-lucide="{{ $item['icon'] }}"></i>
                <span>{{ $item['label'] }}</span>
            </a>
        @endforeach
    </nav>
</aside>
