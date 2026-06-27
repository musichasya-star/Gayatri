@props(['count' => 4])

<div class="grid grid-4" aria-hidden="true">
    @for ($i = 0; $i < $count; $i++)
        <div class="skeleton skeleton-card"></div>
    @endfor
</div>
