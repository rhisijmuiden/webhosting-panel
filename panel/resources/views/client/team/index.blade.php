@php
    $initials = fn ($name) => collect(preg_split('/\s+/', trim($name)))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->join('');
@endphp
<x-layouts.client :title="__('Team')">
    <link rel="stylesheet" href="{{ asset('assets/team.css') }}?v={{ @filemtime(public_path('assets/team.css')) }}">
    <div class="page-head">
        <div>
            <h1>{{ __('Team') }}</h1>
            <p class="sub">{{ __('Give your web developer or colleagues their own login, with access to only what they need.') }}</p>
        </div>
        <div class="actions">
            <x-ui.button icon="list" :href="route('client.team.activity')">{{ __('Activity log') }}</x-ui.button>
            @if ($used < $max)
                <x-ui.button variant="primary" icon="plus" :href="route('client.team.invite')">{{ __('Invite team member') }}</x-ui.button>
            @endif
        </div>
    </div>

    <section class="team-kpis">
        <div class="card stat"><span class="label">{{ __('Team members') }}</span><strong class="value">{{ $members->count() }}</strong><span class="hint">{{ __(':used of :max places used', ['used' => $used, 'max' => $max]) }}</span></div>
        <div class="card stat"><span class="label">{{ __('Open invitations') }}</span><strong class="value">{{ $invitations->count() }}</strong><span class="hint">{{ __('Valid for :days days', ['days' => (int) config('team.invite_days', 7)]) }}</span></div>
        <div class="card stat"><span class="label">{{ __('Two-factor authentication') }}</span>
            <strong class="value team-kpi-small">{{ $customer->team_require_two_factor ? __('Required') : __('Optional') }}</strong>
            <span class="hint">{{ __(':on of :total team members use it', ['on' => $members->filter(fn ($m) => $m->user->hasTwoFactor())->count(), 'total' => $members->count()]) }}</span></div>
    </section>

    <x-ui.card :title="__('People with access')" flush>
        <x-ui.table>
            <x-slot:head><th>{{ __('Person') }}</th><th>{{ __('Websites') }}</th><th>{{ __('Access') }}</th><th>2FA</th><th>{{ __('Last login') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
            @foreach ($owners as $u)
                <tr>
                    <td><span class="who"><span class="avatar avatar-soft">{{ $initials($u->name) }}</span><span><strong>{{ $u->name }}</strong><small class="muted">{{ $u->email }}</small></span></span></td>
                    <td>{{ __('All websites') }}</td>
                    <td><x-ui.pill status="info">{{ __('Owner: everything') }}</x-ui.pill></td>
                    <td>@if ($u->hasTwoFactor())<x-ui.pill status="ok">{{ __('On') }}</x-ui.pill>@else<x-ui.pill status="warn">{{ __('Off') }}</x-ui.pill>@endif</td>
                    <td class="small muted">{{ $u->last_login_at?->diffForHumans() ?? '–' }}</td>
                    <td></td>
                </tr>
            @endforeach
            @foreach ($members as $m)
                <tr>
                    <td><span class="who"><span class="avatar avatar-soft team-avatar">{{ $initials($m->user->name) }}</span><span><strong>{{ $m->user->name }}</strong><small class="muted">{{ $m->user->email }}@if ($m->inviter) · {{ __('invited by :name', ['name' => $m->inviter->name]) }}@endif</small></span></span></td>
                    <td>
                        @if ($m->all_websites)
                            {{ __('All websites') }}
                        @else
                            <span class="team-sites" title="{{ $m->websites->pluck('domain')->join(', ') }}">{{ $m->websites->pluck('domain')->take(2)->join(', ') }}@if ($m->websites->count() > 2) <span class="muted">+{{ $m->websites->count() - 2 }}</span>@endif</span>
                        @endif
                    </td>
                    <td><span class="team-areas">@foreach ($m->areaLabels() as $label)<x-ui.pill :dot="false">{{ $label }}</x-ui.pill>@endforeach</span></td>
                    <td>@if ($m->user->hasTwoFactor())<x-ui.pill status="ok">{{ __('On') }}</x-ui.pill>@else<x-ui.pill :status="$customer->team_require_two_factor ? 'bad' : 'warn'">{{ __('Off') }}</x-ui.pill>@endif</td>
                    <td class="small muted">{{ $m->user->last_login_at?->diffForHumans() ?? __('Never') }}</td>
                    <td class="team-actions">
                        <x-ui.button size="sm" icon="edit" :href="route('client.team.members.edit', $m)">{{ __('Edit access') }}</x-ui.button>
                        <form method="POST" action="{{ route('client.team.members.destroy', $m) }}" data-confirm="{{ __('Remove :name from your team? Their login stops working right away.', ['name' => $m->user->name]) }}">@csrf @method('DELETE')
                            <button class="btn btn-sm btn-danger" type="submit" aria-label="{{ __('Remove') }} {{ $m->user->name }}"><x-icon name="trash" /></button></form>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
        @if ($members->isEmpty())
            <x-ui.empty icon="customers">{{ __('No team members yet. Invite your web developer or a colleague: they get their own login and only see what you choose.') }}</x-ui.empty>
        @endif
    </x-ui.card>

    @if ($invitations->isNotEmpty())
        <x-ui.card :title="__('Open invitations')" flush>
            <x-ui.table>
                <x-slot:head><th>{{ __('E-mail address') }}</th><th>{{ __('Invited by') }}</th><th>{{ __('Valid until') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
                @foreach ($invitations as $inv)
                    <tr>
                        <td><strong>{{ $inv->email }}</strong>@if ($inv->name)<br><span class="small muted">{{ $inv->name }}</span>@endif</td>
                        <td>{{ $inv->inviter?->name ?? '–' }}</td>
                        <td>@if ($inv->isExpired())<x-ui.pill status="bad">{{ __('Expired') }}</x-ui.pill>@else<span title="{{ $inv->expires_at }}">{{ $inv->expires_at->isoFormat('LL') }}</span>@endif</td>
                        <td class="team-actions">
                            <form method="POST" action="{{ route('client.team.invitations.resend', $inv) }}">@csrf<button class="btn btn-sm" type="submit"><x-icon name="send" />{{ __('Send again') }}</button></form>
                            <form method="POST" action="{{ route('client.team.invitations.destroy', $inv) }}" data-confirm="{{ __('Withdraw the invitation for :email?', ['email' => $inv->email]) }}">@csrf @method('DELETE')
                                <button class="btn btn-sm btn-danger" type="submit" aria-label="{{ __('Withdraw') }} {{ $inv->email }}"><x-icon name="close" /></button></form>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif

    <x-ui.card :title="__('Security')">
        <form method="POST" action="{{ route('client.team.settings') }}" class="form team-settings">
            @csrf @method('PUT')
            <input type="hidden" name="team_require_two_factor" value="0">
            <label class="check"><input type="checkbox" name="team_require_two_factor" value="1" @checked($customer->team_require_two_factor) onchange="this.form.requestSubmit()">
                <span>{{ __('Team members must use two-factor authentication') }}<small>{{ __('Team members without it are asked to set it up before they can do anything else.') }}</small></span></label>
            <noscript><button class="btn btn-sm" type="submit">{{ __('Save') }}</button></noscript>
        </form>
    </x-ui.card>
</x-layouts.client>
