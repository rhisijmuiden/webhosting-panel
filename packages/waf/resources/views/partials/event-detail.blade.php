{{-- $event, $allowUrl (null = no allow form), $scopes --}}
<section class="bento">
    <div class="card span-8 card-flush">
        <div class="card-head"><h2>{{ __('Rules that matched') }}</h2>@if ($event->score)<span class="muted small">{{ __('Score :n', ['n' => $event->score]) }}</span>@endif</div>
        <ul class="waf-rules">
            @foreach ($event->rules as $r)
                <li><code>{{ $r['id'] }}</code><span>{{ $r['message'] }}</span>
                    @if ($r['data'] ?? '')<span class="data">{{ $r['data'] }}</span>@endif</li>
            @endforeach
        </ul>
    </div>
    <div class="card span-4">
        <div class="card-head"><h2>{{ __('Request') }}</h2></div>
        <dl class="kv">
            <dt>{{ __('Result') }}</dt><dd>@if ($event->blocked)<x-ui.pill status="ok">{{ __('Blocked') }}</x-ui.pill>@else<x-ui.pill status="warn">{{ __('Detected, let through') }}</x-ui.pill>@endif</dd>
            <dt>{{ __('Attack') }}</dt><dd>@include('waf::partials.kind', ['event' => $event])</dd>
            <dt>{{ __('When') }}</dt><dd>{{ $event->occurred_at->isoFormat('LLLL') }}</dd>
            <dt>{{ __('Address') }}</dt><dd>{{ $event->host }}<span class="mono small block">{{ $event->method }} {{ $event->uri }}</span></dd>
            <dt>{{ __('From') }}</dt><dd class="mono">{{ $event->ip }}</dd>
            @if ($event->user_agent)<dt>{{ __('Browser') }}</dt><dd class="small">{{ $event->user_agent }}</dd>@endif
            <dt>{{ __('Answer') }}</dt><dd>HTTP {{ $event->status ?: '–' }}</dd>
        </dl>
    </div>
</section>
@if ($allowUrl)
    <x-ui.card :title="__('This was not an attack')">
        <p class="muted small">{{ __('Was this you or a normal visitor (a contact form, an editor, an upload)? Then switch these rules off here. Real attacks are still stopped by the other rules.') }}</p>
        <form method="POST" action="{{ $allowUrl }}" class="form">
            @csrf
            <fieldset class="field waf-fieldset"><legend>{{ __('Rules') }}</legend>
                @foreach ($event->rules as $r)
                    <label class="check"><input type="checkbox" name="rules[]" value="{{ $r['id'] }}" checked> <code>{{ $r['id'] }}</code> <span class="small">{{ \Illuminate\Support\Str::limit($r['message'], 80) }}</span></label>
                @endforeach
            </fieldset>
            <div class="seg" role="radiogroup" aria-label="{{ __('Where') }}">
                @foreach ($scopes as $s => $label)
                    <label><input type="radio" name="scope" value="{{ $s }}" @checked($loop->first)><span>{{ $label }}</span></label>
                @endforeach
            </div>
            <div><button type="submit" class="btn btn-dark"><x-icon name="check" />{{ __('Allow') }}</button></div>
        </form>
    </x-ui.card>
@endif
