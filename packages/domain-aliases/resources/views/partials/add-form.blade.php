{{-- $area, $websites (collection; one website = no select), $manager --}}
@php $single = $websites->count() === 1 ? $websites->first() : null; @endphp
<form method="POST" action="{{ route($area.'.domain-aliases.store', $single ?? ($websites->first() ?? 0)) }}" class="form alias-add"
      x-data="{ base: @js(url($area === 'admin' ? 'admin/domain-aliases/websites' : 'domain-aliases/websites')) }">
    @csrf
    @unless ($single)
        <div class="field">
            <label for="alias-website">{{ __('Website') }}</label>
            <select id="alias-website" class="select" @change="$el.form.action = base + '/' + $el.value">
                @foreach ($websites as $w)<option value="{{ $w->id }}">{{ $w->domain }}</option>@endforeach
            </select>
        </div>
    @endunless
    <x-ui.field name="domain" :label="__('Extra domain')" placeholder="example.be" autocomplete="off" required
        :help="__('Point the domain\'s DNS to this server. With :provider DNS (Cloudflare) the records are set automatically.', ['provider' => \App\Support\Brand::provider()])" />
    <div class="field">
        <span class="label">{{ __('Behaviour') }}</span>
        <div class="seg" role="radiogroup" aria-label="{{ __('Behaviour') }}">
            <label><input type="radio" name="mode" value="serve" @checked(old('mode', 'serve') === 'serve')><span>{{ __('Show the same website') }}</span></label>
            <label><input type="radio" name="mode" value="redirect" @checked(old('mode') === 'redirect')><span>{{ __('Redirect to the main domain') }}</span></label>
        </div>
        <span class="help">{{ __('A redirect (301) is best for search engines: visitors and Google end up on one address.') }}</span>
    </div>
    <input type="hidden" name="www" value="0">
    <label class="check"><input type="checkbox" name="www" value="1" @checked(old('www', '1') === '1')><span>{{ __('Include www.') }}<small>{{ __('www.example.be works as well') }}</small></span></label>
    <input type="hidden" name="mail" value="0">
    <label class="check"><input type="checkbox" name="mail" value="1" @checked(old('mail', '1') === '1')><span>{{ __('Forward mail') }}<small>{{ __('info@example.be arrives in the mailbox info@ of the main domain') }}</small></span></label>
    <div><x-ui.button variant="primary" icon="plus">{{ __('Add alias') }}</x-ui.button></div>
</form>
