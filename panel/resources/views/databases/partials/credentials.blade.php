{{-- Shown once after a database or user is created or a password is changed. --}}
@if ($c = session('db_credentials'))
    <x-ui.card class="db-secret" :title="__('Save these details now')">
        <p class="muted small" style="margin:0 0 14px">{{ $c['chosen'] ? __('The password you chose is set. It is not stored in the panel.') : __('The password is shown only this once and is not stored in the panel. Put it in your password manager or in the configuration of your website.') }}</p>
        <dl class="kv" x-data="{ copied: null, copy(k, v) { navigator.clipboard?.writeText(v); this.copied = k; setTimeout(() => this.copied = null, 1500) } }">
            @if ($c['database'])<dt>{{ __('Database') }}</dt><dd>{{ $c['database'] }}</dd>@endif
            <dt>{{ __('User name') }}</dt>
            <dd><span>{{ $c['user'] }}</span><button type="button" class="db-copy" @click="copy('u', @js($c['user']))" x-text="copied === 'u' ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</button></dd>
            @unless ($c['chosen'])
                <dt>{{ __('Password') }}</dt>
                <dd><span>{{ $c['password'] }}</span><button type="button" class="db-copy" @click="copy('p', @js($c['password']))" x-text="copied === 'p' ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</button></dd>
            @endunless
            <dt>{{ __('Server') }}</dt><dd>localhost <span class="muted">(3306)</span></dd>
        </dl>
    </x-ui.card>
@endif
