@if ($reply)
    @switch($reply->state())
        @case('active')<x-ui.pill status="info">{{ __('Autoreply on') }}</x-ui.pill>@break
        @case('scheduled')<x-ui.pill status="pending">{{ __('Autoreply from :date', ['date' => $reply->starts_on->translatedFormat('j M')]) }}</x-ui.pill>@break
    @endswitch
@endif
