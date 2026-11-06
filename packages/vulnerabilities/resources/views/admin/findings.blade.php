<x-layouts.admin :title="__('Security problems')">
    @include('vulnerabilities::partials.styles')
    <div class="page-head">
        <div><p class="crumbs"><a href="{{ route('admin.vulns.index') }}">{{ __('Security overview') }}</a></p><h1>{{ __('Security problems') }}</h1></div>
        <div class="actions">
            @if ($filters['customer'] ?? null)
                <x-ui.button :href="route('admin.vulns.preview', $filters['customer'])" icon="mail" target="_blank">{{ __('Preview the mail') }}</x-ui.button>
                <form method="POST" action="{{ route('admin.vulns.notify', $filters['customer']) }}">@csrf<x-ui.button variant="primary" icon="send">{{ __('Mail the customer now') }}</x-ui.button></form>
            @endif
        </div>
    </div>
    <x-ui.card flush>
        <form method="GET" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:16px 20px;border-bottom:1px solid var(--line-soft)">
            <select name="status" class="select" aria-label="{{ __('Status') }}" style="width:auto">
                @foreach (['open' => __('Unsolved'), 'resolved' => __('Solved'), 'ignored' => __('Ignored'), 'all' => __('All')] as $v => $l)<option value="{{ $v }}" @selected($filters['status'] === $v)>{{ $l }}</option>@endforeach
            </select>
            <select name="severity" class="select" aria-label="{{ __('Severity') }}" style="width:auto">
                <option value="">{{ __('Every severity') }}</option>
                @foreach (['malware' => __('Malware'), 'critical' => __('Critical'), 'high' => __('High'), 'medium' => __('Medium'), 'low' => __('Low'), 'unknown' => __('Unknown')] as $v => $l)<option value="{{ $v }}" @selected(($filters['severity'] ?? '') === $v)>{{ $l }}</option>@endforeach
            </select>
            <select name="customer" class="select" aria-label="{{ __('Customer') }}" style="width:auto">
                <option value="">{{ __('Every customer') }}</option>
                @foreach ($customers as $c)<option value="{{ $c->id }}" @selected((int) ($filters['customer'] ?? 0) === $c->id)>{{ $c->displayName() }}</option>@endforeach
            </select>
            <input type="search" name="q" class="input" style="width:220px" value="{{ $filters['q'] ?? '' }}" placeholder="{{ __('Plugin, CVE or domain') }}" aria-label="{{ __('Search') }}">
            <button class="btn btn-dark" type="submit">{{ __('Filter') }}</button>
        </form>
        @if ($findings->isEmpty())
            <x-ui.empty icon="shield">{{ __('No problems match these filters.') }}</x-ui.empty>
        @else
            @include('vulnerabilities::admin.table', ['findings' => $findings])
        @endif
    </x-ui.card>
    {{ $findings->links('partials.pagination') }}
</x-layouts.admin>
