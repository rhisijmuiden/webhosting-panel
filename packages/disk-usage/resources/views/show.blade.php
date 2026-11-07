@php
    use Illuminate\Support\Number;
    use Rhis\DiskUsage\DiskUsage;
    $size = fn ($b) => Number::fileSize((int) $b, 1);
    $node = $scan['node'] ?? null;
    $dbTotal = $databases !== null ? array_sum(array_column($databases, 'bytes')) : 0;
    $mailTotal = $mailboxes !== null ? array_sum(array_column($mailboxes, 'bytes')) : 0;
    $scanning = (bool) ($scan['scanning'] ?? false);
    $percent = $subscription['percent'] ?? null;
    $tone = $percent === null ? 'ok' : ($percent >= 95 ? 'bad' : ($percent >= (int) config('disk-usage.warn_percent', 85) ? 'warn' : 'ok'));
    $here = fn (string $p) => route($area.'.disk-usage.show', array_filter([$website, 'path' => $p]));
    $tones = ['info' => 'info', 'ok' => 'ok', 'warn' => 'warn', 'neutral' => 'neutral'];
@endphp
<x-dynamic-component :component="'layouts.'.$area" :title="__('Disk usage').' · '.$website->domain">
    @include('disk-usage::partials.styles')
    <div class="du">
    <div class="page-head">
        <div>
            <div class="eyebrow"><a href="{{ route($area.'.disk-usage.index') }}">{{ __('Disk usage') }}</a></div>
            <h1>{{ $website->domain }}</h1>
            <div class="sub">
                @if ($scanning)
                    <span class="du-scanning">{{ __('Scanning the files of this website…') }}</span>
                @elseif ($scan['scanned_at'] ?? null)
                    {{ __('Files scanned :ago', ['ago' => \Illuminate\Support\Carbon::createFromTimestamp($scan['scanned_at'])->diffForHumans()]) }}
                @else
                    {{ __('Which folders, databases and mailboxes take up the space of this website.') }}
                @endif
            </div>
        </div>
        <div class="actions">
            @if ($fileManager)
                <x-ui.button icon="code" :href="$fileManager($path)">{{ __('Open in file manager') }}</x-ui.button>
            @endif
            <form method="POST" action="{{ route($area.'.disk-usage.scan', $website) }}">
                @csrf
                <input type="hidden" name="path" value="{{ $path }}">
                <x-ui.button variant="primary" icon="refresh" :disabled="$scanning">{{ __('Scan again') }}</x-ui.button>
            </form>
        </div>
    </div>

    @if (isset($scan['error']))
        <div class="alert alert-bad" role="alert"><x-icon name="info" /><div><strong>{{ __('The server agent is not reachable.') }}</strong><p class="small">{{ $scan['error'] }}</p></div></div>
    @endif
    @if ($scan['partial'] ?? false)
        <div class="alert alert-warn" role="status"><x-icon name="info" />{{ __('This website has so many files that the scan stopped early. The numbers below are lower than the real use.') }}</div>
    @endif
    @if ($website->isSubdomain())
        <div class="alert alert-info" role="status"><x-icon name="info" />{{ __('This is a subdomain of :domain. Its files count towards the disk space of :domain.', ['domain' => $main->domain]) }}</div>
    @endif

    <section class="bento" style="margin-top: 20px" aria-label="{{ __('Totals') }}">
        <div class="card span-8">
            <span class="kpi-label">{{ __('Where the space goes') }}</span>
            <div class="du-total" style="margin: 6px 0 14px">{{ $size($filesBytes + $dbTotal + $mailTotal) }}<small>{{ __('in total') }}</small></div>
            @include('disk-usage::partials.stack', ['files' => $filesBytes, 'databases' => $dbTotal, 'mail' => $mailTotal])
            <ul class="du-legend">
                <li><i class="du-files"></i>{{ __('Files') }} <strong>{{ $size($filesBytes) }}</strong></li>
                @if ($databases !== null)<li><i class="du-db"></i>{{ __('Databases') }} <strong>{{ $size($dbTotal) }}</strong></li>@endif
                @if ($mailboxes !== null)<li><i class="du-mail"></i>{{ __('Email') }} <strong>{{ $size($mailTotal) }}</strong></li>@endif
            </ul>
        </div>
        <div class="card span-4">
            <span class="kpi-label">{{ __('Disk space') }}</span>
            <div class="du-quota" style="margin-top: 10px">
                @if ($percent !== null)
                    <x-ui.ring :value="$percent" :tone="$tone" :size="112"><span class="du-ring-label">{{ round($percent) }}%</span></x-ui.ring>
                @endif
                <div>
                    <p class="du-total" style="font-size: 22px">{{ $size($subscription['files'] ?? $filesBytes) }}</p>
                    <p class="muted small">
                        @if ($subscription['quota_bytes'] ?? null)
                            {{ __('of :total for files', ['total' => $size($subscription['quota_bytes'])]) }}
                        @else
                            {{ __('No disk limit') }}
                        @endif
                    </p>
                    @if ($percent !== null && $tone !== 'ok')
                        <p style="margin-top: 6px"><x-ui.pill :status="$tone">{{ $tone === 'bad' ? __('Full') : __('Nearly full') }}</x-ui.pill></p>
                    @endif
                </div>
            </div>
            <p class="du-note">
                @if (($subscription['subdomains'] ?? 0) > 0)
                    {{ __('Counts the files of :domain and its subdomains.', ['domain' => $main->domain]) }}
                @endif
                {{ __('Databases and mailboxes have their own limits.') }}
            </p>
        </div>
    </section>

    <div class="du-grid" style="margin-top: 16px">
        <x-ui.card flush :title="__('Folders')">
            <nav class="du-crumbs" aria-label="{{ __('Folder') }}">
                @if ($crumbs)
                    <a href="{{ $here('') }}">{{ $website->domain }}</a>
                    @foreach ($crumbs as $crumb)
                        <span class="sep" aria-hidden="true">/</span>
                        @if ($loop->last)<span aria-current="page">{{ $crumb['name'] }}</span>@else<a href="{{ $here($crumb['path']) }}">{{ $crumb['name'] }}</a>@endif
                    @endforeach
                @else
                    <span aria-current="page">{{ $website->domain }}</span>
                @endif
            </nav>
            @if ($node === null)
                <div class="du-empty-row">
                    @if ($scanning)
                        <span class="du-scanning">{{ __('The first scan is running. This takes from a few seconds to a few minutes.') }}</span>
                    @else
                        {{ __('No scan yet.') }}
                    @endif
                </div>
            @else
                @if (! ($scan['found'] ?? true))
                    <div class="alert alert-info" role="status" style="margin: 0 20px 12px"><x-icon name="info" />{{ __('That folder is not in the last scan. It may be new, small or removed.') }}</div>
                @endif
                @php $max = max(1, $node['bytes']); @endphp
                <ul class="du-rows">
                    @foreach ($node['children'] as $child)
                        @php
                            $childPath = ltrim($node['path'].'/'.$child['name'], '/');
                            $hint = DiskUsage::folderHint($child['name'], $childPath);
                            $pct = $child['bytes'] / $max * 100;
                            $link = $child['has_children'] && ! $child['truncated'];
                        @endphp
                        <li class="du-row {{ $link ? 'is-link' : '' }}">
                            <div class="du-name">
                                <span class="du-ico is-folder">@include('disk-usage::partials.icon', ['name' => 'folder'])</span>
                                @if ($link)
                                    <a class="du-stretch" href="{{ $here($childPath) }}">{{ $child['name'] }}</a>
                                @elseif ($fileManager)
                                    <a href="{{ $fileManager($childPath) }}" title="{{ __('Open in file manager') }}">{{ $child['name'] }}</a>
                                @else
                                    <span>{{ $child['name'] }}</span>
                                @endif
                            </div>
                            <div class="du-bar"><span style="width: {{ round($pct, 2) }}%"></span></div>
                            <div class="du-size">{{ $size($child['bytes']) }}</div>
                            <div class="du-pct">{{ $pct >= 1 ? round($pct) : '<1' }}%</div>
                            @if ($hint || $child['files'])
                                <div class="du-hint">
                                    <span>{{ trans_choice(':count file|:count files', $child['files'], ['count' => Number::format($child['files'])]) }}</span>
                                    @if ($hint)<x-ui.pill :status="$tones[$hint[1]]" :dot="false">{{ __($hint[0]) }}</x-ui.pill>@endif
                                </div>
                            @endif
                        </li>
                    @endforeach
                    @if ($node['other']['count'] > 0)
                        <li class="du-row">
                            <div class="du-name"><span class="du-ico is-folder">@include('disk-usage::partials.icon', ['name' => 'folder'])</span><span class="muted">{{ trans_choice(':count smaller folder|:count smaller folders', $node['other']['count'], ['count' => $node['other']['count']]) }}</span></div>
                            <div class="du-bar"><span style="width: {{ round($node['other']['bytes'] / $max * 100, 2) }}%"></span></div>
                            <div class="du-size">{{ $size($node['other']['bytes']) }}</div>
                            <div class="du-pct">{{ round($node['other']['bytes'] / $max * 100) }}%</div>
                        </li>
                    @endif
                    @if ($node['direct_files'] > 0)
                        <li class="du-row">
                            <div class="du-name"><span class="du-ico">@include('disk-usage::partials.icon', ['name' => 'file'])</span><span>{{ trans_choice(':count file in this folder|:count files in this folder', $node['direct_files'], ['count' => Number::format($node['direct_files'])]) }}</span></div>
                            <div class="du-bar"><span style="width: {{ round($node['direct_bytes'] / $max * 100, 2) }}%; opacity: .55"></span></div>
                            <div class="du-size">{{ $size($node['direct_bytes']) }}</div>
                            <div class="du-pct">{{ round($node['direct_bytes'] / $max * 100) }}%</div>
                        </li>
                    @endif
                    @if ($node['children'] === [] && $node['direct_files'] === 0)
                        <li class="du-empty-row">{{ $node['truncated'] ? __('This folder is too deep to show here. Open it in the file manager.') : __('This folder is empty.') }}</li>
                    @endif
                </ul>
                @if ($node['top_files'] !== [] && $node['path'] !== '')
                    <div class="du-sub">{{ __('Largest files in this folder') }}</div>
                    <ul class="du-rows">
                        @foreach ($node['top_files'] as $file)
                            <li class="du-row">
                                <div class="du-name"><span class="du-ico">@include('disk-usage::partials.icon', ['name' => DiskUsage::isArchive($file['name']) ? 'archive' : 'file'])</span><span>{{ $file['name'] }}</span></div>
                                <div class="du-bar"><span style="width: {{ round($file['bytes'] / $max * 100, 2) }}%; opacity: .55"></span></div>
                                <div class="du-size">{{ $size($file['bytes']) }}</div>
                                <div class="du-pct du-hide-sm">{{ \Illuminate\Support\Carbon::createFromTimestamp($file['mtime'])->isoFormat('ll') }}</div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endif
        </x-ui.card>

        <div class="du-side">
            @if (! empty($scan['largest']))
                <x-ui.card flush :title="__('Largest files')">
                    <ul class="du-rows">
                        @foreach (array_slice($scan['largest'], 0, 10) as $file)
                            @php $dir = str_contains($file['path'], '/') ? substr($file['path'], 0, strrpos($file['path'], '/')) : ''; @endphp
                            <li class="du-row" style="grid-template-columns: minmax(0, 1fr) auto">
                                <div class="du-name">
                                    <span class="du-ico">@include('disk-usage::partials.icon', ['name' => DiskUsage::isArchive($file['path']) ? 'archive' : 'file'])</span>
                                    <span style="display: flex; flex-direction: column; min-width: 0">
                                        <span>{{ basename($file['path']) }}</span>
                                        <a class="muted small" href="{{ $here($dir) }}">/{{ $dir }}</a>
                                    </span>
                                </div>
                                <div class="du-size">{{ $size($file['bytes']) }}</div>
                                @if (DiskUsage::isArchive($file['path']))
                                    <div class="du-hint"><x-ui.pill status="warn" :dot="false">{{ __('Archive or export: an old backup?') }}</x-ui.pill><span>{{ \Illuminate\Support\Carbon::createFromTimestamp($file['mtime'])->isoFormat('ll') }}</span></div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif

            @if ($databases !== null)
                <x-ui.card flush :title="__('Databases')">
                    @if ($databases === [])
                        <div class="du-empty-row">{{ __('This website has no databases.') }}</div>
                    @else
                        @php $dbMax = max(1, $databases[0]['bytes']); @endphp
                        <ul class="du-rows">
                            @foreach ($databases as $db)
                                <li class="du-row is-stack">
                                    <div class="du-name"><span class="du-ico is-db">@include('disk-usage::partials.icon', ['name' => 'db'])</span><span class="mono">{{ $db['name'] }}</span></div>
                                    <div class="du-bar is-db"><span style="width: {{ round($db['bytes'] / $dbMax * 100, 2) }}%"></span></div>
                                    <div class="du-size">{{ $size($db['bytes']) }}</div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.card>
            @endif

            @if ($mailboxes !== null)
                <x-ui.card flush :title="__('Mailboxes')">
                    @if ($mailboxes === [])
                        <div class="du-empty-row">{{ __('No mailboxes on this domain.') }}</div>
                    @else
                        <ul class="du-rows">
                            @foreach ($mailboxes as $box)
                                @php $p = $box['percent']; @endphp
                                <li class="du-row is-stack">
                                    <div class="du-name"><span class="du-ico is-mail">@include('disk-usage::partials.icon', ['name' => 'mail'])</span><span>{{ $box['address'] }}</span></div>
                                    <div class="du-bar is-mail" title="{{ $p !== null ? $p.'%' : __('No limit') }}"><span class="{{ $p !== null && $p >= 95 ? 'bad' : ($p !== null && $p >= 85 ? 'warn' : '') }}" style="width: {{ $p ?? 0 }}%"></span></div>
                                    <div class="du-size">{{ $size($box['bytes']) }}<div class="muted small" style="font-weight: 400">{{ $box['quota_mb'] ? __('of :total', ['total' => $size($box['quota_mb'] * 1024 * 1024)]) : __('No limit') }}</div></div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.card>
            @endif

            @if ($subdomains->isNotEmpty())
                <x-ui.card flush :title="__('Subdomains')">
                    <ul class="du-rows">
                        @foreach ($subdomains as $sub)
                            <li class="du-row is-link" style="grid-template-columns: minmax(0, 1fr) auto">
                                <div class="du-name"><span class="du-ico">@include('disk-usage::partials.icon', ['name' => 'globe'])</span><a class="du-stretch" href="{{ route($area.'.disk-usage.show', $sub['website']) }}">{{ $sub['website']->domain }}</a></div>
                                <div class="du-size">{{ $size($sub['bytes']) }}</div>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif
        </div>
    </div>
    </div>

    @if ($scanning)
        <div hidden x-data x-init="setTimeout(() => location.reload(), 5000)"></div>
    @endif
</x-dynamic-component>
