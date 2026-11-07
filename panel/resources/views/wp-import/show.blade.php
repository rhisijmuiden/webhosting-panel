{{-- WordPress import wizard for one website: upload, check, confirm, import, done. --}}
@php
    $step = $import->step();
    $steps = [1 => __('Upload files'), 2 => __('Check'), 3 => __('Confirm changes'), 4 => __('Import'), 5 => __('Done')];
    $failed = $import->status === 'failed';
@endphp
<x-dynamic-component :component="'layouts.'.$area" :title="__('Import WordPress into :domain', ['domain' => $import->domain])">
    @if ($import->busy())<meta http-equiv="refresh" content="3">@endif
    <link rel="stylesheet" href="{{ asset('assets/wp-import.css') }}?v={{ @filemtime(public_path('assets/wp-import.css')) }}">
    <div class="page-head">
        <div>
            <div class="crumbs"><a href="{{ route($area.'.wp-import.index') }}">{{ __('Import WordPress website') }}</a> / {{ $import->domain }}</div>
            <h1>{{ __('Import WordPress into :domain', ['domain' => $import->domain]) }}</h1>
            <p class="sub">{{ __('Started :time', ['time' => $import->created_at->diffForHumans()]) }}@if ($import->user) · {{ $import->user->name }}@endif</p>
        </div>
        @unless ($import->busy() || $import->status === 'done')
            <div class="actions">
                <form method="POST" action="{{ route($area.'.wp-import.destroy', $import) }}" onsubmit="return confirm(@js(__('Cancel this import and remove the uploaded files?')))">
                    @csrf @method('DELETE')<button class="btn btn-ghost" type="submit"><x-icon name="trash" />{{ __('Cancel import') }}</button></form>
            </div>
        @endunless
    </div>

    <ol class="wpi-steps" aria-label="{{ __('Steps') }}">
        @foreach ($steps as $n => $label)
            <li @class(['is-done' => $n < $step || ($n === 5 && $step === 5), 'is-now' => $n === $step && $step < 5, 'is-bad' => $failed && $n === $step])
                @if ($n === $step) aria-current="step" @endif><span class="wpi-num">{{ $n }}</span><span>{{ $label }}</span></li>
        @endforeach
    </ol>

    @error('import')<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror
    @if ($errors->any() && ! $errors->has('import'))
        <div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $errors->first() }}</div>
    @endif

    @switch($import->status)
        @case('upload')
            @include('wp-import.partials.upload')
            @break
        @case('checking')
            @include('wp-import.partials.progress', ['kind' => 'check'])
            @break
        @case('checked')
            @include('wp-import.partials.report')
            @break
        @case('running')
            @include('wp-import.partials.progress', ['kind' => 'run'])
            @break
        @case('done')
            @include('wp-import.partials.done')
            @break
        @default
            @include('wp-import.partials.failed')
    @endswitch
</x-dynamic-component>
