@php
    $editing = $member !== null;
    $title = $editing ? __('Access of :name', ['name' => $member->user->name]) : __('Invite team member');
    $all = (bool) old('all_websites', $editing ? $member->all_websites : $websites->count() === 1);
    $chosenSites = array_map('intval', old('websites', $editing ? $member->websites->pluck('id')->all() : ($websites->count() === 1 ? [$websites->first()->id] : [])));
    $chosenAreas = old('areas', $editing ? $member->areas : ['websites', 'files', 'databases', 'wordpress']);
    $presets = [
        'developer' => ['label' => __('Web developer'), 'areas' => ['websites', 'files', 'databases', 'wordpress', 'git']],
        'mail' => ['label' => __('Email only'), 'areas' => ['mail']],
        'all' => ['label' => __('Everything'), 'areas' => array_keys($areas)],
    ];
@endphp
<x-layouts.client :title="$title">
    <link rel="stylesheet" href="{{ asset('assets/team.css') }}?v={{ @filemtime(public_path('assets/team.css')) }}">
    <div class="page-head">
        <div>
            <div class="crumbs"><a href="{{ route('client.team.index') }}">{{ __('Team') }}</a></div>
            <h1>{{ $title }}</h1>
            <p class="sub">{{ $editing ? $member->user->email : __('They get an e-mail with a link to choose their own password.') }}</p>
        </div>
    </div>

    <form method="POST" action="{{ $editing ? route('client.team.members.update', $member) : route('client.team.invitations.store') }}" class="team-layout"
          x-data="{ all: @js($all), areas: @js(array_values($chosenAreas)), wide: @js($wideAreas),
                    preset(list) { this.areas = [...list]; if (list.some(a => this.wide.includes(a))) this.all = true; } }">
        @csrf
        @if ($editing) @method('PUT') @endif
        <div class="team-main">
            @unless ($editing)
                <x-ui.card :title="__('Who')">
                    <div class="form-grid">
                        <x-ui.field name="email" type="email" :label="__('E-mail address')" required autocomplete="off" />
                        <x-ui.field name="name" :label="__('Name')" :help="__('Optional. They can change it.')" />
                        <x-ui.field name="locale" type="select" :label="__('Language of the invitation')">
                            @foreach ($locales as $code => $name)<option value="{{ $code }}" @selected(old('locale', app()->getLocale()) === $code)>{{ $name }}</option>@endforeach
                        </x-ui.field>
                    </div>
                </x-ui.card>
            @endunless

            <x-ui.card :title="__('Websites')">
                <div class="team-choice">
                    <label class="team-option" :class="all && 'is-on'"><input type="radio" name="all_websites" value="1" @change="all = true" @checked($all)>
                        <span><strong>{{ __('All websites') }}</strong><small class="muted">{{ __('Also websites you add later.') }}</small></span></label>
                    <label class="team-option" :class="!all && 'is-on'"><input type="radio" name="all_websites" value="0" @change="all = false" @checked(! $all)>
                        <span><strong>{{ __('Only these websites') }}</strong><small class="muted">{{ __('Subdomains of a website come along.') }}</small></span></label>
                </div>
                <div class="team-sites-pick" x-show="!all" x-cloak>
                    @forelse ($websites as $w)
                        <label class="check"><input type="checkbox" name="websites[]" value="{{ $w->id }}" @checked(in_array($w->id, $chosenSites, true))> <span class="mono">{{ $w->domain }}</span></label>
                    @empty
                        <p class="small muted">{{ __('You have no websites yet.') }}</p>
                    @endforelse
                </div>
                @error('websites')<span class="error">{{ $message }}</span>@enderror
            </x-ui.card>

            <x-ui.card :title="__('Access to')">
                <x-slot:actions>
                    <span class="team-presets">
                        @foreach ($presets as $p)
                            <button type="button" class="btn btn-sm" @click="preset(@js($p['areas']))">{{ $p['label'] }}</button>
                        @endforeach
                    </span>
                </x-slot:actions>
                <div class="team-area-grid">
                    @foreach ($areas as $key => $a)
                        @php $isWide = in_array($key, $wideAreas, true); @endphp
                        <label class="team-area" :class="{ 'is-on': areas.includes('{{ $key }}'), 'is-off': !all && {{ $isWide ? 'true' : 'false' }} }">
                            <input type="checkbox" name="areas[]" value="{{ $key }}" x-model="areas" @checked(in_array($key, $chosenAreas, true))
                                   @if ($isWide) :disabled="!all" @endif>
                            <span class="team-area-icon"><x-icon :name="$a['icon']" /></span>
                            <span class="team-area-body"><strong>{{ __($a['label']) }}</strong><small>{{ __($a['help']) }}</small>
                                @if ($isWide)<small class="team-area-note">{{ __('Only with access to all websites.') }}</small>@endif</span>
                        </label>
                    @endforeach
                </div>
                @error('areas')<span class="error">{{ $message }}</span>@enderror
            </x-ui.card>

            <div class="actions">
                <button type="submit" class="btn btn-primary">@if ($editing){{ __('Save access') }}@else<x-icon name="send" />{{ __('Send invitation') }}@endif</button>
                <x-ui.button :href="route('client.team.index')">{{ __('Cancel') }}</x-ui.button>
            </div>
        </div>

        <aside class="team-side">
            <x-ui.card :title="__('How team access works')">
                <ul class="checks">
                    <li class="is-ok"><x-icon name="check" /><span>{{ __('Every team member has their own login, so you always see who changed what in the activity log.') }}</span></li>
                    <li class="is-ok"><x-icon name="check" /><span>{{ __('They only see the websites and parts you choose here. You can change it at any time.') }}</span></li>
                    <li class="is-ok"><x-icon name="check" /><span>{{ __('Removing a team member stops their login right away.') }}</span></li>
                    <li class="is-warn"><x-icon name="lock" /><span>{{ __('Team members cannot manage the team or see the activity log. That stays with you.') }}</span></li>
                </ul>
            </x-ui.card>
        </aside>
    </form>
</x-layouts.client>
