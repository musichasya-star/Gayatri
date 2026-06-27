@props(['icon' => 'inbox', 'title', 'description', 'action' => null])

<div class="card empty-state">
    <div>
        <div class="icon-circle"><i data-lucide="{{ $icon }}"></i></div>
        <h2 class="section-title">{{ $title }}</h2>
        <p class="muted">{{ $description }}</p>
        @if ($action)
            <div style="margin-top: 1rem;">{{ $action }}</div>
        @endif
    </div>
</div>
