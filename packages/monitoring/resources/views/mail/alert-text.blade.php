{{ $heading }}

@if ($greeting)
{{ $greeting }}

@endif
{{ $intro }}

@foreach ($items as $it)
- [{{ $it['badge'] }}] {{ $it['headline'] }}
@if ($it['detail'])
  {{ $it['detail'] }}
@endif
  {{ $it['when'] }}
@if ($it['advice'])
  {{ __('What to do') }}: {{ $it['advice'] }}
@endif

@endforeach
{{ $button }}: {{ $url }}

{{ $footer }} {{ $footerLink }}: {{ $footerUrl }}
