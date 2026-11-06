<?php

declare(strict_types=1);

namespace Rhis\Vulnerabilities;

use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Carbon;
use Rhis\Vulnerabilities\Models\Finding;
use Rhis\WordPress\Models\WordPressInstall;

/**
 * Turns what the WordPress toolkit knows (versions of core, plugins and themes) and the
 * nightly malware scan into findings. A finding that is no longer seen is resolved.
 */
final class Scanner
{
    public function __construct(private readonly AgentClient $agent, private readonly Options $options) {}

    /** @return array{installs:int, found:int, resolved:int, errors:list<string>} */
    public function scanWebsite(Website $website): array
    {
        $stats = ['installs' => 0, 'found' => 0, 'resolved' => 0, 'errors' => []];
        foreach (WordPressInstall::where('website_id', $website->id)->get() as $install) {
            $install->setRelation('website', $website);
            try {
                $r = $this->scanInstall($install);
                $stats['installs']++;
                $stats['found'] += $r['found'];
                $stats['resolved'] += $r['resolved'];
                array_push($stats['errors'], ...$r['errors']);
            } catch (AgentException $e) {
                $stats['errors'][] = $install->label().': '.$e->getMessage();
            }
        }
        // Findings of installs that are gone.
        $stats['resolved'] += $this->resolve(Finding::open()->where('website_id', $website->id)->where('kind', 'vulnerability')
            ->where(fn ($q) => $q->whereNull('wordpress_install_id')
                ->orWhereNotIn('wordpress_install_id', WordPressInstall::where('website_id', $website->id)->pluck('id'))));
        try {
            $r = $this->scanMalware($website);
            $stats['found'] += $r['found'];
            $stats['resolved'] += $r['resolved'];
        } catch (AgentException $e) {
            $stats['errors'][] = $website->domain.' (malware): '.$e->getMessage();
        }

        return $stats;
    }

    /** @return array{found:int, resolved:int, errors:list<string>} */
    public function scanInstall(WordPressInstall $install): array
    {
        $items = [];
        $meta = [];
        if ($install->version) {
            $items[] = ['type' => 'core', 'slug' => 'wordpress', 'version' => $install->version];
            $meta['core:wordpress'] = ['name' => 'WordPress', 'status' => null];
        }
        foreach (['plugin' => $install->plugins ?? [], 'theme' => $install->themes ?? []] as $type => $list) {
            foreach ($list as $p) {
                $slug = strtolower((string) ($p['name'] ?? ''));
                $version = (string) ($p['version'] ?? '');
                if (! preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/', $slug) || ! preg_match('/^[0-9A-Za-z][0-9A-Za-z.+_-]{0,39}$/', $version)) {
                    continue;
                }
                $items[] = ['type' => $type, 'slug' => $slug, 'version' => $version];
                $meta["$type:$slug"] = ['name' => html_entity_decode(strip_tags((string) ($p['title'] ?? $slug))), 'status' => $p['status'] ?? null];
            }
        }
        if ($items === []) {
            return ['found' => 0, 'resolved' => 0, 'errors' => []];
        }

        $res = $this->agent->call('vuln.match', ['source' => $this->options->source(), 'items' => $items], 600);
        $now = now();
        $seen = [];
        $found = 0;
        foreach ($res['matches'] ?? [] as $m) {
            $key = $m['type'].':'.$m['slug'];
            foreach ($m['vulnerabilities'] ?? [] as $v) {
                $fp = hash('sha256', implode('|', ['vuln', $install->id, $m['type'], $m['slug'], $v['id'] ?? $v['title'] ?? '']));
                $seen[] = $fp;
                $finding = Finding::firstOrNew(['website_id' => $install->website_id, 'fingerprint' => $fp]);
                $new = ! $finding->exists || $finding->status === 'resolved';
                $finding->fill([
                    'wordpress_install_id' => $install->id, 'kind' => 'vulnerability',
                    'component_type' => $m['type'], 'component_slug' => $m['slug'],
                    'component_name' => $meta[$key]['name'] ?? ($v['software_name'] ?? $m['slug']),
                    'component_status' => $meta[$key]['status'] ?? null,
                    'installed_version' => $m['version'], 'fixed_version' => $v['fixed_in'] ?? null,
                    'severity' => in_array($v['severity'] ?? '', Finding::SEVERITIES, true) ? $v['severity'] : 'unknown',
                    'cvss_score' => isset($v['cvss_score']) ? round((float) $v['cvss_score'], 1) : null,
                    'title' => mb_substr(html_entity_decode(strip_tags((string) ($v['title'] ?? ''))), 0, 500) ?: $m['slug'],
                    'description' => isset($v['description']) ? mb_substr(strip_tags((string) $v['description']), 0, 4000) : null,
                    'cve' => $v['cve'] ?? null,
                    'references' => array_values(array_filter($v['references'] ?? [], fn ($u) => is_string($u) && preg_match('#^https?://#', $u))),
                    'remediation' => isset($v['remediation']) ? mb_substr(strip_tags((string) $v['remediation']), 0, 2000) : null,
                    'source' => $res['source'] ?? $this->options->source(), 'vuln_id' => $v['id'] ?? null,
                    'copyright' => $v['copyright'] ?? null,
                    'last_seen_at' => $now,
                ]);
                if ($new) {
                    $finding->fill(['status' => 'open', 'first_seen_at' => $now, 'resolved_at' => null, 'notified_at' => null, 'notify_count' => 0]);
                    $found++;
                }
                $finding->save();
            }
        }
        $resolved = $this->resolve(Finding::open()->where('wordpress_install_id', $install->id)->where('kind', 'vulnerability')->whereNotIn('fingerprint', $seen));

        return ['found' => $found, 'resolved' => $resolved, 'errors' => $res['errors'] ?? []];
    }

    /** @return array{found:int, resolved:int} */
    public function scanMalware(Website $website): array
    {
        $report = $this->agent->call('malware.report', ['domain' => $website->domain], 30);
        if (empty($report['scanned_at'])) {
            return ['found' => 0, 'resolved' => 0];
        }
        $now = now();
        $seen = [];
        $found = 0;
        foreach ($report['findings'] ?? [] as $f) {
            $fp = hash('sha256', 'malware|'.($f['file'] ?? '').'|'.($f['signature'] ?? ''));
            $seen[] = $fp;
            $finding = Finding::firstOrNew(['website_id' => $website->id, 'fingerprint' => $fp]);
            $new = ! $finding->exists || $finding->status === 'resolved';
            $finding->fill([
                'kind' => 'malware', 'component_type' => 'file', 'severity' => 'critical',
                'title' => (string) ($f['signature'] ?? 'Malware'), 'file_path' => mb_substr((string) ($f['file'] ?? ''), 0, 1000),
                'source' => (string) ($f['engine'] ?? ''), 'last_seen_at' => $now,
            ]);
            if ($new) {
                $finding->fill(['status' => 'open', 'first_seen_at' => $now, 'resolved_at' => null, 'notified_at' => null, 'notify_count' => 0]);
                $found++;
            }
            $finding->save();
        }
        $resolved = $this->resolve(Finding::open()->where('website_id', $website->id)->where('kind', 'malware')
            ->whereNotIn('fingerprint', $seen)->where('last_seen_at', '<', Carbon::parse($report['scanned_at'])));

        return ['found' => $found, 'resolved' => $resolved];
    }

    private function resolve($query): int
    {
        return $query->update(['status' => 'resolved', 'resolved_at' => now()]);
    }
}
