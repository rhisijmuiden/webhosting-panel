<x-dynamic-component :component="'layouts.'.$area" :title="__('Terminal').' · '.$website->domain">
    <link rel="stylesheet" href="{{ asset('assets/files/filemanager.css') }}?v={{ @filemtime(public_path('assets/files/filemanager.css')) }}">
    <div class="page-head">
        <div>
            <div class="crumbs"><a href="{{ route($area.'.files.index') }}">{{ __('File manager') }}</a> /
                <a href="{{ route($area.'.files.browse', $website) }}">{{ $website->domain }}</a> / {{ __('Terminal') }}</div>
            <h1>{{ __('Terminal') }}</h1>
            <div class="sub">{{ __('A shell in a sandbox: you only see your own webspace, the rest of the server is read-only and hidden. PHP, Composer, WP-CLI, Git and MySQL are available.') }}</div>
        </div>
        <div class="actions">
            @if ($session)
                <form method="POST" action="{{ route($area.'.files.terminal.stop', $website) }}">@csrf @method('DELETE')
                    <button class="btn btn-danger" type="submit">{{ __('End session') }}</button></form>
                <form method="POST" action="{{ route($area.'.files.terminal.start', $website) }}">@csrf
                    <button class="btn" type="submit">{{ __('New session') }}</button></form>
            @endif
            <a class="btn" href="{{ route($area.'.files.browse', $website) }}">{{ __('File manager') }}</a>
        </div>
    </div>
    @error('terminal')<div class="alert alert-bad" role="alert">{{ $message }}</div>@enderror
    <x-ui.card>
        @if ($session)
            <iframe class="term-frame" src="{{ $session }}" title="{{ __('Terminal') }}"></iframe>
            <p class="help">{{ __('The session closes when you leave this page or after :minutes minutes.', ['minutes' => config('files.terminal_minutes')]) }}</p>
        @else
            <div class="empty">
                @include('files::partials.icon', ['name' => 'terminal'])
                <p>{{ __('Start a terminal session for :domain.', ['domain' => $website->domain]) }}</p>
                <form method="POST" action="{{ route($area.'.files.terminal.start', $website) }}">@csrf
                    <button class="btn btn-primary" type="submit">{{ __('Start terminal') }}</button></form>
            </div>
        @endif
    </x-ui.card>
</x-dynamic-component>
