@php
    $kinds = ['sqli' => __('SQL injection'), 'xss' => __('Cross-site scripting'), 'rce' => __('Command injection'), 'lfi' => __('File access'),
              'rfi' => __('Remote file include'), 'injection-php' => __('PHP injection'), 'injection-java' => __('Java injection'), 'protocol' => __('Bad request'),
              'disclosure' => __('Information leak'), 'reputation-scanner' => __('Attack scanner'), 'fixation' => __('Session fixation'), 'generic' => __('Suspicious request'),
              'multipart-header' => __('Bad upload'), 'injection-generic' => __('Code injection')];
    $k = $event->kind();
@endphp
<span class="waf-kind k-{{ $k }}">{{ $kinds[$k] ?? __('Suspicious request') }}</span>
