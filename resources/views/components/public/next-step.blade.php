@props(['label', 'detail', 'href' => null, 'action' => null])
<aside class="transaction-next" aria-label="Langkah berikutnya">
    <p class="transaction-kicker">LANGKAH BERIKUTNYA</p>
    <h2>{{ $label }}</h2>
    <p>{{ $detail }}</p>
    @if($href && $action)
        <a class="transaction-link" href="{{ $href }}">{{ $action }} →</a>
    @endif
</aside>
