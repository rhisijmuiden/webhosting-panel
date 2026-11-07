{{-- Shown on a website's page (admin and client): which certificate it serves. --}}
@php
    $scArea = request()->routeIs('admin.*') ? 'admin' : 'client';
    $scCert = \Rhis\Certificates\Models\SiteCertificate::where('website_id', $website->id)->first();
@endphp
@if (\Illuminate\Support\Facades\Route::has($scArea.'.certificates.show'))
    @include('certificates::partials.styles')
    <x-ui.card :title="__('SSL certificate')" id="certificate">
        <x-slot:actions><a class="btn btn-sm" href="{{ route($scArea.'.certificates.show', $website) }}"><x-icon name="lock" />{{ __('Manage certificate') }}</a></x-slot:actions>
        <dl class="kv kv-tight">
            <dt>{{ __('Type') }}</dt><dd>{{ \Rhis\Certificates\Present::summary($website) }}</dd>
            @if ($scCert && $scCert->source !== 'none')
                <dt>{{ __('Valid for') }}</dt><dd><ul class="cert-names">@foreach ((array) $scCert->names as $n)<li>{{ $n }}</li>@endforeach</ul></dd>
                <dt>{{ __('Valid until') }}</dt><dd>@include('certificates::partials.days', ['cert' => $scCert])</dd>
            @endif
        </dl>
        <p class="muted small" style="margin:12px 0 0">{{ __('Need one certificate for all subdomains, or a certificate you bought yourself? Choose it under Manage certificate.') }}</p>
    </x-ui.card>
@endif
