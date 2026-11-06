<x-mail::message>
# {{ $heading }}

@if ($intro)
{{ $intro }}
@endif

@foreach ($lines as $line)
- {{ $line }}
@endforeach

{{ __('Server: :server', ['server' => $server]) }}

<x-mail::button :url="route('admin.server.index')">
{{ __('Open server management') }}
</x-mail::button>
</x-mail::message>
