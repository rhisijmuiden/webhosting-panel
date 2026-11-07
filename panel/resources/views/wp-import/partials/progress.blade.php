{{-- Steps 2 and 4 while the agent works: the check, or the import itself. The page refreshes by itself. --}}
@php
    $now = $import->jobStep();
    $items = $kind === 'check'
        ? ['website' => __('Check the website zip'), 'database' => __('Read the database export')]
        : ['files' => __('Unpack the website files'), 'database' => __('Make the database and load the export'), 'config' => __('Set up wp-config.php'),
           'replace' => __('Change addresses and paths'), 'check' => __('Check the website')];
    $keys = array_keys($items);
    $at = $kind === 'check' ? ($now === 'database' ? 1 : 0) : max(0, (int) array_search($now ?? 'files', $keys, true));
@endphp
<x-ui.card :title="$kind === 'check' ? __('Checking the files') : __('Importing :domain', ['domain' => $import->domain])" class="wpi-progress-card">
    <ol class="wpi-progress">
        @foreach ($items as $key => $label)
            @php $i = array_search($key, $keys, true); @endphp
            <li @class(['is-done' => $i < $at, 'is-now' => $i === $at])><span class="wpi-dot" aria-hidden="true"></span>{{ $label }}</li>
        @endforeach
    </ol>
    <p class="muted small">{{ $kind === 'check' ? __('This takes a moment for a big database. This page refreshes by itself.') : __('This page refreshes by itself. You can leave it: the import continues.') }}</p>
    @if ($area === 'admin' && ! empty($import->log))<div class="log">{{ implode("\n", $import->log) }}</div>@endif
</x-ui.card>
