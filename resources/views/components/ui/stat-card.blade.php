@props(['icon' => 'sparkles', 'label', 'value', 'note' => null, 'badge' => null])

<article class="card stat-card">
    <div class="card-body">
        <div class="stat-top">
            <div class="icon-circle"><i data-lucide="{{ $icon }}"></i></div>
            @if ($badge)
                <span class="badge badge-gold">{{ $badge }}</span>
            @endif
        </div>
        <p class="stat-label">{{ $label }}</p>
        <p class="stat-value">{{ $value }}</p>
        @if ($note)
            <p class="stat-note">{{ $note }}</p>
        @endif
    </div>
</article>
