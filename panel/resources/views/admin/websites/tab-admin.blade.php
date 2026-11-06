{{-- Administration tab: only on the admin side of the domain page. Server details, package cards and removal. --}}
<section class="grid grid-2" style="align-items:start">
    <x-ui.card :title="__('Details')">
        <dl class="kv">
            <dt>{{ __('Customer') }}</dt><dd>@if ($site->customer)<a href="{{ route('admin.customers.show', $site->customer) }}">{{ $site->customer->displayName() }}</a>@else – @endif</dd>
            <dt>{{ __('Type') }}</dt><dd>{{ __(ucfirst($site->profile ?? 'php')) }}</dd>
            <dt>{{ __('Aliases') }}</dt><dd>{{ $site->aliases ? implode(', ', $site->aliases) : '–' }}</dd>
            <dt>{{ __('System user') }}</dt><dd class="mono">{{ $site->system_user ?? '–' }}</dd>
            <dt>{{ __('Web root') }}</dt><dd class="mono">{{ $site->document_root ?? '–' }}</dd>
            <dt>{{ __('Disk') }}</dt><dd>{{ $site->disk_used_mb }} MB @if ($site->disk_quota_mb) / {{ $site->disk_quota_mb }} MB @endif</dd>
            <dt>{{ __('Subscription') }}</dt><dd>@if ($sub)<a href="{{ route('admin.subscriptions.edit', $sub) }}">{{ $pkg?->name ?? __('No package') }}</a>
                <x-ui.pill :status="$sub->status">{{ __(ucfirst($sub->status)) }}</x-ui.pill>@if ($sub->expires_on) <span class="small muted">{{ __('until :date', ['date' => $sub->expires_on->isoFormat('LL')]) }}</span>@endif @else – @endif</dd>
            <dt>{{ __('Resource jail') }}</dt><dd class="mono small">{{ $sub ? 'whp-sites-'.$sub->sliceOwner().'.slice' : 'whp-sites.slice' }}</dd>
            <dt>{{ __('Created') }}</dt><dd>{{ $site->created_at?->isoFormat('LLL') }}</dd>
        </dl>
    </x-ui.card>
    <x-ui.card :title="__('Remove website')">
        <p class="muted small">{{ __('Removes the Nginx site, PHP pool and certificate. This cannot be undone.') }}</p>
        <form method="POST" action="{{ route('admin.websites.destroy', $site) }}" class="form">
            @csrf @method('DELETE')
            <x-ui.field name="confirm" :label="__('Type :domain to confirm', ['domain' => $site->domain])" autocomplete="off" required />
            <label class="check"><input type="checkbox" name="keep_files" value="1" checked><span>{{ __('Keep the files on the server') }}</span></label>
            <div><button class="btn btn-danger" type="submit"><x-icon name="trash" />{{ __('Remove website') }}</button></div>
        </form>
    </x-ui.card>
</section>
@includeIf('wordpress::partials.website-card', ['website' => $site])
@includeIf('git::partials.website-card', ['website' => $site])
