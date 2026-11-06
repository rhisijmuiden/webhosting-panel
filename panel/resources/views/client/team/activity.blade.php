<x-layouts.client :title="__('Activity log')">
    <link rel="stylesheet" href="{{ asset('assets/team.css') }}?v={{ @filemtime(public_path('assets/team.css')) }}">
    <div class="page-head">
        <div>
            <div class="crumbs"><a href="{{ route('client.team.index') }}">{{ __('Team') }}</a></div>
            <h1>{{ __('Activity log') }}</h1>
            <p class="sub">{{ __('Who changed what, and when: you and every team member.') }}</p>
        </div>
        <form method="GET" class="actions team-filter">
            <label class="sr-only" for="f-user">{{ __('Person') }}</label>
            <select id="f-user" name="user" class="select" onchange="this.form.submit()">
                <option value="">{{ __('Everyone') }}</option>
                @foreach ($people as $p)<option value="{{ $p->id }}" @selected($who === $p->id)>{{ $p->name }}</option>@endforeach
            </select>
            <noscript><button class="btn" type="submit">{{ __('Filter') }}</button></noscript>
        </form>
    </div>
    <x-ui.card flush>
        @if ($logs->isEmpty())
            <x-ui.empty icon="list">{{ __('Nothing yet.') }}</x-ui.empty>
        @else
        <x-ui.table>
            <x-slot:head><th>{{ __('When') }}</th><th>{{ __('Who') }}</th><th>{{ __('What') }}</th><th>{{ __('Subject') }}</th><th>{{ __('IP address') }}</th></x-slot:head>
            @foreach ($logs as $log)
                <tr>
                    <td class="small" title="{{ $log->created_at }}">{{ $log->created_at->isoFormat('lll') }}</td>
                    <td>@if ($log->user){{ $log->user->name }}@elseif ($log->actor){{ \Illuminate\Support\Str::before($log->actor, ' <') }} <span class="small muted">({{ __('removed') }})</span>@else{{ __('System') }}@endif</td>
                    <td>{{ $log->label() }}</td>
                    <td class="mono small">{{ $log->subject ?? '–' }}</td>
                    <td class="mono small">{{ $log->ip ?? '–' }}</td>
                </tr>
            @endforeach
        </x-ui.table>
        @endif
    </x-ui.card>
    {{ $logs->links('partials.pagination') }}
</x-layouts.client>
