{{-- WordPress import wizard: choose the website, and the imports so far. --}}
@php
    $statusLabel = ['upload' => __('Waiting for files'), 'checking' => __('Checking'), 'checked' => __('Ready to import'),
        'running' => __('Importing'), 'done' => __('Imported'), 'failed' => __('Failed')];
    $statusPill = ['upload' => 'neutral', 'checking' => 'warn', 'checked' => 'info', 'running' => 'warn', 'done' => 'ok', 'failed' => 'bad'];
@endphp
<x-dynamic-component :component="'layouts.'.$area" :title="__('Import WordPress website')">
    <link rel="stylesheet" href="{{ asset('assets/wp-import.css') }}?v={{ @filemtime(public_path('assets/wp-import.css')) }}">
    <div class="page-head">
        <div>
            <h1>{{ __('Import WordPress website') }}</h1>
            <p class="sub">{{ __('Move a WordPress website from another hosting company in a few steps: upload two files, check, confirm, done.') }}</p>
        </div>
    </div>
    @if (session('status'))<div class="alert alert-ok" role="status">{{ session('status') }}</div>@endif

    <div class="wpi-layout">
        <div class="wpi-main">
            <x-ui.card :title="__('Start an import')">
                <ol class="wpi-intro">
                    <li><span class="wpi-num">1</span><div><strong>{{ __('Export at your old host') }}</strong><small>{{ __('A zip of the WordPress folder and an export of the database.') }}</small></div></li>
                    <li><span class="wpi-num">2</span><div><strong>{{ __('Upload both files') }}</strong><small>{{ __('They are checked first: nothing changes yet.') }}</small></div></li>
                    <li><span class="wpi-num">3</span><div><strong>{{ __('Confirm the changes') }}</strong><small>{{ __('New database connection, address and old paths.') }}</small></div></li>
                    <li><span class="wpi-num">4</span><div><strong>{{ __('Your website is live') }}</strong><small>{{ __('Files, database and wp-config.php are set up for you.') }}</small></div></li>
                </ol>
                @if ($websites->isEmpty())
                    <x-ui.empty icon="globe">{{ __('There is no active website to import into yet.') }}</x-ui.empty>
                @else
                    <form method="POST" action="{{ route($area.'.wp-import.store') }}" class="form wpi-start">
                        @csrf
                        <x-ui.field name="website" type="select" :label="__('Import into website')" required>
                            <option value="">{{ __('Choose a website') }}</option>
                            @foreach ($websites as $w)
                                <option value="{{ $w->id }}" @selected((int) old('website', $selected) === $w->id)>{{ $w->domain }}@if ($area === 'admin' && $w->customer) · {{ $w->customer->name }}@endif</option>
                            @endforeach
                        </x-ui.field>
                        <div><x-ui.button variant="primary" icon="wordpress">{{ __('Start the import wizard') }}</x-ui.button></div>
                    </form>
                    <p class="small muted">{{ __('Tip: import into a subdomain first (for example test.yourdomain.nl) to try it out while the old website stays online.') }}</p>
                @endif
            </x-ui.card>

            @if ($imports->isNotEmpty())
                <x-ui.card :title="__('Imports')" flush>
                    <x-ui.table>
                        <x-slot:head><th>{{ __('Website') }}</th><th>{{ __('Status') }}</th><th>{{ __('Started') }}</th><th></th></x-slot:head>
                        @foreach ($imports as $i)
                            <tr>
                                <td class="strong">{{ $i->domain }}</td>
                                <td><x-ui.pill :status="$statusPill[$i->status] ?? 'neutral'">{{ $statusLabel[$i->status] ?? $i->status }}</x-ui.pill></td>
                                <td class="muted small">{{ ($i->started_at ?? $i->created_at)->diffForHumans() }}@if ($i->user) · {{ $i->user->name }}@endif</td>
                                <td class="right"><a class="btn btn-sm" href="{{ route($area.'.wp-import.show', $i) }}">{{ $i->status === 'done' ? __('View') : __('Continue') }}</a></td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                </x-ui.card>
            @endif
        </div>
        <aside class="wpi-side">
            @include('wp-import.partials.help')
        </aside>
    </div>
</x-dynamic-component>
