<?php

declare(strict_types=1);

namespace Rhis\ServerAdmin;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\WebsiteManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Rhis\ServerAdmin\Mail\PhpVersionNotice;
use Rhis\ServerAdmin\Models\ServerTaskRun;
use Throwable;

/**
 * PHP versions that lose security support: warn the customers beforehand,
 * move their websites to the next supported version (checking every site
 * before and after, and going back when a site breaks), install that next
 * version when the server does not have it yet, and remove the old version
 * once no website uses it any more.
 */
final class PhpLifecycle
{
    public function __construct(
        private readonly AgentClient $agent,
        private readonly Options $options,
        private readonly HealthCheck $health,
        private readonly Notifier $notifier,
        private readonly WebsiteManager $websites,
    ) {}

    public function eol(string $version): ?Carbon
    {
        $date = config("server-admin.php_eol.$version");

        return $date ? Carbon::parse($date, $this->options->timezone())->endOfDay() : null;
    }

    public function migrateAt(string $version): ?Carbon
    {
        return $this->eol($version)?->copy()->addDays(max(0, (int) $this->options->get('php_grace_days')));
    }

    public function facts(): array
    {
        try {
            return $this->agent->call('server.php_versions', [], 30);
        } catch (AgentException) {
            return ['supported' => [], 'installed' => [], 'configured' => [], 'panel' => '8.4', 'sites' => []];
        }
    }

    /** The version websites on $version move to: the lowest installed newer version that is still supported. */
    public function target(string $version, array $installed): ?string
    {
        $now = $this->options->now();
        $newer = array_filter($installed, fn ($v) => version_compare($v, $version, '>') && ($this->eol($v)?->isAfter($now) ?? true));
        usort($newer, 'version_compare');

        return $newer[0] ?? null;
    }

    /** Rows for the screen: every installed or used version with its end of life and what happens. */
    public function overview(?array $facts = null): array
    {
        $facts ??= $this->facts();
        $used = Website::selectRaw('php_version, count(*) as total')->groupBy('php_version')->pluck('total', 'php_version')->all();
        $versions = array_unique(array_merge($facts['installed'] ?? [], array_keys($used)));
        usort($versions, 'version_compare');
        $now = $this->options->now();
        $notice = (int) $this->options->get('php_notice_days');
        $rows = [];
        foreach ($versions as $v) {
            $eol = $this->eol($v);
            $migrate = $this->migrateAt($v);
            $days = $eol ? (int) floor($now->diffInDays($eol, false)) : null;
            $rows[] = [
                'version' => $v,
                'installed' => in_array($v, $facts['installed'] ?? [], true),
                'panel' => $v === ($facts['panel'] ?? null),
                'sites' => (int) ($used[$v] ?? 0),
                'eol' => $eol,
                'migrate_at' => $migrate,
                'days_left' => $days,
                'state' => $eol === null ? 'unknown' : ($eol->isPast() ? 'eol' : ($days <= max($notice, 90) ? 'ending' : 'supported')),
                'target' => $this->target($v, $facts['installed'] ?? []),
            ];
        }

        return $rows;
    }

    public function run(ServerTaskRun $run, ?string $only = null): array
    {
        if (! $this->options->get('php_eol_enabled') && $only === null) {
            $run->appendLog(__('Turned off in the settings'));

            return ['succeeded', __('Turned off in the settings')];
        }
        $facts = $this->facts();
        $installed = $facts['installed'] ?? [];
        $now = $this->options->now();
        $moved = $failed = $notified = [];
        $actions = [];

        foreach ($this->overview($facts) as $row) {
            $v = $row['version'];
            if ($only !== null && $v !== $only) {
                continue;
            }
            if (! $row['eol']) {
                continue;
            }
            $target = $row['target'];

            if ($this->options->get('php_notify_customers') && $row['days_left'] !== null && $row['days_left'] >= 0
                && $row['days_left'] <= (int) $this->options->get('php_notice_days') && $row['sites'] > 0) {
                $notified = array_merge($notified, $this->notifyCustomers($v, $target, $row['migrate_at']));
            }

            if ($only === null && $now->lt($row['migrate_at'])) {
                continue;
            }
            if ($row['sites'] > 0 && ! $target) {
                $next = $this->nextSupported($v, $facts['supported'] ?? []);
                $actions[] = $this->ensureInstalled($next, $v, $run);

                continue;
            }
            $sites = Website::where('php_version', $v)->whereIn('status', ['active', 'suspended'])->orderBy('domain')->get();
            $skipped = (array) $this->options->state('php_failed', []);
            foreach ($sites as $site) {
                if (($skipped[$site->domain] ?? null) === $target && $only === null) {
                    continue;
                }
                if ($this->migrateSite($site, $v, $target, $run)) {
                    $moved[] = "{$site->domain}: $v → $target";
                } else {
                    $failed[] = $site->domain;
                }
            }
            if (Website::where('php_version', $v)->count() === 0 && $row['installed'] && ! $row['panel'] && $this->options->get('php_remove_old')) {
                $actions[] = $this->remove($v, $run);
            }
        }

        $actions = array_values(array_filter($actions));
        if ($moved || $failed || $actions) {
            $lines = array_merge($moved, array_map(fn ($d) => __(':domain broke on the new version and stays on the old one', ['domain' => $d]), $failed), $actions);
            $failed ? $this->notifier->problem(__('PHP end of life: not every website could move'), $lines)
                : $this->notifier->info(__('PHP end of life: websites moved'), $lines);
        }
        $summary = __(':moved websites moved, :failed failed, :notified customers warned', ['moved' => count($moved), 'failed' => count($failed), 'notified' => count($notified)]);

        return [$failed ? 'failed' : 'succeeded', $summary, compact('moved', 'failed', 'notified', 'actions')];
    }

    public function migrateSite(Website $site, string $from, string $to, ServerTaskRun $run): bool
    {
        $check = $site->status === 'active';
        try {
            $before = $check ? $this->health->site($site) : null;
            $this->websites->setPhp($site, $to);
            $after = $check ? $this->health->site($site) : null;
        } catch (AgentException $e) {
            $run->appendLog("{$site->domain}: ".$e->getMessage());
            $this->markFailed($site->domain, $to);

            return false;
        }
        if ($check && ($before['ok'] ?? false) && ! ($after['ok'] ?? false)) {
            $run->appendLog("{$site->domain}: HTTP {$before['code']} → {$after['code']} on PHP $to, back to $from");
            try {
                $this->websites->setPhp($site, $from);
            } catch (AgentException $e) {
                $run->appendLog("{$site->domain}: ".$e->getMessage());
            }
            $this->markFailed($site->domain, $to);
            ActivityLog::record('server.php_migration_failed', $site->domain, ['from' => $from, 'to' => $to], $site->customer_id);

            return false;
        }
        $run->appendLog("{$site->domain}: PHP $from → $to".($check ? " (HTTP {$after['code']})" : ''));
        $failed = (array) $this->options->state('php_failed', []);
        unset($failed[$site->domain]);
        $this->options->setState('php_failed', $failed ?: null);

        return true;
    }

    private function markFailed(string $domain, string $target): void
    {
        $failed = (array) $this->options->state('php_failed', []);
        $failed[$domain] = $target;
        $this->options->setState('php_failed', $failed);
    }

    private function nextSupported(string $version, array $supported): ?string
    {
        $now = $this->options->now();
        $newer = array_filter($supported, fn ($v) => version_compare($v, $version, '>') && ($this->eol($v)?->isAfter($now) ?? true));
        usort($newer, 'version_compare');

        return $newer[0] ?? null;
    }

    private function ensureInstalled(?string $version, string $old, ServerTaskRun $run): ?string
    {
        if (! $version) {
            $run->appendLog(__('No newer supported PHP version is available for PHP :v', ['v' => $old]));

            return __('No newer supported PHP version is available for PHP :v', ['v' => $old]);
        }
        if (ServerTaskRun::running()->where('task', 'php_install')->exists() || $this->options->state("php_install_started.$version")) {
            return null;
        }
        $this->options->setState("php_install_started.$version", time());
        app(Runner::class)->startAgent('php_install', 'server.php_install', ['version' => $version], 'eol');
        $run->appendLog(__('Installing PHP :v first', ['v' => $version]));

        return __('PHP :v is being installed; websites on PHP :old move once it is ready', ['v' => $version, 'old' => $old]);
    }

    private function remove(string $version, ServerTaskRun $run): ?string
    {
        if (ServerTaskRun::running()->whereIn('task', ['php_remove', 'php_install', 'upgrade'])->exists()) {
            return null;
        }
        $facts = $this->facts();
        if ((int) ($facts['sites'][$version] ?? 0) > 0) {
            $run->appendLog(__('PHP :v is still used by websites the panel does not know', ['v' => $version]));

            return null;
        }
        app(Runner::class)->startAgent('php_remove', 'server.php_remove', ['version' => $version], 'eol');
        $run->appendLog(__('Removing PHP :v', ['v' => $version]));

        return __('PHP :v is removed from the server', ['v' => $version]);
    }

    /** @return list<int> customer ids that got a mail now */
    private function notifyCustomers(string $version, ?string $target, Carbon $at): array
    {
        $sent = (array) $this->options->state("php_notified.$version", []);
        $new = [];
        $byCustomer = Website::where('php_version', $version)->whereIn('status', ['active', 'suspended'])->get()->groupBy('customer_id');
        foreach ($byCustomer as $customerId => $sites) {
            if (in_array($customerId, $sent, true)) {
                continue;
            }
            $customer = Customer::find($customerId);
            if (! $customer?->email) {
                continue;
            }
            try {
                $locale = $customer->locale ?: config('app.locale');
                Mail::to($customer->email)->locale($locale)->send(new PhpVersionNotice($customer, $sites->pluck('domain')->all(), $version, $target, $at));
                $new[] = (int) $customerId;
            } catch (Throwable $e) {
                report($e);
            }
        }
        if ($new) {
            $this->options->setState("php_notified.$version", array_merge($sent, $new));
        }

        return $new;
    }
}
