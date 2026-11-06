@php
    $sev = [
        'critical' => ['#C23434', '#FDECEC', __('Critical')],
        'high' => ['#C2410C', '#FFF1E8', __('High')],
        'medium' => ['#A86B00', '#FFF6E0', __('Medium')],
        'low' => ['#1F5FBF', '#EAF2FF', __('Low')],
        'unknown' => ['#4F5B73', '#EEF1F6', __('Unknown')],
    ];
    $font = "font-family:Archivo,'Segoe UI',Helvetica,Arial,sans-serif;";
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light only">
<title>{{ __('Security alert') }}</title>
</head>
<body style="margin:0;padding:0;background:#F6F7FB;{{ $font }}color:#14213D;-webkit-text-size-adjust:100%">
<div style="display:none;max-height:0;overflow:hidden;opacity:0">{{ trans_choice('We found :count security problem on your website that needs your attention.|We found :count security problems on your websites that need your attention.', $count) }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F6F7FB">
<tr><td align="center" style="padding:24px 12px">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px">

    {{-- Header --}}
    <tr><td style="background:#14213D;border-radius:16px 16px 0 0;padding:28px 32px 24px">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
            <td><img src="{{ isset($message) ? $message->embed($logo) : asset('brand/rhis-logo-wit.svg') }}" width="96" height="34" alt="RHIS" style="display:block;border:0"></td>
            <td align="right" style="{{ $font }}font-size:12px;color:#9CC3FF;letter-spacing:.08em;text-transform:uppercase">{{ __('Security alert') }}</td>
        </tr></table>
    </td></tr>
    <tr><td style="height:4px;background:#FF6B57;font-size:0;line-height:0">&nbsp;</td></tr>

    {{-- Intro --}}
    <tr><td style="background:#FFFFFF;padding:32px 32px 8px">
        <p style="margin:0 0 12px">
            @if ($malware)
                <span style="display:inline-block;padding:4px 12px;border-radius:999px;background:#FDECEC;color:#C23434;font-size:13px;font-weight:700">{{ __('Malware found') }}</span>
            @else
                <span style="display:inline-block;padding:4px 12px;border-radius:999px;background:{{ $sev[$worst][1] }};color:{{ $sev[$worst][0] }};font-size:13px;font-weight:700">{{ __('Risk: :level', ['level' => $sev[$worst][2]]) }}</span>
            @endif
        </p>
        <h1 style="margin:0 0 16px;{{ $font }}font-size:26px;line-height:1.25;font-weight:800;font-stretch:125%;color:#14213D">
            {{ $reminder ? __('Your website still needs an update') : trans_choice('We found a security problem on your website|We found :count security problems on your websites', $count) }}
        </h1>
        <p style="margin:0 0 12px;font-size:16px;line-height:1.6;color:#14213D">{{ __('Hello :name,', ['name' => $customer->name]) }}</p>
        <p style="margin:0 0 12px;font-size:16px;line-height:1.6;color:#4F5B73">
            @if ($reminder)
                {{ __('We wrote to you earlier about the problems below. They are still there, so your website can still be attacked. Please fix them as soon as possible.') }}
            @else
                {{ __('Our server checks your websites every night against the latest list of known security holes. We found the problems below. Hackers scan the internet for exactly these weaknesses, so please fix them as soon as possible.') }}
            @endif
        </p>
        <p style="margin:0 0 8px;font-size:14px;line-height:1.6;color:#4F5B73">{{ __('We do not change anything on your website ourselves: you stay in control. Each problem below says how to fix it.') }}</p>
    </td></tr>

    {{-- Findings per site --}}
    @foreach ($groups as $site => $list)
    <tr><td style="background:#FFFFFF;padding:16px 32px 0">
        <p style="margin:8px 0 12px;{{ $font }}font-size:13px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#4F5B73">{{ $site }}</p>
        @foreach ($list as $f)
            @php [$fg, $bg, $label] = $f->isMalware() ? ['#C23434', '#FDECEC', __('Malware')] : $sev[$f->severity]; @endphp
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 14px;border:1px solid #DCE1EB;border-left:4px solid {{ $fg }};border-radius:10px">
            <tr><td style="padding:16px 18px">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
                    <td style="{{ $font }}font-size:16px;font-weight:700;color:#14213D;padding-bottom:4px">{{ $f->isMalware() ? __('Suspicious file') : $f->componentLabel() }}</td>
                    <td align="right" valign="top" style="white-space:nowrap"><span style="display:inline-block;padding:3px 10px;border-radius:999px;background:{{ $bg }};color:{{ $fg }};font-size:12px;font-weight:700">{{ $label }}@if ($f->cvss_score) · {{ number_format($f->cvss_score, 1) }}@endif</span></td>
                </tr></table>
                @if ($f->isMalware())
                    <p style="margin:0 0 10px;font-size:14px;line-height:1.5;color:#4F5B73;word-break:break-all"><code style="font-family:Consolas,Menlo,monospace;font-size:13px;color:#14213D">{{ $f->file_path }}</code><br>{{ __('Detected as :signature', ['signature' => $f->title]) }}</p>
                @else
                    <p style="margin:0 0 10px;font-size:14px;line-height:1.5;color:#4F5B73">{{ $f->title }}@if ($f->component_status === 'inactive') <em>({{ __('this plugin is switched off, but its files can still be attacked') }})</em>@endif</p>
                @endif
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F6F7FB;border-radius:8px"><tr><td style="padding:12px 14px">
                    <p style="margin:0 0 4px;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#1C8A57">{{ __('How to fix it') }}</p>
                    <p style="margin:0;font-size:14px;line-height:1.5;color:#14213D">{{ $f->advice() }}</p>
                </td></tr></table>
                <p style="margin:12px 0 0;font-size:13px;line-height:1.5">
                    <a href="{{ $fixUrl($f) }}" style="color:#B8392A;font-weight:700;text-decoration:none">{{ $f->isMalware() ? __('View in the control panel') : __('Update now in the control panel') }} &rarr;</a>
                    @if ($f->cve)<span style="color:#4F5B73"> &nbsp;·&nbsp; </span><a href="https://www.cve.org/CVERecord?id={{ urlencode($f->cve) }}" style="color:#4F5B73;text-decoration:underline">{{ $f->cve }}</a>@endif
                </p>
            </td></tr>
            </table>
        @endforeach
    </td></tr>
    @endforeach

    {{-- CTA --}}
    <tr><td style="background:#FFFFFF;padding:16px 32px 32px" align="center">
        <table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="border-radius:10px;background:#FF6B57">
            <a href="{{ $panelUrl }}" style="display:inline-block;padding:14px 28px;{{ $font }}font-size:16px;font-weight:700;color:#14213D;text-decoration:none">{{ __('Open the control panel') }}</a>
        </td></tr></table>
        <p style="margin:16px 0 0;font-size:13px;line-height:1.6;color:#4F5B73">
            {{ __('In the control panel you update WordPress, plugins and themes with one click. A backup is made first, and if your site breaks the update is undone automatically.') }}
            @if ($supportEmail)<br>{{ __('Would you rather we do it for you, or do you have a question? Mail us at') }} <a href="mailto:{{ $supportEmail }}" style="color:#B8392A">{{ $supportEmail }}</a>.@endif
        </p>
    </td></tr>

    {{-- Footer --}}
    <tr><td style="background:#14213D;border-radius:0 0 16px 16px;padding:24px 32px">
        <p style="margin:0 0 8px;font-size:13px;line-height:1.6;color:#C9D3E6">{{ __('You receive this mail because you host your website with :app. We send it only when we find a security problem.', ['app' => config('app.name')]) }}
            <a href="{{ $settingsUrl }}" style="color:#9CC3FF">{{ __('Change who receives these mails') }}</a></p>
        @if ($copyrights->isNotEmpty())
            <p style="margin:8px 0 0;font-size:11px;line-height:1.5;color:#8C99B3">{{ __('Vulnerability data:') }} {{ $copyrights->implode(' ') }}</p>
        @endif
    </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
