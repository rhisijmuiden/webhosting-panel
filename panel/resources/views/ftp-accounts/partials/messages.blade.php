{{-- Errors, warnings and a password that was just generated (shown once). --}}
@error('ftp')<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror
@foreach ((array) session('warnings', []) as $w)
    <div class="alert alert-warn" role="status"><x-icon name="info" />{{ $w }}</div>
@endforeach
@if ($ftpNew = session('ftp_password'))
    <div class="alert alert-ok ftp-secret" role="status" x-data="{ copied: false }">
        <x-icon name="key" />
        <div>
            <strong>{{ __('Password for :login', ['login' => $ftpNew['login']]) }}</strong>
            <div class="ftp-secret-row">
                <code class="mono" x-ref="pw">{{ $ftpNew['password'] }}</code>
                <button type="button" class="btn btn-sm" @click="navigator.clipboard.writeText($refs.pw.textContent); copied = true">
                    <x-icon name="check" x-show="copied" x-cloak /><span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span>
                </button>
            </div>
            <span class="small muted">{{ __('Save it now: it is shown only once. You can always set a new one.') }}</span>
        </div>
    </div>
@endif
