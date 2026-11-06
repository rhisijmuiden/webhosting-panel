<x-layouts.admin :title="__('Migration')">
    <div class="page-head">
        <div><h1>{{ __('Migration') }}</h1><p class="sub">{{ __('Move websites, databases, mailboxes, certificates and DNS from a Plesk server to this panel.') }}</p></div>
    </div>
    @include('migration::admin.partials.tabs')

    @foreach ($agentErrors as $e)<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $e }}</div>@endforeach
    @error('agent')<div class="alert alert-bad" role="alert"><x-icon name="info" />{{ $message }}</div>@enderror
    @if (session('status'))<div class="alert alert-ok" role="status">{{ session('status') }}</div>@endif

    <x-ui.card :title="__('Source servers')" flush>
        @if ($sources->isEmpty())
            <x-ui.empty icon="migrate">{{ __('No source server yet.') }}</x-ui.empty>
        @else
            <x-ui.table>
                <x-slot:head><th>{{ __('Server') }}</th><th>{{ __('Type') }}</th><th>{{ __('Status') }}</th><th>{{ __('Migrated') }}</th><th class="num">{{ __('Running') }}</th><th class="num">{{ __('Failed') }}</th><th></th></x-slot:head>
                @foreach ($sources as $s)
                    <tr>
                        <td><a class="strong" href="{{ route('admin.migration.sources.show', $s) }}">{{ $s->name }}</a><div class="small muted mono">{{ $s->user.'@'.$s->host }}:{{ $s->port }}</div></td>
                        <td>{{ $types[$s->type] ?? $s->type }} {{ $s->version }}</td>
                        <td><x-ui.pill :status="['connected' => 'ok', 'error' => 'bad'][$s->status] ?? 'neutral'">{{ __(ucfirst($s->status)) }}</x-ui.pill></td>
                        @php $total = (int) ($s->inventory['totals']['domains'] ?? 0); $started = $s->items_count - $s->failed_count; @endphp
                        <td style="min-width:170px">
                            @if ($total)
                                <div class="mig-bar-line" role="progressbar" aria-label="{{ __('Migrated') }}" aria-valuemin="0" aria-valuemax="{{ $total }}" aria-valuenow="{{ $started }}"><span style="width: {{ min(100, round($started / $total * 100)) }}%"></span></div>
                                <div class="small muted">{{ __(':done of :total domains started, :switched switched', ['done' => $started, 'total' => $total, 'switched' => $s->done_count]) }}</div>
                            @else<span class="small muted">{{ __('Not read yet') }}</span>@endif
                        </td>
                        <td class="num">{{ $s->active_count }}</td>
                        <td class="num">{{ $s->failed_count }}</td>
                        <td class="num"><a class="btn btn-sm" href="{{ route('admin.migration.sources.show', ['source' => $s, 'show' => 'todo']) }}"><x-icon name="migrate" />{{ __('Migrate next domains') }}</a></td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>
    <x-ui.card :title="__('How it works')">
        <ol class="mig-steps">
            <li><strong>{{ __('Connect') }}</strong><span>{{ __('Add the panel key to the Plesk server and test the SSH login.') }}</span></li>
            <li><strong>{{ __('Choose') }}</strong><span>{{ __('The panel reads all subscriptions, domains, databases and mailboxes. Pick what to move and to which customer.') }}</span></li>
            <li><strong>{{ __('Copy') }}</strong><span>{{ __('Files, databases and mail are copied while the old server keeps running. Nothing changes for visitors yet.') }}</span></li>
            <li><strong>{{ __('Check and sync') }}</strong><span>{{ __('Test the copy, then sync again to pick up the latest changes.') }}</span></li>
            <li><strong>{{ __('Switch DNS') }}</strong><span>{{ __('One click points the domain here and takes over its own DNS records. Late mail can be fetched afterwards.') }}</span></li>
        </ol>
    </x-ui.card>

    <section class="grid grid-2" style="align-items:start">
        <x-ui.card :title="__('Add a source server')">
            <form method="POST" action="{{ route('admin.migration.sources.store') }}" class="form">
                @csrf
                <div class="form-grid">
                    <x-ui.field name="name" :label="__('Name')" :value="old('name', 'Plesk')" required />
                    <x-ui.field name="type" type="select" :label="__('Type')">
                        @foreach ($types as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                    </x-ui.field>
                    <x-ui.field name="host" :label="__('Host name or IP address')" placeholder="plesk.example.com" required />
                    <x-ui.field name="port" type="number" :label="__('SSH port')" value="22" required />
                    <x-ui.field name="user" :label="__('SSH user')" value="root" required :help="__('Root is needed to read mailboxes and databases.')" />
                </div>
                <div><button type="submit" class="btn btn-dark"><x-icon name="plus" />{{ __('Add and test') }}</button></div>
            </form>
        </x-ui.card>

        <x-ui.card :title="__('Panel SSH key')">
            @if ($key)
                <p class="muted small">{{ __('Run this once as root on the Plesk server. It allows the panel to log in with its own key; remove the line again when the migration is done.') }}</p>
                <pre class="log mig-copy" style="white-space:pre-wrap;word-break:break-all">mkdir -p /root/.ssh && echo '{{ $key['public_key'] }}' >> /root/.ssh/authorized_keys && chmod 600 /root/.ssh/authorized_keys</pre>
                <p class="small muted">{{ __('Key fingerprint') }}: <span class="mono">{{ $key['fingerprint'] }}</span></p>
            @else
                <x-ui.empty icon="lock">{{ __('The agent is not reachable, so the key cannot be shown.') }}</x-ui.empty>
            @endif
        </x-ui.card>
    </section>

    @include('migration::admin.styles')
</x-layouts.admin>
