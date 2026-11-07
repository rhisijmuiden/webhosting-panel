@php $d = $item->source_data; $plan = $item->options['plan'] ?? []; $http = $item->wizardValue('http'); @endphp
@if ($current['applicable'])
<section class="grid grid-2" style="align-items:start">
    <x-ui.card :title="__('What was copied')">
        <dl class="kv">
            <dt>{{ __('Type') }}</dt><dd>{{ ['wordpress' => 'WordPress', 'laravel' => 'Laravel', 'php' => 'PHP'][$plan['profile'] ?? 'php'] ?? 'PHP' }} · PHP {{ $plan['php'] ?? '–' }}</dd>
            <dt>{{ __('Document root') }}</dt><dd class="mono small">{{ $plan['docroot'] ?? '–' }}</dd>
            <dt>{{ __('Aliases') }}</dt><dd>{{ implode(', ', $plan['aliases'] ?? []) ?: '–' }}</dd>
            @if (isset($item->result['files']['stats']['total_size']))<dt>{{ __('Size') }}</dt><dd>{{ $item->result['files']['stats']['total_size'] }}</dd>@endif
            @if ($item->website)<dt>{{ __('Website') }}</dt><dd><a href="{{ route('admin.websites.show', $item->website) }}">{{ __('Open website settings') }}</a></dd>@endif
        </dl>
    </x-ui.card>
    <x-ui.card :title="__('Test the website on this server')">
        <p class="small">{{ __('The panel asks this server for the home page, the way visitors reach it after the switch.') }}</p>
        @if ($http)
            <p class="small"><x-ui.pill :status="($http['error'] ?? null) || $http['status'] >= 500 ? 'bad' : ($http['status'] >= 400 ? 'warn' : 'ok')">{{ ($http['error'] ?? null) ? __('No answer') : 'HTTP '.$http['status'] }}</x-ui.pill>
                <span class="muted">{{ \Illuminate\Support\Carbon::parse($http['at'])->diffForHumans() }}</span>
                @if ($http['location'] ?? null)<br><span class="muted">{{ __('Redirects to') }}</span> <span class="mono">{{ $http['location'] }}</span>@endif</p>
        @endif
        <form method="POST" action="{{ route('admin.migration.wizard.test', $item) }}">@csrf<button class="btn" type="submit"><x-icon name="activity" />{{ __('Test the website') }}</button></form>
        <p class="small">{{ __('Check it in your own browser too, by adding this line to your hosts file:') }}</p>
        <pre class="log" style="white-space:pre-wrap">{{ $serverIp ?: '<server-ip>' }}  {{ $item->domain }} www.{{ $item->domain }}</pre>
        @if (($d['web']['app'] ?? '') === 'wordpress')
            <form method="POST" action="{{ route('admin.migration.items.wordpress', $item) }}">@csrf<button class="btn" type="submit"><x-icon name="wordpress" />{{ __('Fix WordPress settings') }}</button></form>
        @endif
    </x-ui.card>
</section>
@endif
