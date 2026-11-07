@php
    $actionLabels = ['create' => __('Add'), 'update' => __('Change'), 'delete' => __('Remove'), 'same' => __('Already right'), 'untouched' => __('Not touched')];
    $actionPills = ['create' => 'ok', 'update' => 'warn', 'delete' => 'bad', 'same' => 'neutral', 'untouched' => 'neutral'];
    $groupLabels = ['web' => __('Website'), 'mail' => __('Mail'), 'own' => __('Own records from the old server'), 'other' => __('Other records')];
    $hint = function (?string $h) {
        if ($h === null) return null;
        if (str_starts_with($h, 'external:')) return __('Points to :service. Kept unless you tick it.', ['service' => substr($h, 9)]);
        return match ($h) {
            'adopt' => __('Same value; the panel takes the record over and keeps it up to date.'),
            'replace' => __('This record was not made by the panel.'),
            'cloudflare_differs' => __('Cloudflare has another value than the old server. Kept unless you tick it.'),
            default => null,
        };
    };
    $zone = $item->domain;
    $editable ??= true;
@endphp
<div class="table-wrap">
<table class="table wz-dns">
    <thead><tr>@if ($editable)<th class="wz-tick"><span class="sr-only">{{ __('Apply') }}</span></th>@endif<th>{{ __('Action') }}</th><th>{{ __('Type') }}</th><th>{{ __('Name') }}</th><th>{{ __('Now in Cloudflare') }}</th><th>{{ __('After the switch') }}</th></tr></thead>
    @foreach (collect($rows)->groupBy('group') as $group => $list)
        <tbody>
        <tr class="mig-group"><td colspan="{{ $editable ? 6 : 5 }}">{{ $groupLabels[$group] ?? $group }}</td></tr>
        @foreach ($list as $r)
            @php $k = $r->key(); $on = $saved !== null && ! $stale ? in_array($k, $saved, true) : $r->selected; @endphp
            <tr @class(['is-off' => $r->isChange() && ! $on && ! $editable])>
                @if ($editable)<td class="wz-tick">@if ($r->isChange())<input class="mig-sel" type="checkbox" name="keys[]" value="{{ $k }}" @checked($on) aria-label="{{ $actionLabels[$r->action] }} {{ $r->type() }} {{ $r->name($zone) }}">@endif</td>@endif
                <td><x-ui.pill :status="$actionPills[$r->action]" :dot="false">{{ $actionLabels[$r->action] }}</x-ui.pill></td>
                <td class="mono small">{{ $r->type() }}</td>
                <td class="mono small">{{ $r->name($zone) }}</td>
                <td class="mono small wz-val">@if ($r->old){{ $r->old->displayValue() }}@if ($r->old->proxied) <span class="wz-cf" title="{{ __('Through the Cloudflare proxy') }}">CF</span>@endif @else<span class="muted">–</span>@endif</td>
                <td class="mono small wz-val">
                    @if ($r->action === 'delete')<span class="muted">{{ __('removed') }}</span>
                    @elseif ($r->action === 'untouched')<span class="muted">{{ __('unchanged') }}</span>
                    @elseif ($r->new){{ $r->new->displayValue() }}@if ($r->new->proxied) <span class="wz-cf" title="{{ __('Through the Cloudflare proxy') }}">CF</span>@endif @endif
                    @if ($t = $hint($r->hint))<div class="wz-hint">{{ $t }}</div>@endif
                </td>
            </tr>
        @endforeach
        </tbody>
    @endforeach
</table>
</div>
