{{-- Repositories and recent deployments, shared by the admin and client screens. --}}
<div class="page-head">
    <div>
        <h1>{{ __('Git') }}</h1>
        <p class="sub">{{ __('Link a GitHub, GitLab, Bitbucket or Gitea repository to a website. Every push can go live automatically, with build steps and without downtime.') }}</p>
    </div>
    <div class="actions">
        <x-ui.button variant="primary" icon="plus" :href="route($area.'.git.create')">{{ __('Link a repository') }}</x-ui.button>
    </div>
</div>

<x-ui.card :title="__('Repositories')" flush>
    @if ($repos->isEmpty())
        <x-ui.empty icon="git">{{ __('No repositories linked yet.') }}</x-ui.empty>
    @else
    <x-ui.table>
        <x-slot:head>
            <th>{{ __('Repository') }}</th><th>{{ __('Website') }}</th>
            @if ($area === 'admin')<th>{{ __('Customer') }}</th>@endif
            <th>{{ __('Branch') }}</th><th>{{ __('Deploy') }}</th><th>{{ __('Live version') }}</th><th>{{ __('Status') }}</th>
        </x-slot:head>
        @foreach ($repos as $r)
            <tr>
                <td><a class="strong" href="{{ route($area.'.git.show', $r) }}">{{ $r->name }}</a>
                    <div class="muted small">{{ $r->providerLabel() }}</div></td>
                <td>{{ $r->website->domain }} <span class="muted small mono">/{{ $r->deploy_path }}</span></td>
                @if ($area === 'admin')<td>{{ $r->website->customer?->name ?? '–' }}</td>@endif
                <td class="mono">{{ $r->branch }}</td>
                <td><x-ui.pill :status="$r->auto_deploy ? 'info' : 'neutral'" :dot="false">{{ $r->auto_deploy ? __('On push') : __('Manual') }}</x-ui.pill>
                    @if ($r->mode === 'releases')<x-ui.pill status="neutral" :dot="false">{{ __('Zero downtime') }}</x-ui.pill>@endif</td>
                <td>@if ($r->deployed_commit)<span class="mono">{{ $r->deployed_commit['short'] ?? '' }}</span>
                        <span class="muted small">{{ $r->last_deployed_at?->diffForHumans() }}</span>
                    @else<span class="muted small">{{ __('Not deployed yet') }}</span>@endif
                    @if ($r->hasNewCommits())<x-ui.pill status="warn">{{ __('New commits') }}</x-ui.pill>@endif</td>
                <td>@include('git::partials.status-pill', ['repo' => $r])</td>
            </tr>
        @endforeach
    </x-ui.table>
    @endif
</x-ui.card>

@if ($recent->isNotEmpty())
<x-ui.card :title="__('Recent deployments')" flush>
    @include('git::partials.deployments', ['deployments' => $recent, 'showRepo' => true])
</x-ui.card>
@endif
