@php
    $font = "font-family:Lexend,'Segoe UI',Helvetica,Arial,sans-serif;";
    $tone = fn ($it) => match (true) {
        $it['type'] === 'recovery' => ['#1C8A57', '#E7F6EE'],
        $it['severity'] === 'critical' => ['#C23434', '#FDECEC'],
        default => ['#A86B00', '#FFF6E0'],
    };
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light only">
<title>{{ $heading }}</title>
</head>
<body style="margin:0;padding:0;background:#F6F9FC;{{ $font }}color:#0A2348;-webkit-text-size-adjust:100%">
<div style="display:none;max-height:0;overflow:hidden;opacity:0">{{ $intro }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F6F9FC">
<tr><td align="center" style="padding:24px 12px">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px">

    <tr><td style="background:#0A2348;border-radius:16px 16px 0 0;padding:28px 32px 24px">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
            <td><img src="{{ isset($message) ? $message->embed($logo) : asset(\App\Support\Brand::logo()) }}" width="132" height="34" alt="{{ config('panel.brand.name') }}" style="display:block;border:0"></td>
            <td align="right" style="{{ $font }}font-size:12px;color:#8CCBFA;letter-spacing:.08em;text-transform:uppercase">{{ $eyebrow }}</td>
        </tr></table>
    </td></tr>
    <tr><td style="height:4px;background:#3FA9F5;font-size:0;line-height:0">&nbsp;</td></tr>

    <tr><td style="background:#FFFFFF;padding:32px 32px 8px">
        <h1 style="margin:0 0 16px;{{ $font }}font-size:24px;line-height:1.25;font-weight:800;color:#0A2348">{{ $heading }}</h1>
        @if ($greeting)<p style="margin:0 0 12px;font-size:16px;line-height:1.6;color:#0A2348">{{ $greeting }}</p>@endif
        <p style="margin:0 0 8px;font-size:15px;line-height:1.6;color:#4D607C">{{ $intro }}</p>
    </td></tr>

    <tr><td style="background:#FFFFFF;padding:12px 32px 0">
        @foreach ($items as $it)
            @php [$fg, $bg] = $tone($it); @endphp
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 14px;border:1px solid #DFE7F0;border-left:4px solid {{ $fg }};border-radius:10px">
            <tr><td style="padding:16px 18px">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
                    <td style="{{ $font }}font-size:16px;font-weight:700;color:#0A2348;padding-bottom:4px">{{ $it['headline'] }}</td>
                    <td align="right" valign="top" style="white-space:nowrap;padding-left:8px"><span style="display:inline-block;padding:3px 10px;border-radius:999px;background:{{ $bg }};color:{{ $fg }};font-size:12px;font-weight:700">{{ $it['badge'] }}</span></td>
                </tr></table>
                @if ($it['detail'])<p style="margin:0 0 6px;font-size:14px;line-height:1.5;color:#0A2348;font-family:Consolas,Menlo,monospace">{{ $it['detail'] }}</p>@endif
                <p style="margin:0;font-size:13px;line-height:1.5;color:#4D607C">{{ $it['when'] }}</p>
                @if ($it['advice'])
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F6F9FC;border-radius:8px;margin-top:10px"><tr><td style="padding:10px 14px">
                    <p style="margin:0 0 2px;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#1C8A57">{{ __('What to do') }}</p>
                    <p style="margin:0;font-size:14px;line-height:1.5;color:#0A2348">{{ $it['advice'] }}</p>
                </td></tr></table>
                @endif
            </td></tr>
            </table>
        @endforeach
    </td></tr>

    <tr><td style="background:#FFFFFF;padding:16px 32px 32px" align="center">
        <table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="border-radius:10px;background:#3FA9F5">
            <a href="{{ $url }}" style="display:inline-block;padding:14px 28px;{{ $font }}font-size:16px;font-weight:700;color:#0A2348;text-decoration:none">{{ $button }}</a>
        </td></tr></table>
    </td></tr>

    <tr><td style="background:#0A2348;border-radius:0 0 16px 16px;padding:24px 32px">
        <p style="margin:0;font-size:13px;line-height:1.6;color:#C6D5E8">{{ $footer }} <a href="{{ $footerUrl }}" style="color:#8CCBFA">{{ $footerLink }}</a></p>
    </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
