@php
    $bytes = function ($b) { if ($b === null) return '–'; $u = ['B', 'KB', 'MB', 'GB', 'TB']; $i = 0; while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; } return round($b, $i ? 1 : 0).' '.$u[$i]; };
    $pill = ['succeeded' => 'ok', 'failed' => 'bad', 'running' => 'warn'];
    $schedules = ['off' => __('Off'), 'daily' => __('Daily'), 'weekly' => __('Weekly')];
    $typeLabels = ['sftp' => 'SFTP', 'scp' => 'SCP', 'ftps' => 'FTPS', 'ftp' => 'FTP', 'onedrive' => 'OneDrive'];
    $onedrive = app(\Rhis\Backups\OneDrive::class);
@endphp
<x-layouts.admin :title="__('Customer backups')">
    <div class="page-head">
        <div><h1>{{ __('Backups') }}</h1><p class="sub">{{ __('Backups that customers make to their own locations, and their restores.') }}</p></div>
    </div>
    @include('backups::partials.admin-tabs')

    <x-ui.card :title="__('Customer backup locations')" flush>
        @if ($targets->isEmpty())
            <x-ui.empty icon="backup">{{ __('No customer has set up a backup location yet.') }}</x-ui.empty>
        @else
        <x-ui.table>
            <x-slot:head><th>{{ __('Customer') }}</th><th>{{ __('Name') }}</th><th>{{ __('Type') }}</th><th>{{ __('Where') }}</th><th>{{ __('Schedule') }}</th><th>{{ __('Status') }}</th></x-slot:head>
            @foreach ($targets as $t)
                <tr>
                    <td>{{ $t->customer?->displayName() }}</td>
                    <td><strong>{{ $t->name }}</strong></td>
                    <td>{{ $typeLabels[$t->type] ?? $t->type }}</td>
                    <td class="small mono">{{ $t->location() }}</td>
                    <td class="small">{{ $schedules[$t->schedule] ?? $t->schedule }}@if ($t->schedule !== 'off') · {{ $t->schedule_time }}@endif</td>
                    <td>@if ($t->last_error)<x-ui.pill status="bad">{{ __('Error') }}</x-ui.pill> <span class="small muted">{{ \Illuminate\Support\Str::limit($t->last_error, 80) }}</span>@else<x-ui.pill status="ok">{{ __('Ok') }}</x-ui.pill>@endif</td>
                </tr>
            @endforeach
        </x-ui.table>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('History')" flush>
        <x-slot:actions>
            @foreach (['' => __('All'), 'running' => __('Running'), 'failed' => __('Failed')] as $value => $label)
                <a href="{{ route('admin.backups.customers', array_filter(['status' => $value])) }}" @class(['btn', 'btn-sm', 'btn-dark' => (string) $status === $value])>{{ $label }}</a>
            @endforeach
        </x-slot:actions>
        @if ($records->isEmpty())
            <x-ui.empty icon="backup">{{ __('Nothing here yet.') }}</x-ui.empty>
        @else
        <x-ui.table>
            <x-slot:head><th>{{ __('Started') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Website') }}</th><th>{{ __('What') }}</th><th>{{ __('Location') }}</th><th>{{ __('Status') }}</th><th class="num">{{ __('Size') }}</th></x-slot:head>
            @foreach ($records as $r)
                <tr>
                    <td class="small">{{ $r->started_at?->isoFormat('lll') }}<br><span class="muted">{{ $r->trigger === 'schedule' ? __('Scheduled') : ($r->user ?? '') }}</span></td>
                    <td>{{ $r->customer?->displayName() }}</td>
                    <td>{{ $r->domain }}</td>
                    <td>{{ $r->label() }}@if ($r->file)<br><span class="small mono muted">{{ $r->file }}</span>@endif</td>
                    <td class="small">{{ $r->target?->name ?? '–' }}</td>
                    <td><x-ui.pill :status="$pill[$r->status] ?? 'neutral'">{{ __(ucfirst($r->status)) }}</x-ui.pill>@if ($r->message)<br><span class="small muted">{{ \Illuminate\Support\Str::limit($r->message, 120) }}</span>@endif</td>
                    <td class="num small" style="white-space:nowrap">{{ $bytes($r->size) }}</td>
                </tr>
            @endforeach
        </x-ui.table>
        @if ($records->hasPages())
            <div class="actions" style="padding: 12px 16px">
                @if ($records->previousPageUrl())<a class="btn btn-sm" href="{{ $records->previousPageUrl() }}">{{ __('Newer') }}</a>@endif
                @if ($records->nextPageUrl())<a class="btn btn-sm" href="{{ $records->nextPageUrl() }}">{{ __('Older') }}</a>@endif
            </div>
        @endif
        @endif
    </x-ui.card>

    <x-ui.card :title="__('OneDrive for customers')" style="margin-top: 24px">
        <p class="small muted">{{ __('Customers can use their own OneDrive as a backup location after the panel is registered once as an app at Microsoft. In the Microsoft Entra admin center: App registrations > New registration, choose "Accounts in any organizational directory and personal Microsoft accounts", platform Web, and the redirect URI below. Then make a client secret under Certificates & secrets and fill in both values here.') }}</p>
        <p class="small">{{ __('Redirect URI') }}: <span class="mono">{{ $onedrive->redirectUri() }}</span></p>
        <form method="POST" action="{{ route('admin.backups.onedrive.update') }}" class="form">
            @csrf @method('PUT')
            <div class="form-grid">
                <x-ui.field name="client_id" :label="__('Application (client) ID')" :value="$onedrive->clientId()" placeholder="00000000-0000-0000-0000-000000000000" required />
                <x-ui.field name="client_secret" type="password" :label="__('Client secret (value)')" autocomplete="new-password"
                    :help="$onedrive->configured() ? __('Leave empty to keep the current secret.') : null" />
            </div>
            <div class="actions">
                <button type="submit" class="btn btn-dark"><x-icon name="check" />{{ __('Save') }}</button>
                @if ($onedrive->configured())<x-ui.pill status="ok">{{ __('Active') }}</x-ui.pill>@endif
            </div>
        </form>
        @if ($onedrive->configured())
            <form method="POST" action="{{ route('admin.backups.onedrive.update') }}" data-confirm="{{ __('Turn off OneDrive for customers? Existing OneDrive locations stop working.') }}" style="margin-top: 12px">
                @csrf @method('PUT')<input type="hidden" name="remove" value="1">
                <button type="submit" class="btn btn-danger"><x-icon name="trash" />{{ __('Turn off') }}</button>
            </form>
        @endif
    </x-ui.card>
</x-layouts.admin>
