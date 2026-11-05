@props(['title' => null, 'flush' => false])
<section {{ $attributes->class(['card', 'card-flush' => $flush]) }}>
    @if ($title || isset($actions))
        <div class="card-head">
            @if ($title)<h2>{{ $title }}</h2>@endif
            @isset($actions)<div class="actions">{{ $actions }}</div>@endisset
        </div>
    @endif
    {{ $slot }}
</section>
