{{-- Get started tab: ways to put a website on this domain. --}}
<p class="lead">{{ __('Start building a website or app for this domain') }}</p>
<div class="tool-grid">
    @include('client.websites.overview.tile', ['href' => $o->has('files.browse') ? $o->url('files.browse', $site) : null, 'icon' => 'upload', 'label' => __('Upload files'), 'sub' => __('With the file manager or SFTP')])
    @include('client.websites.overview.tile', ['href' => $o->has('apps.create') ? $o->url('apps.create', ['app' => 'wordpress', 'website' => $site->id]) : null, 'icon' => 'wordpress', 'label' => 'WordPress', 'sub' => __('Ready in about a minute')])
    @include('client.websites.overview.tile', ['href' => $o->has('apps.index') ? $o->url('apps.index', ['website' => $site->id]) : null, 'icon' => 'apps', 'label' => __('Other apps'), 'sub' => 'Joomla, Nextcloud'])
    @include('client.websites.overview.tile', ['href' => $o->has('git.create') && $o->allows('git.customer_access') ? $o->url('git.create', ['website' => $site->id]) : null, 'icon' => 'git', 'label' => __('Publish with Git'), 'sub' => 'GitHub, GitLab, Bitbucket, Gitea'])
    @include('client.websites.overview.tile', ['href' => $o->has('backups.index') ? $o->url('backups.index') : null, 'icon' => 'backup', 'label' => __('Restore a backup'), 'sub' => __('From your own backup or a backup location')])
</div>
