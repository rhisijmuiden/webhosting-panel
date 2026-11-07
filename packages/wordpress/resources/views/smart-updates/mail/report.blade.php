<x-mail::message>
@php $site = $install?->label(); @endphp
@switch($run->status)
@case('updated')
# {{ __('Your website was updated') }}

{{ __('Tonight :site was updated in the maintenance window. Before and after the update we took screenshots and compared them: the website looks the same, so the update was kept.', ['site' => $site]) }}
@break
@case('rolled_back')
# {{ __('An update was rolled back') }}

{{ __('Tonight we tried to update :site, but :reason. We put the website back the way it was before the update, so visitors noticed nothing.', ['site' => $site, 'reason' => mb_strtolower((string) $run->reasonLabel())]) }}
@break
@case('notified')
# {{ __('Updates are waiting') }}

{{ __('There are updates for :site. This site is set to "notify only", so nothing was changed.', ['site' => $site]) }}
@break
@default
# {{ __('An update failed') }}

{{ __('The scheduled update of :site could not be finished. The website was not changed or was put back.', ['site' => $site]) }}
@endswitch

@if ($changes)
<x-mail::table>
| {{ __('Part') }} | {{ __('From') }} | {{ __('To') }} |
|:--|:--|:--|
@foreach ($changes as $c)
| {{ $c['name'] }} | {{ $c['from'] ?? '–' }} | {{ $c['to'] ?? '–' }} |
@endforeach
</x-mail::table>
@endif

@if ($run->max_score !== null)
{{ __('Largest visual difference: :score% (the limit is :limit%).', ['score' => number_format((float) $run->max_score, 1), 'limit' => $run->threshold]) }}
@endif
@if ($run->status === 'failed' && $run->error)

<x-mail::panel>
{{ \Illuminate\Support\Str::limit($run->error, 400) }}
</x-mail::panel>
@endif

<x-mail::button :url="$url">
{{ $run->status === 'notified' ? __('View the updates') : __('View screenshots and details') }}
</x-mail::button>

@if ($area === 'client')
{{ __('You can choose per website what is updated automatically and which mails you get.') }} [{{ __('Change settings') }}]({{ $settingsUrl }})
@endif
</x-mail::message>
