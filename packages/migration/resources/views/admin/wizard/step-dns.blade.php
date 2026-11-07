@php $mode = $dns['mode'] ?? 'error'; @endphp
@if ($mode === 'error')
    <div class="alert alert-bad" role="alert"><x-icon name="info" />{{ __('Cloudflare could not be read: :error', ['error' => $dns['error'] ?? '']) }}</div>
@elseif ($mode === 'manual' || $mode === 'no_zone')
    <x-ui.card :title="$mode === 'manual' ? __('No Cloudflare connected') : __('The domain is not in Cloudflare yet')">
        @if ($mode === 'no_zone' && $hasCloudflare)
            <p class="small">{{ __('Add the domain to Cloudflare first. Cloudflare copies the records it finds at the current DNS provider; nothing changes for visitors until you change the nameservers at the registrar.') }}</p>
            <form method="POST" action="{{ route('admin.migration.wizard.zone', $item) }}">@csrf<button class="btn btn-primary" type="submit"><x-icon name="cloud" />{{ __('Add domain to Cloudflare') }}</button></form>
            <p class="small muted">{{ __('Or switch by hand: load this zone at the current DNS provider after the final check.') }}</p>
        @else
            <p class="small">{{ __('Without Cloudflare the switch gives a zone file to load at the DNS provider of the domain.') }}</p>
        @endif
        <details><summary class="small">{{ __('Zone file') }}</summary><pre class="log">{{ $dns['zone'] ?? '' }}</pre></details>
        <form method="POST" action="{{ route('admin.migration.wizard.confirm', [$item, 'dns']) }}">@csrf<input type="hidden" name="total" value="0">
            <button class="btn" type="submit"><x-icon name="check" />{{ __('Switch by hand with the zone file') }}</button></form>
    </x-ui.card>
@else
    @php
        $changes = collect($dns['rows'])->filter->isChange();
        $rest = collect($dns['rows'])->reject->isChange();
        $saved = $dns['saved']; $stale = $dns['stale'];
    @endphp
    @if (($dns['zone_status'] ?? null) === 'pending')
        <div class="alert alert-warn"><x-icon name="info" /><div><strong>{{ __('Cloudflare is not live for this domain yet.') }}</strong>
            <p class="small">{{ __('The records below are ready in Cloudflare, but visitors only reach them after the nameservers at the registrar are changed:') }} <span class="mono">{{ implode(', ', $dns['name_servers'] ?: [__('see Cloudflare')]) }}</span></p></div></div>
    @endif
    @if ($stale)<div class="alert alert-warn"><x-icon name="info" />{{ __('The records in Cloudflare changed since you made your choice. Check the list again and save it.') }}</div>@endif
    <form method="POST" action="{{ route('admin.migration.wizard.confirm', [$item, 'dns']) }}">
        @csrf
        <input type="hidden" name="total" value="{{ $changes->count() }}">
        <x-ui.card :title="__('What changes in Cloudflare')">
            <p class="small">{{ __('Tick the records to change. Unticked records stay exactly as they are now, for example records of Microsoft 365 or another external service. Nothing is sent to Cloudflare until the final check.') }}</p>
            @if ($changes->isEmpty())
                <p class="small"><x-ui.pill status="ok">{{ __('Cloudflare already has everything this server needs.') }}</x-ui.pill></p>
            @else
                @include('migration::admin.wizard.dns-rows', ['rows' => $changes->values()->all(), 'saved' => $saved, 'stale' => $stale])
            @endif
            <div class="mig-bar"><span class="small muted">{{ trans_choice(':count change found|:count changes found', $changes->count()) }}</span>
                <button class="btn btn-primary" type="submit"><x-icon name="check" />{{ __('Save choice, next step') }}</button></div>
        </x-ui.card>
    </form>
    @if ($rest->isNotEmpty())
        <x-ui.card>
            <details><summary class="small"><strong>{{ trans_choice(':count record stays as it is|:count records stay as they are', $rest->count()) }}</strong></summary>
                @include('migration::admin.wizard.dns-rows', ['rows' => $rest->values()->all(), 'saved' => null, 'stale' => false, 'editable' => false])
            </details>
        </x-ui.card>
    @endif
    @if ($dns['skipped'])
        <x-ui.card>
            <details><summary class="small">{{ trans_choice(':count record of the old server was not copied|:count records of the old server were not copied', count($dns['skipped'])) }}</summary>
                <ul class="small mono">@foreach ($dns['skipped'] as $s)<li>{{ $s }}</li>@endforeach</ul></details>
        </x-ui.card>
    @endif
@endif
