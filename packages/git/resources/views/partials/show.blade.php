{{-- One repository: deploy, setup (deploy key + webhook), releases, settings and history. Shared by admin and client. --}}
@php
    $r = fn ($name, $extra = []) => route($area.'.git.'.$name, [$repo, ...$extra]);
    $deployed = $repo->deployed_commit;
    $latest = $repo->latest_commit;
    $releases = $status['releases'] ?? [];
    $confirm = fn (string $text) => 'if (!confirm('.\Illuminate\Support\Js::from($text).')) $event.preventDefault()';
    $hookHelp = [
        'github' => __('GitHub: Settings → Webhooks → Add webhook. Content type application/json, secret below, event "Just the push event".'),
        'gitlab' => __('GitLab: Settings → Webhooks. Paste the address, put the secret in "Secret token", tick "Push events".'),
        'bitbucket' => __('Bitbucket: Repository settings → Webhooks → Add webhook. Paste the address and the secret, trigger "Repository push".'),
        'gitea' => __('Gitea / Forgejo: Settings → Webhooks → Add webhook → Gitea. Paste the address and the secret, trigger "Push events".'),
        'other' => __('Add a webhook for push events with this address. Send the secret as HMAC-SHA256 signature (X-Hub-Signature-256) or in the X-Webhook-Token header.'),
    ];
    $keyHelp = [
        'github' => __('GitHub: Settings → Deploy keys → Add deploy key. Read access is enough.'),
        'gitlab' => __('GitLab: Settings → Repository → Deploy keys. Read access is enough.'),
        'bitbucket' => __('Bitbucket: Repository settings → Access keys → Add key.'),
        'gitea' => __('Gitea / Forgejo: Settings → Deploy keys → Add deploy key. Read access is enough.'),
        'other' => __('Add this public key as a read-only deploy key on your git server.'),
    ];
@endphp
<div class="page-head">
    <div>
        <div class="crumbs"><a href="{{ route($area.'.git.index') }}">{{ __('Git') }}</a> / {{ $repo->website->domain }}</div>
        <div class="actions"><h1>{{ $repo->name }}</h1> @include('git::partials.status-pill', ['repo' => $repo])</div>
        <p class="sub"><span class="mono">{{ $repo->branch }}</span> → {{ $repo->website->domain }}/<span class="mono">{{ $repo->deploy_path }}</span>
            · {{ $repo->auto_deploy ? __('deploys on every push') : __('deploys manually') }}
            @if ($web = $repo->webUrl()) · <a href="{{ $web }}" target="_blank" rel="noopener">{{ $repo->providerLabel() }} <x-icon name="external" /></a>@endif</p>
    </div>
    <div class="actions">
        <form method="POST" action="{{ $r('fetch') }}">@csrf<x-ui.button icon="refresh">{{ __('Check for new commits') }}</x-ui.button></form>
        <form method="POST" action="{{ $r('deploy') }}">@csrf<x-ui.button variant="primary" icon="send">{{ __('Deploy now') }}</x-ui.button></form>
    </div>
</div>

@foreach ($agentErrors as $e)<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $e }}</div>@endforeach
@foreach ($errors->get('agent') as $e)<div class="alert alert-bad" role="alert"><x-icon name="info" /><div>{{ $e }}</div></div>@endforeach
@if ($repo->status === 'failed' && $repo->last_error)
    <div class="alert alert-bad" role="alert"><x-icon name="info" /><div><strong>{{ __('The last deployment failed') }}</strong>
        <p class="small mono">{{ __($repo->last_error) }}</p>
        @if ($repo->mode === 'releases')<p class="small">{{ __('Visitors did not notice: the website still runs the previous release.') }}</p>@endif</div></div>
@endif

<section class="grid grid-stats">
    <div class="card stat"><span class="label">{{ __('Live version') }}</span>
        <strong class="value mono">{{ $deployed['short'] ?? '–' }}</strong>
        <span class="hint">{{ $deployed ? \Illuminate\Support\Str::limit(($deployed['message'] ?? '').' · '.($deployed['author'] ?? ''), 70) : __('Not deployed yet') }}</span></div>
    <div class="card stat"><span class="label">{{ __('Newest on :branch', ['branch' => $repo->branch]) }}</span>
        <strong class="value mono">{{ $latest['short'] ?? '–' }}</strong>
        <span class="hint">@if ($repo->hasNewCommits())<x-ui.pill status="warn">{{ __('Not live yet') }}</x-ui.pill>@elseif ($latest){{ __('Live') }}@else{{ __('Unknown, check for new commits') }}@endif</span></div>
    <div class="card stat"><span class="label">{{ __('Last deployment') }}</span>
        <strong class="value">{{ $repo->last_deployed_at?->diffForHumans(short: true) ?? '–' }}</strong>
        <span class="hint">{{ $repo->last_webhook_at ? __('Last push received :time', ['time' => $repo->last_webhook_at->diffForHumans()]) : __('No push received yet') }}</span></div>
</section>

<section class="grid grid-2" style="align-items:start">
    <x-ui.card :title="__('1. Deploy key')">
        @if ($repo->isSsh())
            <p class="muted small">{{ $keyHelp[$repo->provider] ?? $keyHelp['other'] }}</p>
            <div class="copy-row" x-data="{ copied: false }"><code class="mono">{{ $repo->public_key }}</code>
                <button type="button" class="btn btn-sm" x-on:click="navigator.clipboard.writeText(@js($repo->public_key)); copied = true"><span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span></button></div>
            <p class="small muted mono">{{ $repo->fingerprint }}</p>
        @else
            <p class="muted small">{{ __('This is an https:// address: that works for public repositories without a key. For a private repository use the SSH address and the deploy key.') }}</p>
        @endif
        <div class="actions">
            <form method="POST" action="{{ $r('check') }}">@csrf<x-ui.button size="sm" icon="check">{{ __('Test connection') }}</x-ui.button></form>
            @if ($repo->isSsh())
                <form method="POST" action="{{ $r('key') }}" x-on:submit="{{ $confirm(__('Make a new deploy key? The old one stops working.')) }}">@csrf<x-ui.button size="sm" icon="key">{{ __('New key') }}</x-ui.button></form>
            @endif
        </div>
    </x-ui.card>

    <x-ui.card :title="__('2. Webhook')">
        <p class="muted small">{{ $hookHelp[$repo->provider] ?? $hookHelp['other'] }}</p>
        <div x-data="{ copied: '', show: false }" style="display:grid;gap:8px">
            <div class="copy-row"><code class="mono">{{ $repo->webhookUrl() }}</code>
                <button type="button" class="btn btn-sm" x-on:click="navigator.clipboard.writeText(@js($repo->webhookUrl())); copied = 'url'"><span x-text="copied === 'url' ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span></button></div>
            <div class="copy-row"><code class="mono" x-text="show ? @js($repo->webhook_secret) : '••••••••••••••••••••'">••••••••••••••••••••</code>
                <button type="button" class="btn btn-sm" x-on:click="show = !show"><span x-text="show ? @js(__('Hide')) : @js(__('Show'))">{{ __('Show') }}</span></button>
                <button type="button" class="btn btn-sm" x-on:click="navigator.clipboard.writeText(@js($repo->webhook_secret)); copied = 'secret'"><span x-text="copied === 'secret' ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span></button></div>
        </div>
        <div class="actions" style="margin-top:10px">
            <form method="POST" action="{{ $r('secret') }}" x-on:submit="{{ $confirm(__('Make a new webhook secret? Pushes are refused until you update it in your repository.')) }}">@csrf<x-ui.button size="sm" icon="lock">{{ __('New secret') }}</x-ui.button></form>
        </div>
    </x-ui.card>
</section>

@if ($repo->mode === 'releases')
<x-ui.card :title="__('Releases')" flush>
    @if (empty($releases))
        <x-ui.empty icon="backup">{{ __('Every deployment makes a release. You can switch back to an earlier one at any time.') }}</x-ui.empty>
    @else
    <x-ui.table>
        <x-slot:head><th>{{ __('Release') }}</th><th>{{ __('Commit') }}</th><th>{{ __('Made') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
        @foreach ($releases as $rel)
            <tr>
                <td class="mono">{{ $rel['name'] }} @if ($rel['current'])<x-ui.pill status="ok">{{ __('Live') }}</x-ui.pill>@endif</td>
                <td>@if ($url = $repo->commitUrl(strlen($rel['commit']) === 40 ? $rel['commit'] : null))<a class="mono" href="{{ $url }}">{{ substr($rel['commit'], 0, 7) }}</a>@else<span class="mono">{{ substr($rel['commit'], 0, 7) }}</span>@endif</td>
                <td>{{ \Illuminate\Support\Carbon::createFromTimestamp($rel['created'])->diffForHumans() }}</td>
                <td class="num">@unless ($rel['current'])
                    <form method="POST" action="{{ $r('rollback') }}" x-on:submit="{{ $confirm(__('Switch the website back to this release? This takes effect immediately.')) }}">@csrf<input type="hidden" name="release" value="{{ $rel['name'] }}"><button class="btn btn-sm" type="submit"><x-icon name="backup" />{{ __('Roll back') }}</button></form>
                @endunless</td>
            </tr>
        @endforeach
    </x-ui.table>
    @endif
</x-ui.card>
@endif

<x-ui.card :title="__('Deployments')" flush>
    @if ($deployments->isEmpty())
        <x-ui.empty icon="git">{{ __('No deployments yet. Push to :branch or press Deploy now.', ['branch' => $repo->branch]) }}</x-ui.empty>
    @else
        @include('git::partials.deployments', ['deployments' => $deployments->each(fn ($d) => $d->setRelation('repository', $repo))])
    @endif
</x-ui.card>

<x-ui.card :title="__('Settings')">
    <form method="POST" action="{{ $r('update') }}" class="form"
        x-data="{ mode: @js(old('mode', $repo->mode)), path: @js(old('deploy_path', $repo->deploy_path)), shared: @js(old('shared', implode("\n", $repo->shared ?? []))) }">
        @csrf @method('PUT')
        @include('git::partials.settings', ['website' => $repo->website])
        <div><button type="submit" class="btn btn-dark">{{ __('Save') }}</button></div>
    </form>
</x-ui.card>

<x-ui.card :title="__('Remove repository')">
    <p class="muted small">{{ __('Stops deployments and removes the repository from the server. The files of the website stay where they are.') }}</p>
    <form method="POST" action="{{ $r('destroy') }}" x-on:submit="{{ $confirm(__('Remove this repository from the website? The website files are kept.')) }}">
        @csrf @method('DELETE')<x-ui.button variant="danger" icon="trash">{{ __('Remove') }}</x-ui.button></form>
</x-ui.card>
