@props(['title' => null])
@include('layouts.base', ['title' => $title, 'slot' => $slot, 'assetVersion' => substr(md5(app(\App\Services\VersionService::class)->version()), 0, 8)])
