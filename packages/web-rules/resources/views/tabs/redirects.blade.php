<div class="wr-intro">
    <h2>{{ __('Redirects') }}</h2>
    <p class="muted small">{{ __('Send visitors and search engines from an old address to a new one. Use 301 for a permanent move (search engines move the ranking along), 302 or 307 for a temporary one.') }}</p>
</div>
<form method="POST" action="{{ $r('redirects.store') }}" class="form wr-form">
    @csrf
    <div class="form-grid">
        <x-ui.field name="from" :label="__('From (path on this website)')" :value="old('from')" placeholder="/old-page" required />
        <x-ui.field name="to" :label="__('To (address or path)')" :value="old('to')" placeholder="https://{{ $website->domain }}/new-page" required />
        <x-ui.field name="code" type="select" :label="__('Type')">
            @foreach ([301 => __('301 · Moved permanently'), 302 => __('302 · Found (temporary)'), 307 => __('307 · Temporary, keeps the method'), 308 => __('308 · Permanent, keeps the method')] as $code => $label)
                <option value="{{ $code }}" @selected((int) old('code', 301) === $code)>{{ $label }}</option>
            @endforeach
        </x-ui.field>
    </div>
    <input type="hidden" name="prefix" value="0">
    <label class="check"><input type="checkbox" name="prefix" value="1" @checked(old('prefix'))><span>{{ __('Also redirect everything below this path') }}<small>{{ __('/blog/2024/post goes to the same place below the new address: /news/2024/post.') }}</small></span></label>
    <input type="hidden" name="keep_query" value="0">
    <label class="check"><input type="checkbox" name="keep_query" value="1" @checked(old('keep_query', true))><span>{{ __('Keep the query string') }}<small>{{ __('?utm_source=... and other parameters are passed on to the new address.') }}</small></span></label>
    <div><button type="submit" class="btn btn-dark"><x-icon name="plus" />{{ __('Add redirect') }}</button></div>
</form>

@php $redirects = $rules->redirectList(); @endphp
@if ($redirects === [])
    <x-ui.empty icon="external">{{ __('No redirects yet.') }}</x-ui.empty>
@else
    <x-ui.table>
        <x-slot:head><th>{{ __('From') }}</th><th>{{ __('To') }}</th><th>{{ __('Type') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
        @foreach ($redirects as $red)
            <tr>
                <td class="mono">{{ $red['from'] }}@if ($red['prefix'])<span class="muted">{{ $red['from'] === '/' ? '*' : '/*' }}</span>@endif</td>
                <td class="mono wr-break">{{ $red['to'] }}@if ($red['prefix'] && ! str_contains($red['to'], '?'))<span class="muted">{{ str_ends_with($red['to'], '/') ? '*' : '/*' }}</span>@endif</td>
                <td><x-ui.pill :status="in_array($red['code'], [301, 308]) ? 'ok' : 'info'" :dot="false">{{ $red['code'] }}</x-ui.pill>@if (! $red['keep_query'])<span class="muted small"> {{ __('without query') }}</span>@endif</td>
                <td class="num">
                    <form method="POST" action="{{ $r('redirects.destroy', ['id' => $red['id']]) }}" onsubmit="return confirm(@js(__('Remove this redirect?')))">@csrf @method('DELETE')
                        <button class="btn btn-sm" type="submit" aria-label="{{ __('Remove') }} {{ $red['from'] }}"><x-icon name="trash" />{{ __('Remove') }}</button>
                    </form>
                </td>
            </tr>
        @endforeach
    </x-ui.table>
@endif
