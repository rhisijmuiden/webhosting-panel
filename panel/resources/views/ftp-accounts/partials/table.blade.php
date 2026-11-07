{{-- $accounts, $area, $showWebsite, $canManage, $manager. Without $showWebsite (the narrow card on a domain page) the table is compact. --}}
@php
    $ftpProblems = $manager->problems();
    $compact = ! $showWebsite;
    $cols = $compact ? 3 : 6;
    // After a failed edit, keep that form open.
    $openRow = old('_ftp') ? ($errors->has('password') ? 'pw-'.old('_ftp') : 'edit-'.old('_ftp')) : null;
@endphp
@include('ftp-accounts.partials.styles')
<x-ui.table class="ftp-table" x-data="{ open: {{ \Illuminate\Support\Js::from($openRow) }} }">
    <x-slot:head>
        <th>{{ __('Login') }}</th>
        @unless ($compact)
            <th>{{ __('Website') }}</th><th>{{ __('Folder') }}</th>
        @endunless
        <th>{{ __('Access') }}</th>
        @unless ($compact)<th>{{ __('Status') }}</th>@endunless
        <th class="num"><span class="sr-only">{{ __('Actions') }}</span></th>
    </x-slot:head>
    @foreach ($accounts as $a)
        @php
            $problem = $ftpProblems[$a->login] ?? null;
            $state = match (true) {
                ! $a->enabled => ['off', __('Off')],
                $a->website?->status !== 'active' => ['suspended', __('Website not active')],
                ($problem['reason'] ?? '') === 'folder' => ['bad', __('Folder problem')],
                default => ['active', __('Active')],
            };
        @endphp
        <tr>
            <td>
                <span class="mono strong ftp-login-name">{{ $a->name() }}<wbr>{{ '@'.($a->website?->domain ?? \Illuminate\Support\Str::after($a->login, '@')) }}</span>
                @if ($compact)<div class="mono small muted">{{ $a->folderLabel() }}</div>@endif
                @if ($a->description)<div class="muted small">{{ $a->description }}</div>@endif
                @if ($state[0] === 'bad' && ! empty($problem['message']))<div class="small muted">{{ $problem['message'] }}</div>@endif
            </td>
            @unless ($compact)
                <td class="small"><a href="{{ route($area.'.websites.show', [$a->website, 'tab' => 'hosting']) }}#ftp-accounts">{{ $a->website->domain }}</a>
                    @if ($area === 'admin')<div class="muted">{{ $a->website->customer?->displayName() }}</div>@endif</td>
                <td class="mono small">{{ $a->folderLabel() }}</td>
            @endunless
            <td>
                <div class="ftp-pills">
                    @if ($a->read_only)
                        <x-ui.pill status="info" :dot="false">{{ __('Read only') }}</x-ui.pill>
                    @else
                        <span class="small">{{ __('Read & write') }}</span>
                    @endif
                    @if ($compact && $state[0] !== 'active')<x-ui.pill :status="$state[0]">{{ $state[1] }}</x-ui.pill>@endif
                </div>
            </td>
            @unless ($compact)
                <td><x-ui.pill :status="$state[0]">{{ $state[1] }}</x-ui.pill></td>
            @endunless
            <td class="num">
                @if ($canManage)
                    <div class="actions" style="justify-content:flex-end">
                        <button type="button" class="btn btn-sm" :aria-expanded="open === 'edit-{{ $a->id }}'" @click="open = open === 'edit-{{ $a->id }}' ? null : 'edit-{{ $a->id }}'"
                            title="{{ __('Edit') }}"><x-icon name="edit" /><span @class(['sr-only' => $compact])>{{ __('Edit') }}</span></button>
                        <button type="button" class="btn btn-sm" :aria-expanded="open === 'pw-{{ $a->id }}'" @click="open = open === 'pw-{{ $a->id }}' ? null : 'pw-{{ $a->id }}'"
                            title="{{ __('Password') }}"><x-icon name="key" /><span @class(['sr-only' => $compact])>{{ __('Password') }}</span></button>
                        <form method="POST" action="{{ route($area.'.ftp-accounts.destroy', $a) }}"
                              @submit="if (! confirm({{ \Illuminate\Support\Js::from(__('Remove :login? Open connections are closed and the login stops working.', ['login' => $a->login])) }})) $event.preventDefault()">
                            @csrf @method('DELETE')<button class="btn btn-sm btn-ghost" type="submit" title="{{ __('Remove') }}"><x-icon name="trash" /><span class="sr-only">{{ __('Remove') }}</span></button>
                        </form>
                    </div>
                @endif
            </td>
        </tr>
        @if ($canManage)
            <tr class="ftp-edit-row" x-show="open === 'edit-{{ $a->id }}'" @if ($openRow !== 'edit-'.$a->id) style="display:none" @endif>
                <td colspan="{{ $cols }}">
                    <form method="POST" action="{{ route($area.'.ftp-accounts.update', $a) }}" class="form ftp-form">
                        @csrf @method('PUT')<input type="hidden" name="_ftp" value="{{ $a->id }}">
                        @include('ftp-accounts.partials.folder-field', ['website' => $a->website, 'value' => $a->folder, 'id' => 'ftp-folder-'.$a->id, 'useOld' => old('_ftp') == $a->id])
                        @include('ftp-accounts.partials.access-field', ['value' => $a->read_only, 'id' => 'ftp-access-'.$a->id, 'useOld' => false])
                        <div class="field">
                            <label for="ftp-desc-{{ $a->id }}">{{ __('Description') }}</label>
                            <input id="ftp-desc-{{ $a->id }}" class="input" name="description" maxlength="120" value="{{ $a->description }}" placeholder="{{ __('For example: web designer, photo uploads') }}">
                        </div>
                        <input type="hidden" name="enabled" value="0">
                        <label class="check"><input type="checkbox" name="enabled" value="1" @checked($a->enabled)><span>{{ __('Account can log in') }}<small>{{ __('Turn off to block the login for now, without removing it') }}</small></span></label>
                        <div><x-ui.button variant="primary" icon="check">{{ __('Save') }}</x-ui.button></div>
                    </form>
                </td>
            </tr>
            <tr class="ftp-edit-row" x-show="open === 'pw-{{ $a->id }}'" @if ($openRow !== 'pw-'.$a->id) style="display:none" @endif>
                <td colspan="{{ $cols }}">
                    <form method="POST" action="{{ route($area.'.ftp-accounts.password', $a) }}" class="form ftp-form">
                        @csrf @method('PUT')<input type="hidden" name="_ftp" value="{{ $a->id }}">
                        @include('ftp-accounts.partials.password-field', ['id' => 'ftp-pw-'.$a->id])
                        <div><x-ui.button variant="primary" icon="key">{{ __('Set new password') }}</x-ui.button></div>
                    </form>
                </td>
            </tr>
        @endif
    @endforeach
</x-ui.table>
