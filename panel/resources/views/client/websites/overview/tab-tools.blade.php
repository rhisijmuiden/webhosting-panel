{{-- Dashboard tab: every tool for this website, grouped. --}}
@php
    $git = $o->has('git.index') && $o->allows('git.customer_access');
    $findings = $o->security['total'] ?? null;
@endphp
<div class="tool-group">
    <h2>{{ __('Files & data') }}</h2>
    <div class="tool-grid">
        @include('client.websites.overview.tile', ['href' => $o->has('files.browse') ? $o->url('files.browse', $site) : null, 'icon' => 'folder', 'label' => __('File manager'), 'sub' => __('Upload, edit and unzip')])
        @include('client.websites.overview.tile', ['href' => $o->url('websites.show', [$site, 'tab' => 'hosting']).'#connection', 'icon' => 'plug', 'label' => __('Connection details'), 'sub' => __('For SFTP and databases')])
        @include('client.websites.overview.tile', ['href' => $o->has('ftp-accounts.index') ? $o->url('websites.show', [$site, 'tab' => 'hosting']).'#ftp-accounts' : null, 'icon' => 'upload', 'label' => __('FTP accounts'),
            'sub' => ($ftpCount = \App\Models\FtpAccount::where('website_id', $site->id)->count()) ? trans_choice(':count account|:count accounts', $ftpCount) : __('Extra logins with their own folder')])
        @include('client.websites.overview.tile', ['href' => $o->url('websites.show', [$site, 'tab' => 'hosting']).'#databases', 'icon' => 'database', 'label' => __('Databases'), 'sub' => trans_choice(':count database|:count databases', $site->databases()->count())])
        @include('client.websites.overview.tile', ['href' => $o->has('backups.index') ? $o->url('backups.index') : null, 'icon' => 'backup', 'label' => __('Backups'),
            'sub' => $o->lastBackup ? __('Last one :time', ['time' => ($o->lastBackup->finished_at ?? $o->lastBackup->created_at)->diffForHumans()]) : __('Make and restore backups')])
    </div>
</div>
<div class="tool-group">
    <h2>{{ __('Developer tools') }}</h2>
    <div class="tool-grid">
        @include('client.websites.overview.tile', ['href' => $o->url('websites.show', [$site, 'tab' => 'hosting']).'#php', 'icon' => 'code', 'label' => 'PHP', 'sub' => __('Version :v', ['v' => $site->php_version])])
        @include('client.websites.overview.tile', ['href' => $git ? ($o->gitRepos->count() === 1 ? $o->url('git.show', $o->gitRepos->first()) : ($o->gitRepos->isEmpty() ? $o->url('git.create', ['website' => $site->id]) : $o->url('git.index'))) : null,
            'icon' => 'git', 'label' => __('Git deployments'), 'sub' => $o->gitRepos->isEmpty() ? __('Deploy on every push') : trans_choice(':count repository|:count repositories', $o->gitRepos->count())])
        @include('client.websites.overview.tile', ['href' => $o->has('cron.index') && $o->allows('cron.customer_access') ? $o->url('cron.index', ['website' => $site->id]) : null, 'icon' => 'clock', 'label' => __('Cron jobs'),
            'sub' => class_exists(\Rhis\Cron\Models\CronJob::class) && ($n = \Rhis\Cron\Models\CronJob::where('website_id', $site->id)->count()) ? trans_choice(':count cron job|:count cron jobs', $n) : __('Run tasks on a schedule')])
        @include('client.websites.overview.tile', ['href' => $o->canUseTerminal() ? $o->url('files.terminal', $site) : null, 'icon' => 'terminal', 'label' => __('Web terminal'), 'sub' => __('Command line in your browser')])
        @include('client.websites.overview.tile', ['href' => $o->allows('site-logs.customer_access') ? $o->url('site-logs.show', $site) : null, 'icon' => 'list', 'label' => __('Logs and errors'), 'sub' => __('Access, error and PHP logs')])
        @include('client.websites.overview.tile', ['href' => $o->has('apps.index') ? $o->url('apps.index') : null, 'icon' => 'apps', 'label' => __('App installer'), 'sub' => __('WordPress, Joomla, Nextcloud')])
        @if ($o->has('clones.create'))
            @include('client.websites.overview.tile', ['href' => $o->allows('subdomains.customer_clone') && $site->status === 'active' ? $o->url('clones.create', $site) : null, 'icon' => 'migrate', 'label' => __('Clone website'), 'sub' => __('Test copy on a subdomain')])
            @include('client.websites.overview.tile', ['href' => $o->url('websites.show', [$site, 'tab' => 'hosting']).'#subdomains', 'icon' => 'globe', 'label' => __('Subdomains'),
                'sub' => $site->isSubdomain() ? __('Subdomain of :domain', ['domain' => $site->parent?->domain]) : trans_choice(':count subdomain|:count subdomains', $site->subdomains()->count())])
        @endif
        @include('client.websites.overview.tile', ['href' => $o->wordpress->isNotEmpty() ? $o->url('websites.show', [$site, 'tab' => 'wordpress']) : null, 'icon' => 'wordpress', 'label' => 'WordPress',
            'sub' => $o->wordpress->sum(fn ($i) => $i->outdatedCount()) ? trans_choice(':count update|:count updates', $o->wordpress->sum(fn ($i) => $i->outdatedCount())) : __('Up to date'),
            'tone' => $o->wordpress->contains(fn ($i) => $i->isOutdated()) ? 'warn' : null])
        @include('client.websites.overview.tile', ['href' => $o->url('websites.show', [$site, 'tab' => 'hosting']).'#cache', 'icon' => 'gauge', 'label' => __('Speed & caching'), 'sub' => $site->page_cache ? __('Page cache on') : __('Page cache off')])
        @includeIf('web-rules::tile', ['o' => $o, 'site' => $site, 'kind' => 'redirects'])
    </div>
</div>
<div class="tool-group">
    <h2>{{ __('Security') }}</h2>
    <div class="tool-grid">
        @include('client.websites.overview.tile', ['href' => $o->url('websites.show', [$site, 'tab' => 'hosting']).'#connection', 'icon' => 'lock', 'label' => __('SSL/TLS certificate'),
            'sub' => $site->ssl_status === 'active' ? __('Let\'s Encrypt, renewed automatically') : __('No valid certificate yet'), 'tone' => $site->ssl_status === 'active' ? 'ok' : 'warn'])
        @includeIf('web-rules::tile', ['o' => $o, 'site' => $site, 'kind' => 'access'])
        @include('client.websites.overview.tile', ['href' => $o->has('protection.show') ? $o->url('protection.show', $o->isAdmin() ? [] : $site) : null, 'icon' => 'shield', 'label' => __('Login protection'), 'sub' => __('Block password guessing')])
        @include('client.websites.overview.tile', ['href' => $findings !== null ? $o->url('vulns.index') : null, 'icon' => 'firewall', 'label' => __('Security alerts'),
            'sub' => $findings ? trans_choice(':count problem|:count problems', $findings) : __('None found'), 'tone' => $findings ? (($o->security['malware'] ?? 0) ? 'bad' : 'warn') : 'ok'])
    </div>
</div>
