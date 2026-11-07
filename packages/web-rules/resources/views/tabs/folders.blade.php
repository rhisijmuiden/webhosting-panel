<div class="wr-intro">
    <h2>{{ __('Password protection') }}</h2>
    <p class="muted small">{{ __('Visitors must log in before they can open the folder and everything in it, PHP pages included. Handy for a test site (use / for the whole website), an admin area or private downloads.') }}</p>
</div>
@error('folders')<div class="alert alert-warn" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror
<form method="POST" action="{{ $r('folders.store') }}" class="form wr-form">
    @csrf
    <div class="form-grid">
        <x-ui.field name="path" :label="__('Folder')" :value="old('path')" placeholder="/admin" required :help="__('/ protects the whole website.')" />
        <x-ui.field name="realm" :label="__('Title in the login window')" :value="old('realm')" :placeholder="__('Restricted area')" />
        <x-ui.field name="username" :label="__('User name')" :value="old('username')" autocomplete="off" required />
        <x-ui.field name="password" type="password" :label="__('Password')" autocomplete="new-password" required :help="__('At least 8 characters.')" />
    </div>
    <div><button type="submit" class="btn btn-dark"><x-icon name="lock" />{{ __('Protect folder') }}</button></div>
</form>

@forelse ($rules->folderList() as $folder)
    <article class="wr-folder">
        <header>
            <div><x-icon name="lock" /> <span class="mono strong">{{ $folder['path'] }}</span> <span class="muted small">· {{ $folder['realm'] }}</span></div>
            <form method="POST" action="{{ $r('folders.destroy', ['id' => $folder['id']]) }}" onsubmit="return confirm(@js(__('Remove the password protection of :path?', ['path' => $folder['path']])))">@csrf @method('DELETE')
                <button class="btn btn-sm" type="submit">{{ __('Remove protection') }}</button>
            </form>
        </header>
        <ul class="wr-users">
            @foreach ($folder['users'] ?? [] as $u)
                <li>
                    <span><x-icon name="user" /> {{ $u['name'] }}</span>
                    @if (count($folder['users']) > 1)
                        <form method="POST" action="{{ $r('folders.users.destroy', ['id' => $folder['id']]) }}">@csrf @method('DELETE')
                            <input type="hidden" name="username" value="{{ $u['name'] }}">
                            <button class="btn btn-sm btn-ghost" type="submit" aria-label="{{ __('Remove') }} {{ $u['name'] }}"><x-icon name="trash" /></button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
        <form method="POST" action="{{ $r('folders.users.store', ['id' => $folder['id']]) }}" class="wr-inline">
            @csrf
            <input class="input" name="username" placeholder="{{ __('User name') }}" aria-label="{{ __('User name') }}" required autocomplete="off">
            <input class="input" name="password" type="password" placeholder="{{ __('Password') }}" aria-label="{{ __('Password') }}" required minlength="8" autocomplete="new-password">
            <button class="btn btn-sm" type="submit">{{ __('Add user or change password') }}</button>
        </form>
    </article>
@empty
    <x-ui.empty icon="lock">{{ __('No protected folders yet.') }}</x-ui.empty>
@endforelse
