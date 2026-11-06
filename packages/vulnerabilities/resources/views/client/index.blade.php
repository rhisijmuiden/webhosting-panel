<x-layouts.client :title="__('Security alerts')">
    @include('vulnerabilities::partials.styles')
    <div class="page-head">
        <div><h1>{{ __('Security alerts') }}</h1>
            <p class="sub">{{ __('We check your websites every night for known security holes in WordPress, plugins and themes, and for malware.') }}</p></div>
        <div class="actions"><form method="POST" action="{{ route('client.vulns.scan') }}">@csrf<x-ui.button icon="refresh">{{ __('Check again') }}</x-ui.button></form></div>
    </div>

    @if ($open->isEmpty())
        <x-ui.card>
            <div class="vx-allclear"><span class="vx-dot ok" aria-hidden="true">✓</span>
                <div><strong style="font-size:18px">{{ __('All clear') }}</strong><p class="muted small" style="margin:4px 0 0">{{ __('No known security problems on your websites. We keep checking every night and mail you if we find something.') }}</p></div></div>
        </x-ui.card>
    @else
        <section class="grid grid-stats">
            @foreach (['critical' => __('Critical'), 'high' => __('High'), 'medium' => __('Medium'), 'low' => __('Low'), 'malware' => __('Malware')] as $s => $label)
                <div class="card stat sev-{{ $s }}" style="border-top:3px solid var(--sc)"><span class="label">{{ $label }}</span><strong class="value">{{ $totals[$s] }}</strong></div>
            @endforeach
        </section>
        @foreach ($groups as $site => $list)
            <h2 class="vx-site">{{ $site }}</h2>
            <div class="vx-list">
                @foreach ($list as $f)
                    @include('vulnerabilities::partials.finding', ['f' => $f, 'fixUrl' => ! $f->isMalware() && $toolkit && $f->install && Route::has('client.wordpress.show') ? route('client.wordpress.show', $f->install) : null])
                @endforeach
            </div>
        @endforeach
        <p class="muted small">{{ __('We never update your website without asking: you decide. The update button makes a backup first and undoes the update if your site breaks.') }}</p>
    @endif

    @if ($resolved->isNotEmpty())
    <x-ui.card :title="__('Solved in the last 30 days')" flush>
        <x-ui.table>
            <x-slot:head><th>{{ __('Website') }}</th><th>{{ __('What') }}</th><th>{{ __('Solved') }}</th></x-slot:head>
            @foreach ($resolved as $f)
                <tr><td>{{ $f->siteLabel() }}</td><td>{{ $f->isMalware() ? $f->file_path : $f->componentLabel() }} <span class="muted small">{{ \Illuminate\Support\Str::limit($f->title, 70) }}</span></td><td class="small">{{ $f->resolved_at?->diffForHumans() }}</td></tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    @endif

    <x-ui.card :title="__('Who gets these mails')" id="notifications">
        <form method="POST" action="{{ route('client.vulns.notifications') }}" class="form">
            @csrf @method('PUT')
            <input type="hidden" name="enabled" value="0">
            <label class="check"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $contact->enabled))>
                <span>{{ __('Mail me when a security problem is found') }}<small>{{ __('Sent to :email.', ['email' => $customer->email]) }}</small></span></label>
            <x-ui.field name="extra_emails" :label="__('Also send to')" :value="implode(', ', $contact->extra_emails ?? [])" placeholder="webbouwer@example.nl"
                :help="__('For example your web developer. Up to 5 addresses, separated by commas.')" />
            <div><x-ui.button variant="dark">{{ __('Save') }}</x-ui.button></div>
        </form>
    </x-ui.card>
</x-layouts.client>
