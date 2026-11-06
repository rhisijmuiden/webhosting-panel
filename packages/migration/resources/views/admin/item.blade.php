@php
    $r = $item->result ?? [];
    $statusLabels = ['queued' => __('Queued'), 'preparing' => __('Preparing'), 'copying' => __('Copying'), 'copied' => __('Copied'),
        'syncing' => __('Syncing'), 'live' => __('DNS switched'), 'done' => __('Finished'), 'failed' => __('Failed')];
    $stage = match ($item->status) {
        'queued', 'preparing' => 1, 'copying' => 2, 'copied', 'syncing', 'failed' => $item->copied_at ? 3 : 2, 'live' => 4, 'done' => 5, default => 1,
    };
    $steps = [__('Prepare'), __('Copy'), __('Check and sync'), __('Switch DNS'), __('Finished')];
    $d = $item->source_data;
    $kindLabel = ['initial' => __('First copy'), 'sync' => __('Sync'), 'late_mail' => __('Late mail')];
@endphp
<x-layouts.admin :title="$item->domain">
    @if ($item->status === 'queued' || $item->isActive())<meta http-equiv="refresh" content="6">@endif
    <div class="page-head">
        <div>
            <div class="crumbs"><a href="{{ route('admin.migration.index') }}">{{ __('Migration') }}</a> / <a href="{{ route('admin.migration.sources.show', $item->source) }}">{{ $item->source->name }}</a></div>
            <div class="actions"><h1>{{ $item->domain }}</h1><x-ui.pill :status="$item->statusPill()">{{ $statusLabels[$item->status] ?? $item->status }}</x-ui.pill></div>
            <p class="sub">
                @if ($item->customer){{ __('Customer') }}: <a href="{{ route('admin.customers.show', $item->customer) }}">{{ $item->customer->displayName() }}</a>@endif
                @if ($item->website) · <a href="{{ route('admin.websites.show', $item->website) }}">{{ __('Website') }}</a>@endif
                @if ($item->synced_at) · {{ __('Last sync :time', ['time' => $item->synced_at->diffForHumans()]) }}@elseif ($item->copied_at) · {{ __('Copied :time', ['time' => $item->copied_at->diffForHumans()]) }}@endif
            </p>
        </div>
        <div class="actions">
            @if ($item->status === 'failed')
                <form method="POST" action="{{ route('admin.migration.items.retry', $item) }}">@csrf<button class="btn btn-primary" type="submit"><x-icon name="refresh" />{{ __('Try again') }}</button></form>
            @endif
            @if ($item->canSync() && $item->status !== 'failed')
                <form method="POST" action="{{ route('admin.migration.items.sync', $item) }}">@csrf<input type="hidden" name="kind" value="sync"><button class="btn" type="submit"><x-icon name="refresh" />{{ __('Sync again') }}</button></form>
            @endif
            @if (in_array($item->status, ['live', 'done'], true) && ($item->options['mail'] ?? false) && ! empty($d['mailboxes']))
                <form method="POST" action="{{ route('admin.migration.items.sync', $item) }}">@csrf<input type="hidden" name="kind" value="late_mail"><button class="btn" type="submit"><x-icon name="mail" />{{ __('Fetch late mail') }}</button></form>
            @endif
        </div>
    </div>

    @foreach ($agentErrors as $e)<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $e }}</div>@endforeach
    @error('agent')<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror
    @error('confirm')<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror
    @if (session('status'))<div class="alert alert-ok" role="status">{{ session('status') }}</div>@endif

    <x-ui.card>
        <ol class="mig-steps">
            @foreach ($steps as $i => $label)
                <li @class(['done' => $i + 1 < $stage || $item->status === 'done', 'todo' => $i + 1 > $stage])><strong>{{ $label }}</strong></li>
            @endforeach
        </ol>
    </x-ui.card>

    @if ($item->error)
        <div class="alert {{ $item->status === 'failed' ? 'alert-bad' : 'alert-warn' }}" role="alert"><x-icon name="info" /><div><strong>{{ $item->status === 'failed' ? __('Something went wrong') : __('Some parts did not succeed') }}</strong><p class="small mono" style="white-space:pre-line">{{ $item->error }}</p></div></div>
    @endif
    @foreach ($item->noteTexts() as $n)<div class="alert alert-info"><x-icon name="info" />{{ $n }}</div>@endforeach

    @if ($job)
        <x-ui.card :title="$kindLabel[$item->job_kind] ?? __('Copying')">
            <p><x-ui.pill status="warn">{{ __('Running') }}</x-ui.pill> <span class="muted small">{{ __('This page refreshes by itself. You can close it; the copy continues on the server.') }}</span></p>
            @if (! empty($job['log']))<div class="log">{{ implode("\n", array_slice($job['log'], -40)) }}</div>@endif
        </x-ui.card>
    @endif

    <section class="grid grid-2" style="align-items:start">
        <x-ui.card :title="__('What is migrated')">
            <dl class="kv">
                <dt>{{ __('Website') }}</dt><dd>@if ($item->options['files'] && $d['web']){{ ['wordpress' => 'WordPress', 'laravel' => 'Laravel', 'php' => 'PHP'][$item->options['plan']['profile']] ?? 'PHP' }} · PHP {{ $item->options['plan']['php'] }} · <span class="mono small">{{ $item->options['plan']['docroot'] }}</span>@if (isset($r['files']['stats']['total_size']))<br><span class="small muted">{{ $r['files']['stats']['total_size'] }} · {{ __(':count files copied in the last run', ['count' => $r['files']['stats']['files'] ?? 0]) }}</span>@endif @else – @endif</dd>
                <dt>{{ __('Aliases') }}</dt><dd>{{ implode(', ', $item->options['plan']['aliases']) ?: '–' }}</dd>
                <dt>{{ __('Databases') }}</dt><dd>
                    @forelse (($item->options['databases'] ? $d['databases'] : []) as $db)
                        @php $res = collect($r['databases'] ?? [])->firstWhere('name', $db['name']); @endphp
                        <div><span class="mono">{{ $db['name'] }}</span>
                            @if ($res)<x-ui.pill :status="$res['ok'] ? 'ok' : 'bad'">{{ $res['ok'] ? __('Copied') : __('Failed') }}</x-ui.pill>@endif
                            @if ($db['users'])<span class="small muted">{{ __('users') }}: {{ implode(', ', $db['users']) }}</span>@endif</div>
                    @empty – @endforelse
                </dd>
                <dt>{{ __('Mailboxes') }}</dt><dd>
                    @forelse (($item->options['mail'] ? $d['mailboxes'] : []) as $box)
                        @php $res = collect($r['mail'] ?? [])->firstWhere('local', $box['local']); @endphp
                        <div>{{ $box['local'] }}{{ '@'.$item->domain }} <span class="small muted">{{ $box['size_mb'] }} MB</span>
                            @if ($res)<x-ui.pill :status="$res['ok'] ? 'ok' : 'bad'">{{ $res['ok'] ? __('Copied') : __('Failed') }}</x-ui.pill>@endif</div>
                    @empty – @endforelse
                </dd>
                <dt>SSL</dt><dd>
                    @if (($r['ssl']['installed'] ?? false)){{ __('Certificate of the old server is in use until Let\'s Encrypt takes over.') }}
                    @elseif ($item->website){{ __(ucfirst($item->website->ssl_status)) }}@else – @endif
                </dd>
            </dl>
        </x-ui.card>

        <div class="mig-stack">
            @if ($secrets)
                <x-ui.card :title="__('New database passwords')">
                    <p class="small muted">{{ __('These users got a new password. Put it in the website configuration (for WordPress: wp-config.php). They are removed when you mark the migration as finished.') }}</p>
                    <dl class="kv">@foreach ($secrets as $k => $v)<dt class="mono">{{ substr($k, 3) }}</dt><dd class="mono" style="word-break:break-all">{{ $v }}</dd>@endforeach</dl>
                </x-ui.card>
            @endif

            @if ($item->status === 'copied')
                <x-ui.card :title="__('Switch DNS')">
                    <p class="small">{{ __('Test the website here first, for example by adding this server to your hosts file:') }}</p>
                    <pre class="log" style="white-space:pre-wrap">{{ $serverIp ?: '<server-ip>' }}  {{ $item->domain }} www.{{ $item->domain }}</pre>
                    <p class="small">{{ __('Sync again right before the switch, so the latest orders, posts and mail are here. The switch points web and mail to this server and copies the domain\'s own DNS records to Cloudflare.') }}</p>
                    <form method="POST" action="{{ route('admin.migration.items.cutover', $item) }}" class="form">
                        @csrf
                        <label class="check"><input type="checkbox" name="confirm" value="1"><span>{{ __('I checked the copy and synced it just now') }}</span></label>
                        <div><button class="btn btn-dark" type="submit"><x-icon name="globe" />{{ __('Switch DNS to this server') }}</button></div>
                    </form>
                </x-ui.card>
            @endif

            @if (isset($r['dns']))
                <x-ui.card :title="__('DNS')">
                    @if ($r['dns']['mode'] === 'cloudflare')
                        <p>{{ __('Cloudflare was updated. :count of the domain\'s own records were taken over.', ['count' => $r['dns']['imported']]) }}</p>
                        @if (($r['dns']['conflicts'] ?? 0) > 0)<div class="alert alert-warn">{{ __('Some records still conflict. Check the DNS tab of the domain.') }}</div>@endif
                    @else
                        <p class="small">{{ __('The domain is not in the connected Cloudflare account. Load this zone at your DNS provider, or add the domain to Cloudflare and switch again.') }}</p>
                        <pre class="log">{{ $r['dns']['zone'] ?? '' }}</pre>
                    @endif
                    @if ($r['dns']['skipped'])
                        <details><summary class="small">{{ trans_choice(':count record of the old server was not copied|:count records of the old server were not copied', count($r['dns']['skipped'])) }}</summary>
                            <ul class="small mono">@foreach ($r['dns']['skipped'] as $s)<li>{{ $s }}</li>@endforeach</ul></details>
                    @endif
                </x-ui.card>
            @endif

            @if (in_array($item->status, ['live', 'copied'], true))
                <x-ui.card :title="__('Finish')">
                    <p class="small muted">{{ __('When everything works here, mark the migration as finished. You can then remove the domain from Plesk.') }}</p>
                    <form method="POST" action="{{ route('admin.migration.items.finish', $item) }}">@csrf<button class="btn" type="submit"><x-icon name="check" />{{ __('Mark as finished') }}</button></form>
                </x-ui.card>
            @endif
        </div>
    </section>
    @include('migration::admin.styles')
</x-layouts.admin>
