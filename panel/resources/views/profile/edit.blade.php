@component('profile.layout', ['title' => __('Profile')])
<div class="grid grid-2">
    <x-ui.card :title="__('Your details')">
        <form method="POST" action="{{ route('profile.update') }}" class="form">
            @csrf @method('PUT')
            <x-ui.field name="name" :label="__('Name')" :value="$user->name" required />
            <x-ui.field name="email" type="email" :label="__('E-mail address')" :value="$user->email" required />
            <x-ui.field name="locale" type="select" :label="__('Language')" :help="__('The panel and the e-mails you get use this language.')">
                @foreach ($locales as $code => $name)
                    <option value="{{ $code }}" @selected(old('locale', $user->locale) === $code)>{{ $name }}</option>
                @endforeach
            </x-ui.field>
            <div><button class="btn btn-primary" type="submit">{{ __('Save') }}</button></div>
        </form>
    </x-ui.card>
    <x-ui.card :title="__('Password')">
        <form method="POST" action="{{ route('profile.password') }}" class="form">
            @csrf @method('PUT')
            <x-ui.field name="current_password" type="password" :label="__('Current password')" autocomplete="current-password" required />
            <x-ui.field name="password" type="password" :label="__('New password')" :help="__('At least 12 characters, with upper and lower case letters and a number.')" autocomplete="new-password" required />
            <x-ui.field name="password_confirmation" type="password" :label="__('Repeat new password')" autocomplete="new-password" required />
            <div><button class="btn" type="submit">{{ __('Change password') }}</button></div>
        </form>
    </x-ui.card>
</div>
@endcomponent
