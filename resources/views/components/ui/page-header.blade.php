@props(['eyebrow' => null, 'title', 'description' => null])

<div class="page-header">
    <div>
        @if ($eyebrow)
            <p class="eyebrow">{{ $eyebrow }}</p>
        @endif
        <h1 class="page-title">{{ $title }}</h1>
        @if ($description)
            <p class="page-description">{{ $description }}</p>
        @endif
    </div>
    @if (isset($actions))
        <div>{{ $actions }}</div>
    @endif
</div>
