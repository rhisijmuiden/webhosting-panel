{{ $reminder ? __('Your website still needs an update') : trans_choice('We found a security problem on your website|We found :count security problems on your websites', $count) }}

{{ __('Hello :name,', ['name' => $customer->name]) }}

{{ $reminder ? __('We wrote to you earlier about the problems below. They are still there, so your website can still be attacked. Please fix them as soon as possible.') : __('Our server checks your websites every night against the latest list of known security holes. We found the problems below. Hackers scan the internet for exactly these weaknesses, so please fix them as soon as possible.') }}

@foreach ($groups as $site => $list)
== {{ $site }} ==
@foreach ($list as $f)
- [{{ $f->isMalware() ? __('Malware') : __(ucfirst($f->severity)) }}] {{ $f->isMalware() ? $f->file_path : $f->componentLabel() }}
  {{ $f->isMalware() ? __('Detected as :signature', ['signature' => $f->title]) : $f->title }}
  {{ __('How to fix it') }}: {{ $f->advice() }}
  {{ $fixUrl($f) }}
@endforeach

@endforeach
{{ __('Open the control panel') }}: {{ $panelUrl }}
@if ($supportEmail)
{{ __('Would you rather we do it for you, or do you have a question? Mail us at') }} {{ $supportEmail }}
@endif

{{ __('Change who receives these mails') }}: {{ $settingsUrl }}
@if ($copyrights->isNotEmpty())
{{ __('Vulnerability data:') }} {{ $copyrights->implode(' ') }}
@endif
