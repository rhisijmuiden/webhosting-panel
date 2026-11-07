@php
    use Illuminate\Support\Number;
    $live = $live ?? null;
    $info = fn ($name) => $live['databases'][$name] ?? null;
    $totalSize = $databases->sum(fn ($d) => $info($d->name)['size_bytes'] ?? 0);
    $full = $left !== null && $left <= 0;
    $openOnLoad = $errors->getBag('database')->any() ? 'newDb' : ($errors->getBag('user')->any() ? 'newUser' : ($errors->getBag('password')->any() ? 'pw' : null));
    $mainUsers = $databases->flatMap(fn ($d) => $d->users ?: [$d->name])->values();
@endphp
<x-dynamic-component :component="'layouts.'.$area" :title="__('Databases').' · '.$website->domain">
    @include('databases.partials.styles')
    <div class="db-page" x-data="{
            del: { url: '', name: '' }, confirm: '',
            pw: { user: @js(old('user', '')) },
            edit: { url: '', name: '', databases: [], readonly: false },
            open(ref) { this.$refs[ref].showModal() },
        }" x-init="{{ $openOnLoad ? "\$nextTick(() => open('{$openOnLoad}'))" : '' }}">

    <div class="page-head">
        <div>
            <p class="eyebrow"><a href="{{ route($area.'.databases.index') }}">{{ __('Databases') }}</a></p>
            <h1>{{ $website->domain }}</h1>
            <p class="sub">@if ($area === 'admin' && $website->customer){{ $website->customer->name }} · @endif{{ __('Databases and database users of this website.') }}</p>
        </div>
        <div class="actions">
            <x-ui.button type="button" icon="user" @click="open('newUser')" :disabled="$databases->isEmpty()">{{ __('New user') }}</x-ui.button>
            <x-ui.button type="button" variant="primary" icon="plus" @click="open('newDb')" :disabled="$full">{{ __('New database') }}</x-ui.button>
        </div>
    </div>

    @include('databases.partials.credentials')
    @error('confirm')<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror
    @if ($live === null)
        <div class="alert alert-warn" role="status"><x-icon name="info" /><div>{{ __('The server did not answer: sizes and users may be out of date.') }}</div></div>
    @endif
    @if ($full)
        <div class="alert alert-info" role="status"><x-icon name="info" /><div>
            {{ $limit === 0 ? __('The package of this website does not include databases.') : __('All :max databases of the package are in use. Delete one, or ask for a larger package.', ['max' => $limit]) }}
            @if ($area === 'client' && Route::has('client.shop.index'))<a href="{{ route('client.shop.index') }}">{{ __('Upgrade') }}</a>@endif
        </div></div>
    @endif

    <x-ui.card>
        <div class="kpi-row">
            <div><span>{{ __('Databases') }}</span><strong>{{ $used }}<small class="muted"> / {{ $limit ?? '∞' }}</small></strong>
                <small class="muted">{{ $website->parent_id ? __('Counted together with the main website') : __('Package: :name', ['name' => $website->package?->name ?? __('none')]) }}</small></div>
            <div><span>{{ __('Total size') }}</span><strong>{{ $totalSize ? Number::fileSize($totalSize, 1) : '0 B' }}</strong><small class="muted">{{ __('Counts towards the disk space') }}</small></div>
            <div><span>{{ __('Database users') }}</span><strong>{{ $mainUsers->count() + $users->count() }}</strong><small class="muted">{{ trans_choice(':count extra user|:count extra users', $users->count()) }}</small></div>
            <div><span>{{ __('Server') }}</span><strong style="font-size:20px">localhost</strong><small class="muted">MariaDB · {{ __('port') }} 3306</small></div>
        </div>
    </x-ui.card>

    <x-ui.card :title="__('Databases')" flush>
        @if ($databases->isEmpty())
            <x-ui.empty icon="database">{{ __('No databases yet. WordPress and other apps make their own; for your own code, create one here.') }}</x-ui.empty>
        @else
            <x-ui.table>
                <x-slot:head>
                    <th>{{ __('Database') }}</th><th class="num db-hide-sm">{{ __('Size') }}</th><th class="num db-hide-sm">{{ __('Tables') }}</th>
                    <th class="db-hide-sm">{{ __('Users') }}</th><th></th>
                </x-slot:head>
                @foreach ($databases as $db)
                    @php $i = $info($db->name); $main = $db->users[0] ?? $db->name; @endphp
                    <tr>
                        <td><div class="db-name"><span class="db-icon"><x-icon name="database" /></span>
                            <div><span class="mono">{{ $db->name }}</span>
                            <div class="muted small">{{ match ($db->origin) { 'app' => __('Made by an app'), 'migration' => __('Migrated'), 'clone' => __('Copy of a website'), default => __('Created :date', ['date' => $db->created_at?->isoFormat('LL')]) } }}</div></div></div></td>
                        <td class="num db-hide-sm">{{ $i ? Number::fileSize($i['size_bytes'] ?? 0, 1) : '–' }}</td>
                        <td class="num db-hide-sm">{{ $i['tables'] ?? '–' }}</td>
                        <td class="db-hide-sm"><div class="db-list-tags">
                            @foreach ($i['users'] ?? [['user' => $main, 'readonly' => false, 'main' => true]] as $u)
                                <span @class(['db-tag', 'ro' => $u['readonly']]) title="{{ $u['main'] ? __('Main user') : ($u['readonly'] ? __('Read only') : __('Read and write')) }}">{{ $u['user'] }}@if ($u['readonly'])<b>{{ __('read') }}</b>@endif</span>
                            @endforeach
                        </div></td>
                        <td><div class="db-actions">
                            @if ($phpMyAdmin)
                                <form method="POST" action="{{ route($area.'.databases.phpmyadmin', $db) }}" target="_blank">@csrf
                                    <x-ui.button size="sm" class="db-pma" :title="__('Open in phpMyAdmin, logged in automatically')"><span class="pma-mark" aria-hidden="true">pMA</span><span class="btn-text">phpMyAdmin</span></x-ui.button>
                                </form>
                            @endif
                            <x-ui.button type="button" size="sm" icon="key" :title="__('New password for :user', ['user' => $main])" :aria-label="__('New password for :user', ['user' => $main])"
                                @click="pw.user = {{ \Illuminate\Support\Js::from($main) }}; open('pw')"></x-ui.button>
                            <x-ui.button type="button" size="sm" variant="danger" icon="trash" :title="__('Delete')" :aria-label="__('Delete :name', ['name' => $db->name])"
                                @click="del = { url: {{ \Illuminate\Support\Js::from(route($area.'.databases.destroy', $db)) }}, name: {{ \Illuminate\Support\Js::from($db->name) }} }; confirm = ''; open('del')"></x-ui.button>
                        </div></td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('Extra users')" flush>
        <x-slot:actions>@if ($databases->isNotEmpty())<x-ui.button type="button" size="sm" icon="plus" @click="open('newUser')">{{ __('New user') }}</x-ui.button>@endif</x-slot:actions>
        @if ($users->isEmpty())
            <x-ui.empty icon="user">{{ __('Every database has its own main user. Add an extra user for a developer, a reporting tool or a read-only connection.') }}</x-ui.empty>
        @else
            <x-ui.table>
                <x-slot:head><th>{{ __('User') }}</th><th>{{ __('Rights') }}</th><th class="db-hide-sm">{{ __('Databases') }}</th><th></th></x-slot:head>
                @foreach ($users as $u)
                    <tr>
                        <td><div class="db-name"><span class="db-icon"><x-icon name="user" /></span><span class="mono">{{ $u->name }}</span></div></td>
                        <td><x-ui.pill :status="$u->readonly ? 'info' : 'ok'">{{ $u->readonly ? __('Read only') : __('Read and write') }}</x-ui.pill></td>
                        <td class="db-hide-sm"><div class="db-list-tags">@foreach ($u->databases ?? [] as $d)<span class="db-tag">{{ $d }}</span>@endforeach</div></td>
                        <td><div class="db-actions">
                            <x-ui.button type="button" size="sm" icon="edit" :title="__('Change access')" :aria-label="__('Change access of :user', ['user' => $u->name])"
                                @click="edit = { url: {{ \Illuminate\Support\Js::from(route($area.'.databases.users.update', $u)) }}, name: {{ \Illuminate\Support\Js::from($u->name) }}, databases: {{ \Illuminate\Support\Js::from($u->databases ?? []) }}, readonly: {{ $u->readonly ? 'true' : 'false' }} }; open('edit')"></x-ui.button>
                            <x-ui.button type="button" size="sm" icon="key" :title="__('New password for :user', ['user' => $u->name])" :aria-label="__('New password for :user', ['user' => $u->name])"
                                @click="pw.user = {{ \Illuminate\Support\Js::from($u->name) }}; open('pw')"></x-ui.button>
                            <form method="POST" action="{{ route($area.'.databases.users.destroy', $u) }}" data-confirm="{{ __('Delete database user :user? Websites that log in with it stop working.', ['user' => $u->name]) }}">
                                @csrf @method('DELETE')
                                <x-ui.button size="sm" variant="danger" icon="trash" :title="__('Delete')" :aria-label="__('Delete :name', ['name' => $u->name])"></x-ui.button>
                            </form>
                        </div></td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('Connect from your website')" class="db-connect">
        <dl class="kv">
            <dt>{{ __('Server (host)') }}</dt><dd><code>localhost</code> <span class="muted small">{{ __('or 127.0.0.1, port 3306') }}</span></dd>
            <dt>{{ __('Database and user') }}</dt><dd>{{ __('The names in the list above, for example') }} <code>{{ $databases->first()?->name ?? $prefix.'shop' }}</code></dd>
            <dt>{{ __('Password') }}</dt><dd>{{ __('Shown once when you create the database. Lost it? Use the key button to set a new one.') }}</dd>
            <dt>WordPress</dt><dd class="muted small"><code>DB_HOST</code> = <code>localhost</code> {{ __('in wp-config.php') }}</dd>
        </dl>
        <p class="muted small" style="margin:14px 0 0">{{ __('Databases only accept connections from this server, so your website can reach them and the internet cannot.') }}</p>
    </x-ui.card>

    {{-- New database --}}
    <dialog class="modal" x-ref="newDb" aria-labelledby="db-new-title">
        <form method="POST" action="{{ route($area.'.databases.store', $website) }}" class="form">
            @csrf
            <div class="modal-head"><h2 id="db-new-title">{{ __('New database') }}</h2>
                <button type="button" class="btn btn-ghost btn-sm" @click="$refs.newDb.close()" aria-label="{{ __('Close') }}"><x-icon name="close" /></button></div>
            <div class="modal-body">
                @php $err = $errors->getBag('database')->first('name'); @endphp
                <div class="field">
                    <label for="db-new-name">{{ __('Name') }}</label>
                    <div class="db-prefix"><span>{{ $prefix }}</span><input id="db-new-name" class="input" name="name" value="{{ old('name') }}" required maxlength="32" pattern="[a-z0-9][a-z0-9_]{0,31}" placeholder="shop" autocomplete="off" @if ($err) aria-invalid="true" @endif></div>
                    <span class="help">{{ __('The database user gets the same name and full rights on this database.') }}</span>
                    @if ($err)<span class="error">{{ $err }}</span>@endif
                </div>
                @include('databases.partials.password-choice', ['bag' => 'database', 'id' => 'db-new'])
            </div>
            <div class="modal-foot"><x-ui.button type="button" @click="$refs.newDb.close()">{{ __('Cancel') }}</x-ui.button><x-ui.button variant="primary" icon="plus">{{ __('Create database') }}</x-ui.button></div>
        </form>
    </dialog>

    {{-- New extra user --}}
    <dialog class="modal" x-ref="newUser" aria-labelledby="db-user-title">
        <form method="POST" action="{{ route($area.'.databases.users.store', $website) }}" class="form">
            @csrf
            <div class="modal-head"><h2 id="db-user-title">{{ __('New database user') }}</h2>
                <button type="button" class="btn btn-ghost btn-sm" @click="$refs.newUser.close()" aria-label="{{ __('Close') }}"><x-icon name="close" /></button></div>
            <div class="modal-body">
                @php $ub = $errors->getBag('user'); $oldDbs = old('databases', $databases->count() === 1 ? [$databases->first()->name] : []); @endphp
                <div class="field">
                    <label for="db-user-name">{{ __('User name') }}</label>
                    <div class="db-prefix"><span>{{ $prefix }}</span><input id="db-user-name" class="input" name="name" value="{{ old('name') }}" required maxlength="32" pattern="[a-z0-9][a-z0-9_]{0,31}" placeholder="developer" autocomplete="off" @if ($ub->has('name')) aria-invalid="true" @endif></div>
                    @if ($ub->has('name'))<span class="error">{{ $ub->first('name') }}</span>@endif
                </div>
                <div class="field">
                    <span class="label">{{ __('Databases') }}</span>
                    <div class="db-checks">
                        @foreach ($databases as $db)
                            <label class="check"><input type="checkbox" name="databases[]" value="{{ $db->name }}" @checked(in_array($db->name, (array) $oldDbs, true))><span>{{ $db->name }}</span></label>
                        @endforeach
                    </div>
                    @if ($ub->has('databases'))<span class="error">{{ $ub->first('databases') }}</span>@endif
                </div>
                <div class="field">
                    <span class="label">{{ __('Rights') }}</span>
                    <div class="seg" role="radiogroup" aria-label="{{ __('Rights') }}">
                        <label><input type="radio" name="readonly" value="0" @checked(! old('readonly'))><span>{{ __('Read and write') }}</span></label>
                        <label><input type="radio" name="readonly" value="1" @checked(old('readonly'))><span>{{ __('Read only') }}</span></label>
                    </div>
                    <span class="help">{{ __('Read only: can look at the data and export it, but cannot change anything.') }}</span>
                </div>
                @include('databases.partials.password-choice', ['bag' => 'user', 'id' => 'db-user'])
            </div>
            <div class="modal-foot"><x-ui.button type="button" @click="$refs.newUser.close()">{{ __('Cancel') }}</x-ui.button><x-ui.button variant="primary" icon="plus">{{ __('Create user') }}</x-ui.button></div>
        </form>
    </dialog>

    {{-- Change access of an extra user --}}
    <dialog class="modal" x-ref="edit" aria-labelledby="db-edit-title">
        <form method="POST" :action="edit.url" class="form">
            @csrf @method('PUT')
            <div class="modal-head"><h2 id="db-edit-title">{{ __('Change access') }} <span class="mono" x-text="edit.name"></span></h2>
                <button type="button" class="btn btn-ghost btn-sm" @click="$refs.edit.close()" aria-label="{{ __('Close') }}"><x-icon name="close" /></button></div>
            <div class="modal-body">
                <div class="field">
                    <span class="label">{{ __('Databases') }}</span>
                    <div class="db-checks">
                        @foreach ($databases as $db)
                            <label class="check"><input type="checkbox" name="databases[]" value="{{ $db->name }}" x-model="edit.databases"><span>{{ $db->name }}</span></label>
                        @endforeach
                    </div>
                </div>
                <div class="field">
                    <span class="label">{{ __('Rights') }}</span>
                    <div class="seg" role="radiogroup" aria-label="{{ __('Rights') }}">
                        <label><input type="radio" name="readonly" value="0" :checked="! edit.readonly" @change="edit.readonly = false"><span>{{ __('Read and write') }}</span></label>
                        <label><input type="radio" name="readonly" value="1" :checked="edit.readonly" @change="edit.readonly = true"><span>{{ __('Read only') }}</span></label>
                    </div>
                </div>
            </div>
            <div class="modal-foot"><x-ui.button type="button" @click="$refs.edit.close()">{{ __('Cancel') }}</x-ui.button><x-ui.button variant="primary" icon="check" ::disabled="! edit.databases.length">{{ __('Save') }}</x-ui.button></div>
        </form>
    </dialog>

    {{-- New password --}}
    <dialog class="modal" x-ref="pw" aria-labelledby="db-pw-title">
        <form method="POST" action="{{ route($area.'.databases.password', $website) }}" class="form">
            @csrf
            <input type="hidden" name="user" :value="pw.user">
            <div class="modal-head"><h2 id="db-pw-title">{{ __('New password') }}</h2>
                <button type="button" class="btn btn-ghost btn-sm" @click="$refs.pw.close()" aria-label="{{ __('Close') }}"><x-icon name="close" /></button></div>
            <div class="modal-body">
                <p style="margin:0">{{ __('For database user') }} <strong class="mono" x-text="pw.user"></strong></p>
                @include('databases.partials.password-choice', ['bag' => 'password', 'id' => 'db-pw'])
                <div class="alert alert-warn" role="note"><x-icon name="info" /><div>{{ __('The old password stops working right away. Put the new one in the configuration of your website too (for WordPress: wp-config.php).') }}</div></div>
            </div>
            <div class="modal-foot"><x-ui.button type="button" @click="$refs.pw.close()">{{ __('Cancel') }}</x-ui.button><x-ui.button variant="primary" icon="key">{{ __('Set new password') }}</x-ui.button></div>
        </form>
    </dialog>

    {{-- Delete a database --}}
    <dialog class="modal" x-ref="del" aria-labelledby="db-del-title">
        <form method="POST" :action="del.url" class="form">
            @csrf @method('DELETE')
            <div class="modal-head"><h2 id="db-del-title">{{ __('Delete database') }}</h2>
                <button type="button" class="btn btn-ghost btn-sm" @click="$refs.del.close()" aria-label="{{ __('Close') }}"><x-icon name="close" /></button></div>
            <div class="modal-body">
                <div class="alert alert-bad" role="note"><x-icon name="info" /><div>{{ __('The database, all its tables and its main user are deleted. This cannot be undone, except from a backup.') }}</div></div>
                <div class="field">
                    <label for="db-del-confirm">{{ __('Type the name to confirm') }}: <strong class="mono" x-text="del.name"></strong></label>
                    <input id="db-del-confirm" class="input" name="confirm" x-model="confirm" autocomplete="off" spellcheck="false">
                </div>
            </div>
            <div class="modal-foot"><x-ui.button type="button" @click="$refs.del.close()">{{ __('Cancel') }}</x-ui.button><x-ui.button variant="danger" icon="trash" ::disabled="confirm !== del.name">{{ __('Delete database') }}</x-ui.button></div>
        </form>
    </dialog>
    </div>
</x-dynamic-component>
