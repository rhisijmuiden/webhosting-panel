{{-- $area, $websites (collection; one website = no select) --}}
@php $single = $websites->count() === 1 ? $websites->first() : null; $first = $single ?? $websites->first(); @endphp
<form method="POST" action="{{ route($area.'.ftp-accounts.store', $first) }}" class="form ftp-form"
      x-data="{ base: @js(url($area === 'admin' ? 'admin/ftp-accounts/websites' : 'ftp-accounts/websites')), domains: @js($websites->pluck('domain', 'id')), site: @js($first->id) }">
    @csrf
    @unless ($single)
        <div class="field">
            <label for="ftp-website">{{ __('Website') }}</label>
            <select id="ftp-website" class="select" x-model="site" @change="$el.form.action = base + '/' + site">
                @foreach ($websites as $w)<option value="{{ $w->id }}">{{ $w->domain }}</option>@endforeach
            </select>
        </div>
    @endunless
    <div class="field">
        <label for="ftp-name">{{ __('Login') }} <span aria-hidden="true">*</span></label>
        <div class="ftp-login">
            <input id="ftp-name" class="input mono" name="name" value="{{ old('name') }}" maxlength="32" required autocomplete="off" placeholder="jan" pattern="[a-z0-9][a-z0-9._\-]{0,31}">
            <span class="mono muted">@<span x-text="domains[site]">{{ $first->domain }}</span></span>
        </div>
        <span class="help">{{ __('Lowercase letters, digits, dots, dashes or underscores. The full login is name@domain.') }}</span>
        @error('name')<span class="error">{{ $message }}</span>@enderror
    </div>
    @include('ftp-accounts.partials.password-field', ['id' => 'ftp-new-pw'])
    @include('ftp-accounts.partials.folder-field', ['website' => $first, 'value' => \App\Services\FtpAccounts::defaultFolder($first), 'id' => 'ftp-new-folder'])
    @include('ftp-accounts.partials.access-field', ['value' => false, 'id' => 'ftp-new-access'])
    <div class="field">
        <label for="ftp-new-desc">{{ __('Description') }}</label>
        <input id="ftp-new-desc" class="input" name="description" maxlength="120" value="{{ old('description') }}" placeholder="{{ __('For example: web designer, photo uploads') }}">
    </div>
    <div><x-ui.button variant="primary" icon="plus">{{ __('Add FTP account') }}</x-ui.button></div>
</form>
@include('ftp-accounts.partials.styles')
