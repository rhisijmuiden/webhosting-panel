<x-layouts.admin :title="$website->domain">
    <div class="page-head">
        <div>
            <div class="crumbs"><a href="{{ route('admin.websites.index') }}">{{ __('Websites') }}</a> / <a href="{{ route('admin.customers.show', $website->customer) }}">{{ $website->customer->displayName() }}</a></div>
            <div class="actions"><h1>{{ $website->domain }}</h1>
                <x-ui.pill :status="$website->status">{{ __(ucfirst($website->status)) }}</x-ui.pill>
                <x-ui.pill :status="$website->ssl_status">SSL: {{ __(ucfirst($website->ssl_status)) }}</x-ui.pill></div>
        </div>
        <div class="actions">
            @if ($website->ssl_status !== 'active')
                <form method="POST" action="{{ route('admin.websites.ssl', $website) }}">@csrf<button class="btn" type="submit"><x-icon name="lock" />{{ __('Request certificate') }}</button></form>
            @endif
            <a class="btn btn-primary" href="https://{{ $website->domain }}" target="_blank" rel="noopener">{{ __('Visit website') }}<x-icon name="external" /></a>
        </div>
    </div>

    @if ($website->status === 'provisioning')
        <div class="alert alert-info" role="status"><x-icon name="refresh" />{{ __('The website is being set up. This page shows the result when it is ready.') }}</div>
    @elseif ($website->status === 'failed' && $website->last_error)
        <div class="alert alert-bad" role="alert"><x-icon name="info" /><div><strong>{{ __('Something went wrong') }}</strong><p class="small mono">{{ $website->last_error }}</p></div></div>
    @endif

    <section class="grid grid-2" style="align-items:start">
        <x-ui.card :title="__('PHP and caching')">
            <form method="POST" action="{{ route('admin.websites.update', $website) }}" class="form">
                @csrf @method('PUT')
                <div class="field">
                    <span class="label">{{ __('PHP version') }}</span>
                    <div class="seg" role="radiogroup" aria-label="{{ __('PHP version') }}">
                        @foreach (\App\Models\Website::PHP_VERSIONS as $v)
                            <label><input type="radio" name="php_version" value="{{ $v }}" @checked($website->php_version === $v)><span>PHP {{ $v }}</span></label>
                        @endforeach
                    </div>
                    <span class="help">{{ __('Only versions that still get security updates are offered. Switching takes a few seconds, without downtime.') }}</span>
                </div>
                <input type="hidden" name="page_cache" value="0">
                <label class="check"><input type="checkbox" name="page_cache" value="1" @checked($website->page_cache)><span>{{ __('Page cache') }}<small>{{ __('Logged-in visitors and shopping carts are skipped automatically.') }}</small></span></label>
                <div><button type="submit" class="btn btn-dark">{{ __('Save') }}</button></div>
            </form>
        </x-ui.card>
        <x-ui.card :title="__('Details')">
            <dl class="kv">
                <dt>{{ __('Type') }}</dt><dd>{{ __(ucfirst($website->profile)) }}</dd>
                <dt>{{ __('Aliases') }}</dt><dd>{{ $website->aliases ? implode(', ', $website->aliases) : '–' }}</dd>
                <dt>{{ __('System user') }}</dt><dd class="mono">{{ $website->system_user ?? '–' }}</dd>
                <dt>{{ __('Web root') }}</dt><dd class="mono">{{ $website->document_root ?? '–' }}</dd>
                <dt>{{ __('Disk') }}</dt><dd>{{ $website->disk_used_mb }} MB @if ($website->disk_quota_mb) / {{ $website->disk_quota_mb }} MB @endif</dd>
                <dt>{{ __('Created') }}</dt><dd>{{ $website->created_at->isoFormat('LLL') }}</dd>
            </dl>
        </x-ui.card>
    </section>

    @if ($website->databases->isNotEmpty())
        <x-ui.card :title="__('Databases')" flush>
            <x-ui.table>
                <x-slot:head><th>{{ __('Database') }}</th><th>{{ __('Users') }}</th><th>{{ __('Origin') }}</th></x-slot:head>
                @foreach ($website->databases as $db)
                    <tr><td class="mono">{{ $db->name }}</td><td class="mono small">{{ implode(', ', $db->users ?? []) ?: '–' }}</td><td>{{ $db->origin === 'migration' ? __('Migration') : __('Panel') }}</td></tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif

    <x-ui.card :title="__('Remove website')">
        <p class="muted small">{{ __('Removes the Nginx site, PHP pool and certificate. This cannot be undone.') }}</p>
        <form method="POST" action="{{ route('admin.websites.destroy', $website) }}" class="form" style="max-width:480px">
            @csrf @method('DELETE')
            <x-ui.field name="confirm" :label="__('Type :domain to confirm', ['domain' => $website->domain])" autocomplete="off" required />
            <label class="check"><input type="checkbox" name="keep_files" value="1" checked><span>{{ __('Keep the files on the server') }}</span></label>
            <div><button class="btn btn-danger" type="submit"><x-icon name="trash" />{{ __('Remove website') }}</button></div>
        </form>
    </x-ui.card>
</x-layouts.admin>
