<x-layouts.guest :title="__('Join the team')">
    <link rel="stylesheet" href="{{ asset('assets/team.css') }}?v={{ @filemtime(public_path('assets/team.css')) }}">
    @if ($invitation === null)
        <div>
            <h1>{{ __('This invitation no longer works') }}</h1>
            <p class="muted" style="margin-top:6px">{{ __('The link has expired, was already used or was withdrawn. Ask the person who invited you to send a new invitation.') }}</p>
        </div>
        <a class="btn btn-primary" href="{{ route('login') }}">{{ __('Log in') }}</a>
    @elseif (auth()->check())
        <div>
            <h1>{{ __('Join the team') }}</h1>
            <p class="muted" style="margin-top:6px">{{ __('You are logged in as :email. Log out first to accept this invitation with a new login.', ['email' => auth()->user()->email]) }}</p>
        </div>
        <form method="POST" action="{{ route('logout') }}">@csrf<button class="btn btn-primary" type="submit">{{ __('Log out') }}</button></form>
    @else
        <div>
            <span class="eyebrow">{{ $invitation->customer->displayName() }}</span>
            <h1>{{ __('Join the team') }}</h1>
            <p class="muted" style="margin-top:6px">{{ __(':name invited you to help manage their hosting.', ['name' => $invitation->inviter?->name ?? $invitation->customer->displayName()]) }}</p>
        </div>
        <div class="team-invite-summary">
            <div><span class="label">{{ __('Websites') }}</span>
                <strong>{{ $invitation->all_websites ? __('All websites') : $websites->join(', ') }}</strong></div>
            <div><span class="label">{{ __('Access to') }}</span>
                <span class="team-areas">@foreach ($areas as $a)<x-ui.pill>{{ $a }}</x-ui.pill>@endforeach</span></div>
        </div>
        <form method="POST" action="{{ route('team.invitation.accept', $token) }}" class="form">
            @csrf
            <x-ui.field name="email" type="email" :label="__('E-mail address')" :value="$invitation->email" disabled />
            <x-ui.field name="name" :label="__('Your name')" :value="old('name', $invitation->name)" required autofocus autocomplete="name" />
            <x-ui.field name="password" type="password" :label="__('Choose a password')" :help="__('At least 12 characters, with upper and lower case letters and a number.')" required autocomplete="new-password" />
            <x-ui.field name="password_confirmation" type="password" :label="__('Repeat the password')" required autocomplete="new-password" />
            <button type="submit" class="btn btn-primary">{{ __('Accept and log in') }}</button>
        </form>
    @endif
</x-layouts.guest>
