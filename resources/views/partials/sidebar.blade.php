@php
    $menu = [
        ['label' => 'Dashboard', 'icon' => 'layout-dashboard', 'route' => 'admin.dashboard'],
        ['label' => 'Customers', 'icon' => 'users', 'route' => 'admin.customers.index'],
        ['label' => 'WhatsApp Inbox', 'icon' => 'message-circle', 'route' => 'admin.inbox'],
        ['label' => 'Bookings', 'icon' => 'calendar-check', 'route' => 'admin.bookings.index'],
        ['label' => 'Jadwal Tersedia', 'icon' => 'calendar-plus', 'route' => 'admin.availability.index'],
        ['label' => 'Home Service', 'icon' => 'house', 'route' => 'admin.home-service.index', 'active' => 'admin.home-service.*'],
        ['label' => 'Reminders', 'icon' => 'bell-ring', 'route' => 'admin.reminders.index'],
        ['label' => 'Ucapan Ulang Tahun', 'icon' => 'cake', 'route' => 'admin.birthdays.index', 'active' => 'admin.birthdays.*'],
        ['label' => 'Follow-Up', 'icon' => 'phone-forwarded', 'route' => 'admin.followups.index'],
        ['label' => 'Feedback', 'icon' => 'star', 'route' => 'admin.feedback.index'],
        ['label' => 'Customer Retention', 'icon' => 'heart-handshake', 'route' => 'admin.retention'],
        ['label' => 'Promo & Voucher', 'icon' => 'badge-percent', 'route' => 'admin.promos.index'],
        ['label' => 'Campaigns', 'icon' => 'megaphone', 'route' => 'admin.campaigns.index'],
        ['label' => 'Branches', 'icon' => 'building-2', 'route' => 'admin.branches.index'],
        ['label' => 'Layanan Pijat & Spa', 'icon' => 'sparkles', 'route' => 'admin.services.index', 'params' => ['service_group' => 'spa']],
        ['label' => 'Laktasi & Konsultasi ASI', 'icon' => 'heart-handshake', 'route' => 'admin.services.index', 'params' => ['service_group' => 'lactation']],
        ['label' => 'Produk Pendukung', 'icon' => 'shopping-bag', 'route' => 'admin.retail.index', 'active'=>'admin.retail.*'],
        ['label' => 'Landing Page CMS', 'icon' => 'layout-template', 'route' => 'admin.landing.index'],
        ['label' => 'Therapists', 'icon' => 'user-round-check', 'route' => 'admin.therapists.index'],
        ['label' => 'Fee Terapis', 'icon' => 'badge-percent', 'route' => 'admin.therapist-fees.index', 'active' => 'admin.therapist-fees.*'],
    ];

    $aiMenu = [
        ['label' => 'Balasan Otomatis', 'icon' => 'message-circle-reply', 'route' => 'admin.ai.auto-replies.index', 'active' => 'admin.ai.auto-replies.*'],
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
        ['label' => 'Monitoring WhatsApp', 'icon' => 'activity', 'route' => 'admin.whatsapp.monitor'],
        ['label' => 'Settings', 'icon' => 'settings', 'route' => 'admin.settings.index'],
        ['label' => 'Users & Roles', 'icon' => 'users-round', 'route' => 'admin.users.index'],
    ];
    $menuActive = function (array $item): bool {
        $pattern = $item['active'] ?? (str_ends_with($item['route'], '.index') ? substr($item['route'], 0, -5).'*' : $item['route']);
        if (isset($item['params']['service_group'])) {
            $boundService = request()->route('service');
            $group = $boundService instanceof \App\Models\Service ? $boundService->service_group : request('service_group', \App\Support\ServiceGroup::SPA);
            return request()->routeIs('admin.services.*') && $group === $item['params']['service_group'];
        }
        return request()->routeIs($pattern);
    };
    $crmRoutes = ['admin.customers.index', 'admin.followups.index', 'admin.retention', 'admin.promos.index', 'admin.campaigns.index'];
@endphp

<aside class="sidebar" id="admin-sidebar" data-sidebar>
    <button class="button button-ghost sidebar-close" type="button" data-sidebar-toggle aria-label="Tutup menu" aria-controls="admin-sidebar" aria-expanded="false"><i data-lucide="x"></i> Tutup menu</button>
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
            @php($roles = in_array($item['route'], ['admin.dashboard','admin.inbox'], true) ? ['owner','manager','admin','sales','therapist'] : (in_array($item['route'], $crmRoutes, true) ? ['owner','manager','admin','sales'] : ['owner','manager','admin']))
            @continue(!auth()->user()->hasRole($roles))
            <a class="nav-link {{ $menuActive($item) ? 'is-active' : '' }}" @if($menuActive($item)) aria-current="page" @endif href="{{ Route::has($item['route']) ? route($item['route'], $item['params'] ?? []) : '#' }}">
                <i data-lucide="{{ $item['icon'] }}"></i>
                <span>{{ $item['label'] }}</span>
            </a>
        @endforeach

        @if(in_array(auth()->user()?->role, ['owner', 'manager', 'admin'], true))
            @foreach(['laundry' => 'Laundry Ibu & Bayi', 'rentals' => 'Rental Perlengkapan'] as $module => $heading)
                <p class="nav-label">{{ $heading }}</p>
                @foreach(\App\Support\AdminModuleNavigation::items($module) as $item)
                    @php($active = \App\Support\AdminModuleNavigation::isActive($item))
                    <a class="nav-link {{ $active ? 'is-active' : '' }}" @if($active) aria-current="page" @endif href="{{ route($item['route']) }}">
                        <i data-lucide="{{ $item['icon'] }}"></i><span>{{ $item['sidebar_label'] }}</span>
                    </a>
                @endforeach
            @endforeach
        @endif
        @if(auth()->user()->hasRole(['owner','manager','admin']))
        <p class="nav-label">AI Management</p>
        @foreach ($aiMenu as $item)
            @continue($item['route'] === 'admin.ai.auto-replies.index' && !in_array(auth()->user()?->role, ['owner', 'manager', 'admin'], true))
            <a class="nav-link {{ $menuActive($item) ? 'is-active' : '' }}" @if($menuActive($item)) aria-current="page" @endif href="{{ Route::has($item['route']) ? route($item['route']) : '#' }}">
                <i data-lucide="{{ $item['icon'] }}"></i>
                <span>{{ $item['label'] }}</span>
            </a>
        @endforeach
        @endif

        @if(auth()->user()->hasRole(['owner','manager','admin','sales']))
        <p class="nav-label">Bisnis</p>
        @foreach ($bottomMenu as $item)
            @php($roles = $item['route'] === 'admin.users.index' ? ['owner','manager'] : ($item['route'] === 'admin.reports.index' ? ['owner','manager','admin','sales'] : ['owner','manager','admin']))
            @continue(!auth()->user()->hasRole($roles))
            <a class="nav-link {{ $menuActive($item) ? 'is-active' : '' }}" @if($menuActive($item)) aria-current="page" @endif href="{{ Route::has($item['route']) ? route($item['route']) : '#' }}">
                <i data-lucide="{{ $item['icon'] }}"></i>
                <span>{{ $item['label'] }}</span>
            </a>
        @endforeach
        @endif
    </nav>
</aside>
