<?php

declare(strict_types=1);

namespace Rhis\ServerAdmin;

use App\Models\Website;
use App\Services\Agent\AgentClient;
use Rhis\ServerAdmin\Models\ServerTaskRun;

/** Checks that every website answers and every service runs (before and after maintenance). */
final class HealthCheck
{
    public function __construct(private readonly AgentClient $agent, private readonly Options $options, private readonly Notifier $notifier) {}

    /** @param iterable<Website>|null $websites */
    public function run(?iterable $websites = null): array
    {
        $websites ??= Website::where('status', 'active')->get(['id', 'domain', 'ssl_status']);
        $sites = [];
        foreach ($websites as $w) {
            $sites[] = ['domain' => $w->domain, 'ssl' => $w->ssl_status === 'active'];
        }

        return $this->agent->call('server.health', ['sites' => $sites], 900);
    }

    public function site(Website $website): array
    {
        $res = $this->run([$website]);

        return $res['sites'][0] ?? ['domain' => $website->domain, 'ok' => false, 'code' => 0, 'error' => 'no result'];
    }

    /** What is down now but was fine before. */
    public static function regressions(?array $before, array $after): array
    {
        $beforeSites = $before['sites_down'] ?? [];
        $beforeServices = $before['services_down'] ?? [];

        return [
            'sites' => array_values(array_diff($after['sites_down'] ?? [], $beforeSites)),
            'services' => array_values(array_diff($after['services_down'] ?? [], $beforeServices)),
        ];
    }

    public static function line(array $health): string
    {
        $sites = is_array($health['sites'] ?? null) ? count($health['sites']) : (int) ($health['sites'] ?? 0);
        $down = count($health['sites_down'] ?? []);
        $svc = count($health['services_down'] ?? []);

        return __(':ok of :n websites answer, :svc services down', ['ok' => $sites - $down, 'n' => $sites, 'svc' => $svc]);
    }

    public static function monitoringAlerts(): bool
    {
        return class_exists(\Rhis\Monitoring\Options::class) && (bool) app(\Rhis\Monitoring\Options::class)->get('enabled');
    }

    /** The hourly task: mail when something new is down, and once more when it is fine again. */
    public function scheduled(ServerTaskRun $run): array
    {
        $health = $this->run();
        $run->appendLog(self::line($health));
        foreach ($health['sites'] ?? [] as $s) {
            if (! $s['ok']) {
                $run->appendLog("{$s['domain']}: HTTP {$s['code']} ".($s['error'] ?? ''));
            }
        }
        $down = ['sites' => $health['sites_down'] ?? [], 'services' => $health['services_down'] ?? []];
        $alerted = $this->options->state('health_alerted', ['sites' => [], 'services' => []]);
        $new = ['sites' => array_values(array_diff($down['sites'], $alerted['sites'] ?? [])),
            'services' => array_values(array_diff($down['services'], $alerted['services'] ?? []))];
        // The monitoring package (rhis/monitoring) checks every few minutes and sends these alerts itself.
        if (self::monitoringAlerts()) {
            $run->appendLog(__('Alerts are sent by Monitoring.'));
        } elseif ($new['sites'] || $new['services']) {
            $this->notifier->problem(__('Websites or services are down'), array_merge(
                array_map(fn ($d) => __('Website :domain does not answer', ['domain' => $d]), $new['sites']),
                array_map(fn ($s) => __('Service :service is not running', ['service' => $s]), $new['services']),
            ));
        } elseif (($alerted['sites'] ?? []) || ($alerted['services'] ?? [])) {
            if (! $down['sites'] && ! $down['services']) {
                $this->notifier->info(__('Everything is working again'), [self::line($health)]);
            }
        }
        $this->options->setState('health_alerted', $down);
        $this->options->setState('last_health', ['at' => now()->getTimestamp()] + $health);

        return [$down['sites'] || $down['services'] ? 'failed' : 'succeeded', self::line($health), ['sites_down' => $down['sites'], 'services_down' => $down['services']]];
    }
}
