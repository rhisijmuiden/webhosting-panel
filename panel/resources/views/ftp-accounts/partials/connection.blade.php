{{-- How to connect: server, ports and the SFTP host key. $manager --}}
@php $ftpStatus = $manager->status(); $ftpHost = $manager->host(); @endphp
<dl class="kv kv-tight ftp-connection">
    <dt>{{ __('Server') }}</dt><dd class="mono">{{ $ftpHost }}</dd>
    <dt>SFTP</dt><dd><span class="mono">{{ __('port :port', ['port' => $ftpStatus['sftp_port']]) }}</span> <span class="muted small">{{ __('recommended') }}</span></dd>
    <dt>FTPS</dt><dd><span class="mono">{{ __('port :port', ['port' => $ftpStatus['ftp_port']]) }}</span> <span class="muted small">{{ __('explicit TLS ("FTP over TLS"); plain FTP is refused') }}</span></dd>
    <dt>{{ __('User name') }}</dt><dd class="small">{{ __('The full login, for example :example', ['example' => 'jan@'.($exampleDomain ?? 'example.com')]) }}</dd>
    @foreach ($ftpStatus['fingerprints'] ?? [] as $fp)
        <dt>{{ __('SFTP key (:type)', ['type' => $fp['type']]) }}</dt><dd class="mono small ftp-fp">{{ $fp['sha256'] }}</dd>
    @endforeach
</dl>
@if (! ($ftpStatus['active'] ?? false))
    <p class="small muted">
        <x-ui.pill status="warn">{{ ($ftpStatus['unreachable'] ?? false) ? __('Status unknown') : __('FTP server not running') }}</x-ui.pill>
        {{ __('Accounts are kept; they work as soon as the server runs the FTP service (it is installed with the next panel update).') }}
    </p>
@endif
