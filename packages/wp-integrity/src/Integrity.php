<?php

declare(strict_types=1);

namespace Rhis\WpIntegrity;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Collection;
use Rhis\WordPress\Models\WordPressInstall;
use Rhis\WpIntegrity\Models\IntegrityReport;

/** Everything the screens and the nightly check do, and from here to the agent (wpint.*). */
final class Integrity
{
    public function __construct(private readonly AgentClient $agent) {}

    public function for(WordPressInstall $install): IntegrityReport
    {
        $report = IntegrityReport::firstOrNew(['wordpress_install_id' => $install->id]);
        $report->setRelation('install', $install);

        return $report;
    }

    /** Check now and wait for the answer (nightly command). */
    public function check(WordPressInstall $install): IntegrityReport
    {
        $report = $this->for($install);
        $this->store($report, $this->agent->call('wpint.check', $install->target(), 900));

        return $report;
    }

    /** Start a check in the background (screens). */
    public function start(WordPressInstall $install): IntegrityReport
    {
        $report = $this->for($install);
        $job = $this->agent->call('wpint.check', $install->target() + ['background' => true], 30)['job'];
        $report->fill(['job_id' => $job, 'job_kind' => 'check', 'job_started_at' => now()])->save();

        return $report;
    }

    /**
     * Put original files back. $items: [['type' => 'core'|'plugin', 'slug' => ..., 'files' => 'all'|[...], 'quarantine_added' => bool]].
     */
    public function restore(WordPressInstall $install, array $items): IntegrityReport
    {
        $report = $this->for($install);
        $job = $this->agent->call('wpint.restore', $install->target() + ['items' => $items], 30)['job'];
        $report->fill(['job_id' => $job, 'job_kind' => 'restore', 'job_started_at' => now()])->save();

        return $report;
    }

    /**
     * Follow the running job of a report; store the outcome once it is finished.
     *
     * @return array{status: string, log: list<string>, error: ?string}|null
     */
    public function poll(IntegrityReport $report): ?array
    {
        if ($report->job_id === null) {
            return null;
        }
        try {
            $state = $this->agent->call('job.status', ['job' => $report->job_id], 10);
        } catch (AgentException $e) {
            if (! $report->isRunning()) {
                $report->fill(['job_id' => null, 'job_kind' => null])->save();
            }

            return ['status' => 'unknown', 'log' => [], 'error' => $e->getMessage()];
        }
        $status = (string) ($state['status'] ?? 'running');
        $out = ['status' => $status, 'log' => array_slice($state['log'] ?? [], -12), 'error' => $state['error']['message'] ?? null];
        if ($status === 'running') {
            return $out;
        }
        $kind = $report->job_kind;
        $report->fill(['job_id' => null, 'job_kind' => null]);
        $result = $state['result'] ?? [];
        if ($kind === 'restore') {
            $report->last_restore = [
                'at' => now()->toIso8601String(), 'ok' => $status === 'succeeded',
                'restored' => (int) ($result['restored'] ?? 0), 'quarantined' => (int) ($result['quarantined'] ?? 0),
                'quarantine_dir' => $result['quarantine_dir'] ?? null, 'errors' => array_slice($result['errors'] ?? [], 0, 20),
                'error' => $out['error'], 'malware_rescanned' => (bool) ($result['malware_rescanned'] ?? false),
            ];
            $result = $result['report'] ?? [];
            if (($state['result']['malware_rescanned'] ?? false) && class_exists(\Rhis\Vulnerabilities\Scanner::class) && $report->install?->website) {
                try {
                    app(\Rhis\Vulnerabilities\Scanner::class)->scanMalware($report->install->website);
                } catch (\Throwable) {
                    // The nightly security scan picks it up.
                }
            }
        }
        if ($status === 'succeeded' && ! isset($result['checked_at']) && $report->install?->website) {
            // The agent keeps the last report too; ask for it when the job did not return it.
            try {
                $result = $this->agent->call('wpint.report', $report->install->target(), 30);
            } catch (AgentException) {
                $result = [];
            }
        }
        if ($status === 'succeeded' && isset($result['checked_at'])) {
            $this->store($report, $result);
        } else {
            $report->save();
        }

        return $out;
    }

    /** Store a wpint.check answer and publish it to the Security center. */
    public function store(IntegrityReport $report, array $data): void
    {
        $report->report = $data;
        $report->checked_at = isset($data['checked_at']) ? now()->setTimestamp((int) $data['checked_at']) : now();
        $report->recount();
        $report->save();
        $this->publish($report);
    }

    /** Mark files as an intended change (or undo that); accepted files no longer count. */
    public function accept(IntegrityReport $report, array $keys, bool $accept = true): int
    {
        $known = [];
        foreach ($report->report['components'] ?? [] as $c) {
            foreach ($c['files'] ?? [] as $f) {
                if (isset($f['md5']) && empty($f['malware']) && ($f['issue'] ?? '') !== 'missing') {
                    $known[IntegrityReport::acceptKey($c, $f)] = true;
                }
            }
        }
        $keys = array_values(array_filter($keys, fn ($k) => is_string($k) && isset($known[$k])));
        $current = $report->accepted ?? [];
        $report->accepted = array_values($accept ? array_unique([...$current, ...$keys]) : array_diff($current, $keys));
        $report->recount();
        $report->save();
        $this->publish($report);

        return count($keys);
    }

    /**
     * Changed files become findings in the Security center (rhis/vulnerabilities), one per
     * component; a component that is clean again resolves its finding.
     */
    public function publish(IntegrityReport $report): void
    {
        if (! config('wp-integrity.findings') || ! class_exists(\Rhis\Vulnerabilities\Models\Finding::class)) {
            return;
        }
        $finding = \Rhis\Vulnerabilities\Models\Finding::class;
        $install = $report->install ?? $report->install()->with('website')->first();
        if (! $install?->website) {
            return;
        }
        $now = now();
        $seen = [];
        foreach ($report->components() as $c) {
            if ($c['status'] !== 'changed') {
                continue;
            }
            $fp = hash('sha256', implode('|', ['integrity', $install->id, $c['type'], $c['slug']]));
            $seen[] = $fp;
            $files = collect($c['files'])->reject(fn ($f) => $f['accepted'])->values();
            $f = $finding::firstOrNew(['website_id' => $install->website_id, 'fingerprint' => $fp]);
            $new = ! $f->exists || $f->status === 'resolved';
            $f->fill([
                'wordpress_install_id' => $install->id, 'kind' => 'integrity',
                'component_type' => $c['type'], 'component_slug' => $c['slug'], 'component_name' => $c['name'] ?? $c['slug'],
                'component_status' => isset($c['active']) ? ($c['active'] ? 'active' : 'inactive') : null,
                'installed_version' => $c['version'] ?? null, 'fixed_version' => null,
                'severity' => $c['malware_files'] > 0 ? 'critical' : ($c['type'] === 'core' ? 'high' : 'medium'),
                'title' => $c['type'] === 'core' ? 'Changed files in WordPress core' : 'Changed files in this plugin',
                'description' => $files->take(10)->map(fn ($x) => ($c['base'] ? $c['base'].'/' : '').$x['file'])->implode("\n"),
                'file_path' => mb_substr((string) ($files->first()['file'] ?? ''), 0, 1000),
                'source' => 'wordpress.org', 'last_seen_at' => $now,
            ]);
            // An ignored finding stays ignored; only what it says is refreshed.
            if ($new) {
                $f->fill(['status' => 'open', 'first_seen_at' => $now, 'resolved_at' => null, 'notified_at' => null, 'notify_count' => 0]);
            }
            $f->save();
        }
        $finding::open()->where('kind', 'integrity')->where('wordpress_install_id', $install->id)
            ->whereNotIn('fingerprint', $seen)->update(['status' => 'resolved', 'resolved_at' => $now]);
    }

    /** Overview rows: every install with its report (or none yet). */
    public function overview(?int $customerId = null): Collection
    {
        $installs = WordPressInstall::with('website.customer')
            ->when($customerId, fn ($q, $id) => $q->forCustomer($id))->get();
        $reports = IntegrityReport::whereIn('wordpress_install_id', $installs->pluck('id'))->get()->keyBy('wordpress_install_id');

        return $installs->map(function (WordPressInstall $i) use ($reports) {
            $r = $reports->get($i->id);
            $r?->setRelation('install', $i);

            return ['install' => $i, 'report' => $r];
        })->sortBy(fn ($row) => [
            ['changed' => 0, 'error' => 1, 'unknown' => 2, 'ok' => 3][$row['report']?->status ?? 'unknown'] ?? 2,
            -($row['report']?->malware_files ?? 0), $row['install']->label(),
        ])->values();
    }
}
