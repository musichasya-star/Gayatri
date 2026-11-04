<nav class="fee-actions fee-no-print" aria-label="Menu fee terapis">
    <a class="button {{ request()->routeIs('admin.therapist-fees.index') ? 'button-primary' : 'button-secondary' }}" href="{{ route('admin.therapist-fees.index') }}">Laporan Fee</a>
    <a class="button {{ request()->routeIs('admin.therapist-fees.settings') ? 'button-primary' : 'button-secondary' }}" href="{{ route('admin.therapist-fees.settings') }}">Pengaturan Persentase</a>
</nav>
