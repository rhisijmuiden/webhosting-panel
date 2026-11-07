{{-- $value (read only?), $id, $useOld (default true) --}}
@php $ro = (bool) (($useOld ?? true) ? old('read_only', $value) : $value); @endphp
<div class="field">
    <span class="label">{{ __('Access') }}</span>
    <div class="seg" role="radiogroup" aria-label="{{ __('Access') }}">
        <label><input type="radio" name="read_only" value="0" @checked(! $ro)><span>{{ __('Read & write') }}</span></label>
        <label><input type="radio" name="read_only" value="1" @checked($ro)><span>{{ __('Read only') }}</span></label>
    </div>
    <span class="help">{{ __('Read only: download and browse, no uploads, changes or deletes.') }}</span>
</div>
