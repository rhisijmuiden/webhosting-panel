{{-- One line in the WordPress tab of a website's page: how this install sends its mail. --}}
@php
    $wpmSetting = \Rhis\WordPress\Mail\WpMailSetting::where('wordpress_install_id', $i->id)->first();
    $wpmUrl = $o->url('wordpress.mail.show', $i);
@endphp
@if ($wpmUrl)
    <p class="small" style="margin:10px 0 0"><x-icon name="mail" /> <strong>{{ __('Website mail') }}</strong>:
        @if ($wpmSetting?->enabled){{ __('through this server as :from', ['from' => $wpmSetting->setRelation('install', $i)->sender()]) }}
        @elseif ($wpmSetting?->reason === 'staging'){{ __('held back (staging)') }}
        @else{{ __('not through this server') }}@endif
        · <a href="{{ $wpmUrl }}">{{ __('Settings and test message') }}</a></p>
@endif
