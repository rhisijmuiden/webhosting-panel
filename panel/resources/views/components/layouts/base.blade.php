@props(['title' => null])
@include('layouts.base', ['title' => $title, 'slot' => $slot, 'assetVersion' => substr(md5(app(\App\Services\VersionService::class)->version().'|'.@filemtime(public_path('assets/app.css')).'|'.@filemtime(public_path('assets/app.js')).'|'.@filemtime(public_path('assets/mobile.css')).'|'.@filemtime(public_path('assets/mobile.js'))), 0, 8)])
