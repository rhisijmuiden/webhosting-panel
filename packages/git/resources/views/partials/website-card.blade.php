{{-- Shown on a website's page (admin and client): linked repositories, or a link to add one. --}}
@php
    $gitArea = request()->routeIs('admin.*') ? 'admin' : 'client';
    $gitRepos = \Rhis\Git\Models\GitRepository::where('website_id', $website->id)->orderBy('name')->get();
@endphp
@if ($gitArea === 'admin' || config('git.customer_access'))
<x-ui.card :title="__('Git')">
    <x-slot:actions><a class="btn btn-sm" href="{{ route($gitArea.'.git.create', ['website' => $website->id]) }}"><x-icon name="plus" />{{ __('Link a repository') }}</a></x-slot:actions>
    @forelse ($gitRepos as $g)
        <div class="row">
            <div><a class="strong" href="{{ route($gitArea.'.git.show', $g) }}">{{ $g->name }}</a>
                <span class="muted small mono">{{ $g->branch }} → /{{ $g->deploy_path }}</span></div>
            <div>@if ($g->deployed_commit)<span class="mono small">{{ $g->deployed_commit['short'] ?? '' }}</span>@endif
                @include('git::partials.status-pill', ['repo' => $g])</div>
        </div>
    @empty
        <p class="muted small">{{ __('Deploy this website from GitHub, GitLab, Bitbucket or Gitea on every push.') }}</p>
    @endforelse
</x-ui.card>
@endif
