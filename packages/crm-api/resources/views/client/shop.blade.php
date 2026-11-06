@php
    $locale = app()->getLocale();
    $money = fn ($p) => '€ '.number_format((float) $p['amount'], 2, $locale === 'nl' ? ',' : '.', $locale === 'nl' ? '.' : ',');
    $interval = ['month' => __('per month'), 'year' => __('per year'), 'once' => __('one-off')];
    $statusPill = ['requested' => 'info', 'awaiting_payment' => 'warn', 'accepted' => 'info', 'completed' => 'ok', 'rejected' => 'bad', 'cancelled' => 'neutral'];
    $statusLabel = ['requested' => __('Requested'), 'awaiting_payment' => __('Awaiting payment'), 'accepted' => __('Accepted'), 'completed' => __('Completed'), 'rejected' => __('Rejected'), 'cancelled' => __('Cancelled')];
@endphp
<x-layouts.client :title="__('Order')">
    <div class="page-head">
        <div><h1>{{ __('Order') }}</h1><p class="sub">{{ __('Extra services for your hosting. Prices excl. VAT.') }}</p></div>
        @if ($shopUrl)<div class="actions"><x-ui.button :href="$shopUrl" icon="globe" target="_blank" rel="noopener">{{ __('Visit our webshop') }}</x-ui.button></div>@endif
    </div>
    @if (session('status'))<div class="alert alert-ok" role="status">{{ session('status') }}</div>@endif
    @foreach ($errors->all() as $e)<div class="alert alert-bad" role="alert">{{ $e }}</div>@endforeach

    @if (empty($products))
        <x-ui.card><x-ui.empty icon="package">{{ __('There is nothing to order here yet.') }}</x-ui.empty></x-ui.card>
    @else
    <section class="grid grid-2" style="align-items:start">
        @foreach ($products as $p)
            <x-ui.card :title="$p['name'][$locale] ?? $p['name']['en']" @class(['card-selected' => $selected === $p['code']]) id="product-{{ $p['code'] }}">
                @if (! empty($p['description']))<p class="muted">{{ $p['description'][$locale] ?? $p['description']['en'] ?? '' }}</p>@endif
                <p><strong style="font-size:1.4em">{{ $money($p['price']) }}</strong> <span class="muted small">{{ $p['per_unit'] ? __('per user').' ' : '' }}{{ $interval[$p['price']['interval'] ?? 'month'] ?? '' }}</span></p>
                <form method="POST" action="{{ route('client.shop.orders.store') }}" class="form">
                    @csrf
                    <input type="hidden" name="product" value="{{ $p['code'] }}">
                    <div class="form-grid">
                        @if ($p['per_unit'])<x-ui.field name="quantity" type="number" min="1" max="1000" value="1" :label="__('Number of users')" />@endif
                        @if ($domains->isNotEmpty())
                            <x-ui.field name="domain" type="select" :label="__('For domain')">
                                @foreach ($domains as $d)<option value="{{ $d }}">{{ $d }}</option>@endforeach
                            </x-ui.field>
                        @endif
                    </div>
                    <x-ui.field name="note" type="textarea" rows="2" :label="__('Note (optional)')" />
                    <div class="actions">
                        <button type="submit" class="btn btn-dark">{{ __('Request') }}</button>
                        @if (! empty($p['shop_url']))<a class="btn" href="{{ $p['shop_url'] }}" target="_blank" rel="noopener">{{ __('More information') }}</a>@endif
                    </div>
                </form>
            </x-ui.card>
        @endforeach
    </section>
    @endif

    @if ($orders->isNotEmpty())
    <x-ui.card :title="__('Your orders')" flush>
        <x-ui.table>
            <x-slot:head><th>{{ __('Product') }}</th><th>{{ __('Status') }}</th><th>{{ __('Date') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
            @foreach ($orders as $o)
                <tr>
                    <td>{{ $o->product_code }}{{ $o->quantity > 1 ? ' × '.$o->quantity : '' }}{{ $o->domain ? ' · '.$o->domain : '' }}@if ($o->message)<br><span class="small muted">{{ $o->message }}</span>@endif</td>
                    <td><x-ui.pill :status="$statusPill[$o->status] ?? 'neutral'">{{ $statusLabel[$o->status] ?? $o->status }}</x-ui.pill></td>
                    <td class="small muted">{{ $o->created_at->isoFormat('LL') }}</td>
                    <td class="num">
                        <div class="actions" style="justify-content:flex-end">
                            @if ($o->checkout_url && $o->status === 'awaiting_payment')<a class="btn btn-sm btn-primary" href="{{ $o->checkout_url }}" target="_blank" rel="noopener">{{ __('Pay') }}</a>@endif
                            @if (in_array($o->status, ['requested', 'awaiting_payment'], true))
                                <form method="POST" action="{{ route('client.shop.orders.cancel', $o) }}" data-confirm="{{ __('Cancel this order?') }}">@csrf @method('DELETE')<button class="btn btn-sm" type="submit">{{ __('Cancel') }}</button></form>
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    @endif
</x-layouts.client>
