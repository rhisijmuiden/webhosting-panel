@php
    $domainMode = $run->mode === 'domain';
    $title = $domainMode ? __('Change domain') : __('Search & replace');
    $data = in_array($run->status, ['applied', 'failed', 'rolled_back', 'applying'], true) ? ($run->result ?: $run->preview) : $run->preview;
    $step = in_array($run->status, ['applied', 'failed', 'rolled_back'], true) ? 3 : 2;
    $p = $run->params;
    $again = $domainMode ? route($area.'.wordpress.sr.domain', $install) : route($area.'.wordpress.sr.form', $install).'?again='.$run->id;
@endphp
<x-dynamic-component :component="'layouts.'.$area" :title="$title">
    @if ($run->isRunning())<meta http-equiv="refresh" content="3">@endif
    @include('wordpress::search-replace.head', ['title' => $title, 'sub' => $install->label().' · '.$run->created_at->diffForHumans().($run->user ? ' · '.$run->user->name : ''), 'step' => $step])

    <x-ui.card>
        @if ($domainMode)
            <div class="wsr-change">
                <div><small>{{ __('From') }}</small><span class="mono">{{ $data['old_home'] ?? '…' }}</span></div>
                <span class="wsr-arrow" aria-hidden="true">→</span>
                <div><small>{{ __('To') }}</small><span class="mono">{{ $data['new_home'] ?? ($p['scheme'].'://'.$p['host']) }}</span></div>
            </div>
        @else
            <div class="wsr-change">
                <div><small>{{ __('Search for') }}</small><span class="mono">{{ $p['search'] }}</span></div>
                <span class="wsr-arrow" aria-hidden="true">→</span>
                <div><small>{{ __('Replace with') }}</small>@if (($p['replace'] ?? '') === '')<span class="muted">{{ __('(nothing: the text is removed)') }}</span>@else<span class="mono">{{ $p['replace'] }}</span>@endif</div>
            </div>
            <p class="small muted" style="margin-top:8px">{{ ($p['tables'] ?? 'wordpress') === 'all' ? __('All tables in the database') : __('WordPress tables') }}{{ ($p['skip_guid'] ?? true) ? ' · '.__('GUIDs left alone') : '' }}</p>
        @endif
    </x-ui.card>

    @if ($run->isRunning())
        <x-ui.card>
            <p><x-ui.pill status="warn">{{ $run->status === 'previewing' ? __('Preview running') : __('Changing') }}</x-ui.pill>
                <span class="muted small">{{ $run->status === 'previewing' ? __('Counting what would change. Nothing is written.') : __('A snapshot is made, then everything is replaced. This page refreshes by itself.') }}</span></p>
            @if ($area === 'admin' && ! empty($job['log']))<div class="log">{{ implode("\n", $job['log']) }}</div>@endif
        </x-ui.card>
    @elseif ($run->status === 'preview_failed')
        <div class="alert alert-bad" role="alert"><x-icon name="info" /><div><strong>{{ __('The preview failed') }}</strong><p class="small mono">{{ $run->error }}</p></div></div>
        <x-ui.button :href="$again" icon="edit">{{ __('Change and try again') }}</x-ui.button>
    @else
        @if ($run->status === 'applied')
            <div class="alert alert-ok" role="status"><x-icon name="check" />
                <div><strong>{{ trans_choice(':count replacement made.|:count replacements made.', (int) ($data['total'] ?? 0)) }}</strong>
                    @if ($domainMode)<p>{{ __('WordPress now uses :url. Caches have been emptied.', ['url' => $data['new_home'] ?? '']) }} <a href="{{ $data['new_home'] ?? '#' }}" target="_blank" rel="noopener">{{ __('Open the website') }}</a></p>@endif
                    @if (! empty($data['constants']))<p class="small">{{ __('Also changed in wp-config.php: :names', ['names' => implode(', ', $data['constants'])]) }}</p>@endif
                    @if ($data['snapshot'] ?? null)<p class="small">{{ __('Not what you expected? The snapshot from before the change can be put back on the site page.') }}</p>@endif
                </div></div>
            @if ($data['snapshot'] ?? null)
                <form method="POST" action="{{ route($area.'.wordpress.restore', $install) }}" onsubmit="return confirm(@js(__('Undo this change? The database, plugins and themes go back to the snapshot made just before it.')))">
                    @csrf<input type="hidden" name="snapshot" value="{{ $data['snapshot'] }}">
                    <x-ui.button icon="backup">{{ __('Undo (restore the snapshot)') }}</x-ui.button>
                </form>
            @endif
        @elseif ($run->status === 'rolled_back')
            <div class="alert alert-warn" role="status"><x-icon name="info" />{{ __('The website stopped working after the change (HTTP :code), so everything was put back as it was. Nothing was lost.', ['code' => $data['health']['after'] ?? '?']) }}</div>
        @elseif ($run->status === 'failed')
            <div class="alert alert-bad" role="alert"><x-icon name="info" /><div><strong>{{ __('The change failed') }}</strong><p class="small mono">{{ $run->error }}</p>
                @if ($data['snapshot'] ?? null)<p class="small">{{ __('The snapshot from before the change has been put back.') }}</p>@endif</div></div>
        @endif

        <section class="grid grid-2" style="align-items:start">
            <x-ui.card :title="$run->status === 'applied' ? __('Replaced') : __('Would be replaced')">
                <p class="wsr-total">{{ number_format((int) ($data['total'] ?? 0), 0, ',', '.') }}</p>
                <p class="muted small">{{ trans_choice('in :count column|in :count columns', count($data['tables'] ?? [])) }}</p>
                @if (! empty($data['tables']))
                    <x-ui.table>
                        <x-slot:head><th>{{ __('Table') }}</th><th>{{ __('Column') }}</th><th class="num">{{ __('Replacements') }}</th></x-slot:head>
                        @foreach (array_slice($data['tables'], 0, 50) as $t)
                            <tr><td class="mono">{{ $t['table'] }}</td><td class="mono">{{ $t['column'] }}</td><td class="num">{{ number_format((int) $t['count'], 0, ',', '.') }}</td></tr>
                        @endforeach
                    </x-ui.table>
                @endif
            </x-ui.card>

            @if ($run->status === 'previewed')
                <x-ui.card :title="__('Confirm')">
                    @if ((int) ($data['total'] ?? 0) === 0)
                        <x-ui.empty icon="search">{{ __('Nothing found: there is nothing to replace.') }}</x-ui.empty>
                        <x-ui.button :href="$again" icon="edit">{{ __('Change the search') }}</x-ui.button>
                    @elseif ($run->isExpired())
                        <p class="muted small">{{ __('This preview is too old. Run the preview again so you see what will change now.') }}</p>
                        <x-ui.button :href="$again" icon="refresh">{{ __('New preview') }}</x-ui.button>
                    @else
                        <p class="small">{{ __('Check the examples below. When you confirm:') }}</p>
                        <ul class="small">
                            <li>{{ __('a snapshot of the database, plugins and themes is made first;') }}</li>
                            <li>{{ __('every replacement is made, serialized data is kept intact;') }}</li>
                            <li>{{ __('caches are emptied and the website is checked: if it stops working, the snapshot is put back automatically.') }}</li>
                        </ul>
                        <form method="POST" action="{{ route($area.'.wordpress.sr.apply', $run) }}" class="form"
                            onsubmit="return confirm(@js(trans_choice('Make :count replacement now?|Make :count replacements now?', (int) $data['total'])))">
                            @csrf
                            @if ($area === 'admin')
                                <label class="check small"><input type="checkbox" name="skip_snapshot" value="1"><span>{{ __('Skip the snapshot (faster, no automatic rollback)') }}</span></label>
                            @endif
                            <div class="actions">
                                <x-ui.button variant="primary" icon="check">{{ $domainMode ? __('Change the domain') : __('Replace everything') }}</x-ui.button>
                                <x-ui.button :href="$again" icon="edit">{{ __('Change') }}</x-ui.button>
                            </div>
                        </form>
                    @endif
                </x-ui.card>
            @elseif ($step === 3)
                <x-ui.card :title="__('Next')">
                    <div class="actions" style="flex-wrap:wrap">
                        <x-ui.button :href="$again" icon="edit">{{ $domainMode ? __('Change domain again') : __('New search & replace') }}</x-ui.button>
                        <x-ui.button :href="route($area.'.wordpress.show', $install)" icon="wordpress">{{ __('Back to the site') }}</x-ui.button>
                    </div>
                    @if ($domainMode && $run->status === 'applied')
                        <p class="small muted" style="margin-top:12px">{{ __('Visitors who still use the old address keep seeing the website. To send them to the new address, set the old domain alias to "redirect" under Domain aliases.') }}</p>
                    @endif
                </x-ui.card>
            @endif
        </section>

        @if (! empty($run->preview['samples']))
            <x-ui.card :title="__('Examples from the preview')">
                <p class="muted small">{{ ($run->preview['samples_truncated'] ?? false) ? __('The first :n changes; the rest are of the same kind.', ['n' => count($run->preview['samples'])]) : __('All changes.') }}</p>
                @foreach ($run->preview['samples'] as $s)
                    <div class="wsr-sample">
                        <header class="mono">{{ $s['table'] }}.{{ $s['column'] }} · {{ __('row') }} {{ $s['row'] }}</header>
                        <pre class="wsr-old">{{ $s['old'] }}</pre>
                        <pre class="wsr-new">{{ $s['new'] }}</pre>
                    </div>
                @endforeach
            </x-ui.card>
        @endif

        @if ($area === 'admin' && ! empty($job['log']))
            <details><summary class="small">{{ __('Log') }}</summary><div class="log">{{ implode("\n", $job['log']) }}</div></details>
        @endif
    @endif
</x-dynamic-component>
