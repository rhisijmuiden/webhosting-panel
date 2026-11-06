@props(['status' => 'neutral', 'dot' => true])
@php
$map = ['ok' => 'ok', 'active' => 'ok', 'running' => 'ok', 'succeeded' => 'ok',
        'warn' => 'warn', 'pending' => 'warn', 'provisioning' => 'warn', 'removing' => 'warn',
        'bad' => 'bad', 'failed' => 'bad', 'suspended' => 'bad', 'offline' => 'bad', 'off' => 'neutral',
        'info' => 'info', 'imported' => 'info', 'neutral' => 'neutral'];
@endphp
<span {{ $attributes->class(['pill', 'pill-'.($map[$status] ?? 'neutral')]) }}>@if ($dot)<span class="dot" aria-hidden="true"></span>@endif{{ $slot }}</span>
