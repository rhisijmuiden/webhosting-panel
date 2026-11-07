{{-- Stacked bar: files, databases and mail, relative to $max. --}}
@php
    $max = max(1, $max ?? ($files + $databases + $mail));
    $w = fn ($v) => round($v / $max * 100, 2);
@endphp
<div class="du-stack {{ $thin ?? false ? 'is-thin' : '' }}" role="img"
     aria-label="{{ __('Files') }} {{ \Illuminate\Support\Number::fileSize($files, 1) }}, {{ __('Databases') }} {{ \Illuminate\Support\Number::fileSize($databases, 1) }}, {{ __('Email') }} {{ \Illuminate\Support\Number::fileSize($mail, 1) }}">
    @if ($files)<span class="du-files" style="width: {{ $w($files) }}%"></span>@endif
    @if ($databases)<span class="du-db" style="width: {{ $w($databases) }}%"></span>@endif
    @if ($mail)<span class="du-mail" style="width: {{ $w($mail) }}%"></span>@endif
</div>
