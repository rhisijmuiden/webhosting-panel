@php use Rhis\Monitoring\Describe; @endphp
<x-layouts.base :title="$title">
@include('monitoring::partials.styles')
<style>
body { background: var(--surface); }
.st-top { background: var(--nacht); color: #fff; }
.st-top .in { max-width: 860px; margin: 0 auto; padding: 22px 16px 70px; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.st-top img { height: 30px; width: auto; }
.st-top .st-label { font-size: 12px; letter-spacing: .08em; text-transform: uppercase; color: var(--lucht); font-weight: 700; }
.st-stripe { height: 4px; background: var(--koraal); }
.st-main { max-width: 860px; margin: -48px auto 0; padding: 0 16px 48px; display: flex; flex-direction: column; gap: 16px; }
.st-main h1 { font-size: 26px; margin: 0; font-stretch: 125%; }
.st-head { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 8px; color: #fff; }
.st-head .muted { color: #C9D3E6; font-size: 13px; }
.st-foot { text-align: center; font-size: 12.5px; color: var(--ink-muted); }
.st-foot a { color: inherit; }
</style>
<header class="st-top">
    <div class="in">
        <img src="{{ asset(\App\Support\Brand::logo()) }}" alt="{{ \App\Support\Brand::name() }}">
        <div style="display:flex;align-items:center;gap:14px"><span class="st-label">{{ __('Status') }}</span>@include('partials.locale-switch')</div>
    </div>
</header>
<div class="st-stripe"></div>
<main class="st-main">
    <div class="st-head"><h1>{{ $title }}</h1>
        <span class="muted">{{ $updated ? __('Checked :time', ['time' => \Illuminate\Support\Carbon::createFromTimestamp($updated)->diffForHumans()]) : '' }}</span></div>

    <div class="mo-banner {{ $overall }}" role="status">
        <span class="mo-pulse" aria-hidden="true"></span>
        <strong>{{ match ($overall) { 'critical' => __('We have an outage'), 'warning' => __('Some services are partly unavailable'), default => __('All systems are working') } }}</strong>
    </div>

    @include('monitoring::partials.platform')

    @if ($maintenance)
        <x-ui.card :title="__('Planned maintenance')">
            <p class="small muted" style="margin:0">{{ __('Updates are installed in the maintenance window, :start to :end. Sometimes websites are unavailable for a few minutes then.', ['start' => $maintenance['start'], 'end' => $maintenance['end']]) }}
                @if ($maintenance['next']) {{ __('Next window: :time.', ['time' => $maintenance['next']->isoFormat('dddd D MMMM HH:mm')]) }}@endif</p>
        </x-ui.card>
    @endif

    <x-ui.card :title="__('Past 14 days')" flush>
        @if ($incidents->isEmpty())
            <x-ui.empty icon="check">{{ __('No interruptions in the past 14 days.') }}</x-ui.empty>
        @else
            <ul class="mo-incidents">
                @foreach ($incidents as $i)
                    <li><span class="mo-dot {{ $i->resolved_at ? '' : $i->severity }}" aria-hidden="true"></span>
                        <strong>{{ \Rhis\Monitoring\Components::all()[$i->component] ?? '' }}: {{ $i->resolved_at ? __('interruption') : __('ongoing interruption') }}</strong>
                        @if ($i->resolved_at)<x-ui.pill status="ok">{{ __('Solved') }}</x-ui.pill>@else<x-ui.pill status="bad">{{ __('Ongoing') }}</x-ui.pill>@endif
                        <span class="meta">{{ $i->started_at->timezone(config('app.timezone'))->isoFormat('D MMM HH:mm') }} · {{ $i->duration() }}</span></li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
    <p class="st-foot">{{ config('app.name') }} · <a href="{{ url('/') }}">{{ __('Log in to the control panel') }}</a></p>
</main>
</x-layouts.base>
