{{-- Hosting & DNS tab: settings of the website itself, connection details, databases, aliases and DNS. --}}
<div class="tool-grid tool-grid-wide">
    @php $dns = $o->mailDomain ? $o->mailDomain->dnsPill() : null; @endphp
    @include('client.websites.overview.tile', ['href' => $o->mailLink('dns'), 'icon' => 'globe', 'label' => __('DNS records'),
        'sub' => match ($dns) { 'ok' => __('All records are correct'), 'warn' => __('Some records need attention'), 'bad' => __('There is a DNS problem'), null => null, default => __('Not checked yet') },
        'tone' => in_array($dns, ['ok', 'warn', 'bad'], true) ? $dns : null])
    @if ($o->has('domain-aliases.index'))
        @include('client.websites.overview.tile', ['href' => '#domain-aliases', 'icon' => 'link', 'label' => __('Domain aliases'), 'sub' => __('Extra domains for this website')])
    @endif
</div>

<section class="grid grid-2" style="align-items:start" id="php">
    <x-ui.card :title="__('PHP version')">
        <form method="POST" action="{{ $o->url('websites.update', $site) }}" class="form">
            @csrf @method('PUT')
            <p class="muted small">{{ __('Only versions that still get security updates are offered. Switching takes a few seconds, without downtime.') }}</p>
            <div class="seg" role="radiogroup" aria-label="{{ __('PHP version') }}">
                @foreach (\App\Models\Website::PHP_VERSIONS as $v)
                    <label><input type="radio" name="php_version" value="{{ $v }}" @checked($site->php_version === $v)><span>PHP {{ $v }}</span></label>
                @endforeach
            </div>
            <div><button type="submit" class="btn btn-dark">{{ __('Save') }}</button></div>
        </form>
    </x-ui.card>
    <x-ui.card :title="__('Speed & caching')" id="cache">
        <form method="POST" action="{{ $o->url('websites.update', $site) }}" class="form">
            @csrf @method('PUT')
            <p class="muted small">{{ __('Pages are served straight from the server cache. Logged-in visitors and shopping carts are skipped automatically.') }}</p>
            <input type="hidden" name="page_cache" value="0">
            <label class="check"><input type="checkbox" name="page_cache" value="1" @checked($site->page_cache)><span>{{ __('Page cache') }}</span></label>
            <div><button type="submit" class="btn btn-dark">{{ __('Save') }}</button></div>
        </form>
    </x-ui.card>
    <x-ui.card :title="__('Connection details')" id="connection">
        <dl class="kv kv-tight">
            <dt>{{ __('SFTP server') }}</dt><dd class="mono">{{ $o->serverIp ?? request()->getHost() }}</dd>
            <dt>{{ __('SFTP user') }}</dt><dd class="mono">{{ $site->system_user ?? '–' }}</dd>
            <dt>{{ __('Website folder') }}</dt><dd class="mono">{{ $site->document_root ?? '–' }}</dd>
            <dt>{{ __('Database server') }}</dt><dd class="mono">localhost</dd>
            <dt>{{ __('Certificate') }}</dt><dd>{{ class_exists(\Rhis\Certificates\Present::class) ? \Rhis\Certificates\Present::summary($site) : ($site->ssl_status === 'active' ? __('Let\'s Encrypt, renewed automatically') : __(ucfirst($site->ssl_status))) }}</dd>
        </dl>
    </x-ui.card>
    <x-ui.card :title="__('Databases')" id="databases">
        @forelse ($site->databases as $db)
            <div class="row"><span class="mono strong">{{ $db->name }}</span><span class="muted small mono">{{ implode(', ', $db->users ?? []) }}</span></div>
        @empty
            <p class="muted small">{{ __('This website has no database yet. The app installer creates one for you.') }}</p>
        @endforelse
    </x-ui.card>
    @unless ($o->isAdmin())
        @includeIf('security::client.site-card', ['website' => $site])
    @endunless
</section>
@include('partials.website-php', ['website' => $site, 'action' => $o->url('websites.php', $site)])
@includeIf('domain-aliases::partials.website-card', ['website' => $site])
@includeIf('subdomains::partials.website-card', ['website' => $site])
@includeIf('certificates::partials.website-card', ['website' => $site])
