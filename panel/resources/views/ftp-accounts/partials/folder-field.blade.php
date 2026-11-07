{{-- $website, $value, $id, $useOld (default true) --}}
@php
    $docroot = \App\Services\FtpAccounts::defaultFolder($website);
    $suggest = array_values(array_unique(['', $docroot, $docroot.'/wp-content/uploads', $docroot.'/wp-content/themes', $docroot.'/uploads']));
@endphp
<div class="field">
    <label for="{{ $id }}">{{ __('Folder') }}</label>
    <div class="ftp-folder">
        <span class="mono muted">{{ $website->domain }}/</span>
        <input id="{{ $id }}" class="input mono" name="folder" list="{{ $id }}-list" value="{{ ($useOld ?? true) ? old('folder', $value) : $value }}" maxlength="500" autocomplete="off" placeholder="{{ __('(whole website)') }}">
    </div>
    <datalist id="{{ $id }}-list">@foreach ($suggest as $s)<option value="{{ $s }}">{{ $s === '' ? __('Whole website') : '/'.$s }}</option>@endforeach</datalist>
    <span class="help">{{ __('The account only sees this folder and what is in it. Empty means the whole website (including logs). A folder that does not exist yet is created.') }}</span>
    @if ($useOld ?? true)@error('folder')<span class="error">{{ $message }}</span>@enderror @endif
</div>
