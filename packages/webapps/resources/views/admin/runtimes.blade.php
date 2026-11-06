<x-layouts.admin :title="__('Runtime versions')">
    @include('webapps::partials.styles')
    @php $confirm = fn (string $text) => 'if (!confirm('.\Illuminate\Support\Js::from($text).')) $event.preventDefault()'; @endphp
    <div class="page-head">
        <div>
            <div class="crumbs"><a href="{{ route('admin.webapps.index') }}">{{ __('Node.js & Python') }}</a></div>
            <h1>{{ __('Runtime versions') }}</h1>
            <p class="sub">{{ __('Native builds on this server, one folder per version: Node.js from nodejs.org (checksum verified), Python through uv. Customers choose from the installed versions. Versions past their end of life are no longer offered for new apps.') }}</p>
        </div>
    </div>
    @foreach ($errors->get('agent') as $e)<div class="alert alert-bad" role="alert"><x-icon name="info" /><div>{{ $e }}</div></div>@endforeach
    @if (! empty($runtimes['error']))<div class="alert alert-bad" role="alert"><x-icon name="info" /><div>{{ $runtimes['error'] }}</div></div>@endif
    @if (($runtimes['uv'] ?? true) === false)<div class="alert alert-warn" role="status"><x-icon name="info" /><div>{{ __('uv is not installed yet; it comes with the next panel update. Python versions can be installed after that.') }}</div></div>@endif

    <div x-data="{ job: @js($job ? ['status' => 'running'] + $job : null),
                   async poll() { const r = await fetch(@js(route('admin.webapps.runtimes.job')), {headers: {Accept: 'application/json'}}); if (!r.ok) return;
                                  const d = await r.json(); if (d.status === 'none') { if (this.job && this.job.status === 'running') location.reload(); return; }
                                  this.job = d; if (d.status !== 'running') setTimeout(() => location.reload(), 1500); },
                   init() { if (this.job) setInterval(() => this.poll(), 3000); } }">
        <template x-if="job">
            <div class="alert" :class="job.status === 'failed' ? 'alert-bad' : (job.status === 'succeeded' ? 'alert-ok' : 'alert-info')" role="status">
                <x-icon name="refresh" /><div><strong x-text="job.status === 'running' ? @js(__('Installing…')) : (job.status === 'failed' ? (job.error || @js(__('Failed'))) : @js(__('Installed')))"></strong>
                <pre class="log" x-show="job.log && job.log.length" x-text="(job.log || []).join('\n')"></pre></div></div>
        </template>
    </div>

    @foreach (\Rhis\WebApps\Models\WebApp::RUNTIMES as $rt => $label)
    <x-ui.card :title="$label" flush>
        <x-ui.table>
            <x-slot:head><th>{{ __('Version') }}</th><th>{{ __('Installed') }}</th><th>{{ __('Supported until') }}</th><th class="num">{{ __('Apps') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
            @foreach ($runtimes[$rt] ?? [] as $row)
                @php $n = $usage[$rt.':'.$row['version']] ?? 0; @endphp
                <tr>
                    <td><div class="wa-runtime">@include('webapps::partials.badge', ['runtime' => $rt])<strong>{{ $label }} {{ $row['version'] }}</strong>
                        @if ($row['lts'] ?? null)<x-ui.pill status="info" :dot="false">LTS {{ $row['lts'] }}</x-ui.pill>@endif</div></td>
                    <td>@if ($row['installed'])<span class="mono">{{ $row['full_version'] }}</span>
                            @if ($row['update_available'] ?? false)<x-ui.pill status="warn">{{ __(':version available', ['version' => $row['latest']]) }}</x-ui.pill>@endif
                        @else<span class="muted">{{ __('Not installed') }}</span>@endif</td>
                    <td>{{ \Illuminate\Support\Carbon::parse($row['eol_date'])->isoFormat('LL') }}
                        @if ($row['eol'])<x-ui.pill status="bad">{{ __('End of life') }}</x-ui.pill>@endif</td>
                    <td class="num">{{ $n }}</td>
                    <td class="num"><div class="actions" style="justify-content:flex-end">
                        @if (! $row['installed'] && ! $row['eol'])
                            <form method="POST" action="{{ route('admin.webapps.runtimes.install') }}">@csrf<input type="hidden" name="runtime" value="{{ $rt }}"><input type="hidden" name="version" value="{{ $row['version'] }}"><x-ui.button size="sm" variant="primary" icon="plus">{{ __('Install') }}</x-ui.button></form>
                        @elseif ($row['installed'])
                            <form method="POST" action="{{ route('admin.webapps.runtimes.install') }}">@csrf<input type="hidden" name="runtime" value="{{ $rt }}"><input type="hidden" name="version" value="{{ $row['version'] }}"><x-ui.button size="sm" icon="refresh">{{ __('Update') }}</x-ui.button></form>
                            @if ($n === 0)
                                <form method="POST" action="{{ route('admin.webapps.runtimes.destroy') }}" x-data x-on:submit="{{ $confirm(__('Remove this version from the server?')) }}">@csrf @method('DELETE')<input type="hidden" name="runtime" value="{{ $rt }}"><input type="hidden" name="version" value="{{ $row['version'] }}"><x-ui.button size="sm" icon="trash">{{ __('Remove') }}</x-ui.button></form>
                            @endif
                        @endif
                    </div></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    @endforeach
</x-layouts.admin>
