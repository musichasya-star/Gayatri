@props(['module', 'compact' => false])
@php
    $items = collect(\App\Support\AdminModuleNavigation::items($module));
    $primary = $items->where('primary', true);
    $secondary = $items->where('primary', false);
    $activeSecondary = $secondary->first(fn ($item) => \App\Support\AdminModuleNavigation::isActive($item));
    $label = $module === 'laundry' ? 'Menu Laundry' : 'Menu rental';
@endphp
<nav class="ops-nav module-nav" aria-label="{{ $label }}">
    @foreach($primary as $item)
        @php($active = \App\Support\AdminModuleNavigation::isActive($item))
        <a class="button {{ $active ? 'button-primary' : 'button-secondary' }}" href="{{ route($item['route']) }}" @if($active) aria-current="page" @endif>{{ $item['label'] }}</a>
    @endforeach
    <details class="ops-menu" @if($activeSecondary && !$compact) open @endif>
        <summary class="button button-secondary">
            <span>Menu lainnya
                @if($activeSecondary)
                    <span class="module-nav-current">· {{ $activeSecondary['label'] }}</span>
                @endif
            </span>
        </summary>
        <div class="ops-actions module-nav-more">
            @foreach($secondary as $item)
                @php($active = \App\Support\AdminModuleNavigation::isActive($item))
                <a class="button {{ $active ? 'button-primary' : 'button-secondary' }}" href="{{ route($item['route']) }}" @if($active) aria-current="page" @endif>{{ $item['label'] }}</a>
            @endforeach
        </div>
    </details>
</nav>
