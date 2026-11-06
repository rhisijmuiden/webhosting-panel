<x-ui.table>
    <x-slot:head><th>{{ __('Severity') }}</th><th>{{ __('Website') }}</th><th>{{ __('Customer') }}</th><th>{{ __('What') }}</th><th>{{ __('Fix') }}</th><th>{{ __('Status') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
    @foreach ($findings as $f)
        <tr>
            <td>@include('vulnerabilities::partials.chip', ['finding' => $f])</td>
            <td>@if ($f->install)<a href="{{ route('admin.wordpress.show', $f->install) }}">{{ $f->siteLabel() }}</a>@else{{ $f->siteLabel() }}@endif</td>
            <td>{{ $f->website->customer?->displayName() }}</td>
            <td><strong>{{ $f->isMalware() ? __('Suspicious file') : $f->componentLabel() }}</strong><br>
                <span class="muted small">{{ $f->isMalware() ? $f->file_path : \Illuminate\Support\Str::limit($f->title, 90) }}</span>
                @if ($f->cve)<br><a class="small" href="https://www.cve.org/CVERecord?id={{ urlencode($f->cve) }}" target="_blank" rel="noopener noreferrer">{{ $f->cve }}</a>@endif</td>
            <td class="small">{{ $f->isMalware() ? __('Clean the file') : ($f->fixed_version ? __('Update to :v', ['v' => $f->fixed_version]) : __('No fix yet')) }}</td>
            <td class="small">
                @if ($f->status === 'open')
                    <x-ui.pill status="bad">{{ __('Unsolved') }}</x-ui.pill><br>
                    <span class="muted">{{ $f->notified_at ? __('mailed :time', ['time' => $f->notified_at->diffForHumans()]) : __('not mailed yet') }}</span>
                @elseif ($f->status === 'resolved')
                    <x-ui.pill status="ok">{{ __('Solved') }}</x-ui.pill> <span class="muted">{{ $f->resolved_at?->diffForHumans() }}</span>
                @else
                    <x-ui.pill>{{ __('Ignored') }}</x-ui.pill> <span class="muted">{{ $f->ignore_reason }}</span>
                @endif
            </td>
            <td class="num">
                <div class="actions" style="justify-content:flex-end;flex-wrap:nowrap;white-space:nowrap">
                    @if ($f->status === 'open')
                        <form method="POST" action="{{ route('admin.vulns.ignore', $f) }}" onsubmit="return confirm(@js(__('Ignore this problem? It is no longer mailed to the customer.')))">@csrf<button class="btn btn-sm btn-ghost" type="submit">{{ __('Ignore') }}</button></form>
                    @elseif ($f->status === 'ignored')
                        <form method="POST" action="{{ route('admin.vulns.reopen', $f) }}">@csrf<button class="btn btn-sm btn-ghost" type="submit">{{ __('Reopen') }}</button></form>
                    @endif
                    @if ($f->status === 'open' && $f->website->customer)
                        <form method="POST" action="{{ route('admin.vulns.notify', $f->website->customer) }}">@csrf<button class="btn btn-sm" type="submit" title="{{ __('Mail the customer now') }}"><x-icon name="send" />{{ __('Mail') }}</button></form>
                    @endif
                </div>
            </td>
        </tr>
    @endforeach
</x-ui.table>
