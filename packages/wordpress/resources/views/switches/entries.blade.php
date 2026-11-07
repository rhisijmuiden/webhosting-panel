{{-- Entries of the debug log, newest first. Also rendered for "refresh automatically". --}}
@php
    $pill = ['error' => 'bad', 'warning' => 'warn', 'notice' => 'info', 'deprecated' => 'neutral', 'other' => 'neutral'];
    $label = ['error' => __('Error'), 'warning' => __('Warning'), 'notice' => __('Notice'), 'deprecated' => __('Deprecated'), 'other' => __('Other')];
@endphp
@if (! ($log['exists'] ?? false))
    <x-ui.empty icon="list">{{ __('There is no debug log yet. It appears as soon as WordPress writes something to it while debug mode is on.') }}</x-ui.empty>
@elseif (empty($log['entries']))
    <x-ui.empty icon="check">{{ ($log['size'] ?? 0) > 0 ? __('Nothing matches these filters.') : __('The debug log is empty.') }}</x-ui.empty>
@else
    <ol class="wps-entries">
        @foreach ($log['entries'] as $e)
            @php [$first, $rest] = array_pad(explode("\n", $e['message'], 2), 2, null); @endphp
            <li class="wps-entry is-{{ $e['level'] }}">
                <div class="wps-entry-head">
                    <x-ui.pill :status="$pill[$e['level']] ?? 'neutral'">{{ $label[$e['level']] ?? $e['level'] }}</x-ui.pill>
                    @if ($e['time'])<time class="mono small muted">{{ $e['time'] }} {{ $e['tz'] }}</time>@endif
                    @if (($e['source'] ?? null) === 'cron')<span class="small muted">{{ __('WP-cron / WP-CLI') }}</span>@endif
                    <button type="button" class="btn btn-sm btn-ghost wps-copy" x-data="{ c: false }" x-on:click="navigator.clipboard.writeText($el.closest('li').querySelector('.wps-msg').innerText); c = true; setTimeout(() => c = false, 1500)">
                        <span x-text="c ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span></button>
                </div>
                <div class="wps-msg">
                    <pre class="mono">{{ $first }}</pre>
                    @if ($rest)<details><summary class="small">{{ trans_choice(':count more line|:count more lines', substr_count($rest, "\n") + 1) }}</summary><pre class="mono">{{ $rest }}</pre></details>@endif
                </div>
            </li>
        @endforeach
    </ol>
    @if (($log['matched'] ?? 0) > count($log['entries']))
        <p class="muted small">{{ __('Showing the newest :shown of :total entries.', ['shown' => count($log['entries']), 'total' => $log['matched']]) }}</p>
    @endif
    @if ($log['truncated'] ?? false)
        <p class="muted small">{{ __('Only the last 2 MB of the log was read.') }}</p>
    @endif
@endif
