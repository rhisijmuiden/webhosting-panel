{{-- $exceptions, $storeUrl, $destroyUrl (fn index => url), $canChange --}}
<x-ui.card :title="__('Exceptions')" flush>
    <p class="muted small" style="padding:0 20px">{{ __('An exception switches one rule off: everywhere, only below a path, or only for one form field. Use it when the firewall stops something that is not an attack.') }}</p>
    @if (empty($exceptions))
        <x-ui.empty icon="check">{{ __('No exceptions. Every rule is checked.') }}</x-ui.empty>
    @else
        <x-ui.table>
            <x-slot:head><th>{{ __('Rule') }}</th><th>{{ __('Where') }}</th><th>{{ __('Field') }}</th><th>{{ __('Note') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
            @foreach ($exceptions as $i => $x)
                <tr>
                    <td><code>{{ $x['rule'] }}</code></td>
                    <td class="small">@if ($x['path'] ?? null)<span class="mono">{{ $x['path'] }}</span>…@else{{ __('Everywhere') }}@endif</td>
                    <td class="small">@if ($x['param'] ?? null)<span class="mono">{{ $x['param'] }}</span>@else{{ __('All fields') }}@endif</td>
                    <td class="small muted">{{ $x['comment'] ?? '' }}@if ($x['added_by'] ?? null)<span class="block">{{ $x['added_by'] }}, {{ \Illuminate\Support\Carbon::parse($x['added_at'])->isoFormat('D MMM YYYY') }}</span>@endif</td>
                    <td class="num">@if ($canChange)
                        <form method="POST" action="{{ $destroyUrl($i) }}">@csrf @method('DELETE')
                            <button class="btn btn-sm btn-ghost icon-btn" type="submit" title="{{ __('Remove') }}"><x-icon name="trash" /><span class="sr-only">{{ __('Remove') }}</span></button></form>
                    @endif</td>
                </tr>
            @endforeach
        </x-ui.table>
    @endif
    @if ($canChange)
        <form method="POST" action="{{ $storeUrl }}" class="form" style="padding:4px 20px 20px">
            @csrf
            <div class="form-grid">
                <x-ui.field name="rule" type="number" min="1" :label="__('Rule number')" placeholder="942100" required />
                <x-ui.field name="path" :label="__('Only below path (optional)')" placeholder="/wp-admin/" />
                <x-ui.field name="param" :label="__('Only for field (optional)')" placeholder="content" />
                <x-ui.field name="comment" :label="__('Note (optional)')" :placeholder="__('Contact form')" />
            </div>
            <div><button type="submit" class="btn btn-dark"><x-icon name="plus" />{{ __('Add exception') }}</button></div>
        </form>
    @endif
</x-ui.card>
