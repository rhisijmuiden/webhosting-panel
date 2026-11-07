<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light only">
<title>{{ __('Your hosting in :month', ['month' => $monthName]) }}</title>
</head>
<body style="margin:0;padding:0;background:#F6F9FC;font-family:Lexend,'Segoe UI',Helvetica,Arial,sans-serif;color:#0A2348;-webkit-text-size-adjust:100%">
<div style="display:none;max-height:0;overflow:hidden;opacity:0">{{ __('Visitors, uptime, backups and security of your websites in :month.', ['month' => $monthName]) }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F6F9FC">
<tr><td align="center" style="padding:24px 12px">
@include('reports::partials.body')
</td></tr>
</table>
</body>
</html>
