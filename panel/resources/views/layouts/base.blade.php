<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('brand/favicon.svg') }}" type="image/svg+xml">
    <link rel="alternate icon" href="{{ asset('favicon.ico') }}">
    <link rel="stylesheet" href="{{ asset('assets/app.css') }}?v={{ $assetVersion }}">
    @foreach (\App\Support\PanelAssets::styles() as $style)
        <link rel="stylesheet" href="{{ $style }}">
    @endforeach
    @php
        $panelI18n = [
            'update_confirm' => __('Update the panel now? It is unavailable for a minute during the update.'),
            'channel_confirm' => __('Switch to the :channel channel and install its latest version now?'),
            'update_failed' => __('The update failed. The previous version is still running.'),
        ];
    @endphp
    <meta name="panel-i18n" content="{{ json_encode($panelI18n) }}">
    <script src="{{ asset('assets/app.js') }}?v={{ $assetVersion }}"></script>
    <script src="{{ asset('assets/qrcode.js') }}?v={{ $assetVersion }}" defer></script>
    @foreach (\App\Support\PanelAssets::scripts() as $script)
        <script src="{{ $script }}" defer></script>
    @endforeach
    <script src="{{ asset('assets/alpine.min.js') }}?v={{ $assetVersion }}" defer></script>
</head>
<body>
{{ $slot }}
</body>
</html>
