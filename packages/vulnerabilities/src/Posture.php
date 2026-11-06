<?php

declare(strict_types=1);

namespace Rhis\Vulnerabilities;

use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Facades\Cache;

/** How the server itself is doing: a list of checks and a score out of 100. */
final class Posture
{
    public function __construct(private readonly AgentClient $agent, private readonly Options $options) {}

    public function get(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget('vulnerabilities.posture');
        }

        return Cache::remember('vulnerabilities.posture', 600, fn () => $this->build());
    }

    private function build(): array
    {
        $error = null;
        try {
            $p = $this->agent->call('posture.get', [], 180);
        } catch (AgentException $e) {
            $p = [];
            $error = $e->getMessage();
        }
        try {
            $alerts = $this->agent->call('crowdsec.metrics')['alerts_24h'] ?? null;
            $blocked = count($this->agent->call('crowdsec.decisions')['decisions'] ?? []);
        } catch (AgentException) {
            $alerts = $blocked = null;
        }

        $svc = $p['services'] ?? [];
        $checks = [];
        $add = function (string $key, string $label, string $status, string $detail) use (&$checks) {
            $checks[] = compact('key', 'label', 'status', 'detail');
        };

        if ($p) {
            $sec = $p['updates']['security'] ?? null;
            $total = $p['updates']['total'] ?? null;
            $add('updates', __('Security updates'), $sec === null ? 'warn' : ($sec > 0 ? 'bad' : ($total > 0 ? 'warn' : 'ok')),
                $sec === null ? __('Could not check') : ($sec > 0 ? trans_choice(':count security update waiting|:count security updates waiting', $sec)
                    : ($total > 0 ? trans_choice(':count other update waiting|:count other updates waiting', $total) : __('Everything is up to date'))));
            $add('unattended', __('Automatic security updates'), ($p['updates']['unattended'] ?? false) ? 'ok' : 'warn',
                ($p['updates']['unattended'] ?? false) ? __('On') : __('Off: turn on unattended-upgrades'));
            $add('reboot', __('Restart'), ($p['reboot_required'] ?? false) ? 'warn' : 'ok',
                ($p['reboot_required'] ?? false) ? __('A restart is needed to finish an update (kernel or libraries)') : __('Not needed'));
            $add('crowdsec', __('CrowdSec'), ($svc['crowdsec'] ?? false) && ($svc['crowdsec-firewall-bouncer'] ?? false) ? 'ok' : 'bad',
                ($svc['crowdsec'] ?? false) ? (($svc['crowdsec-firewall-bouncer'] ?? false) ? __('Watching logs and blocking attackers') : __('The firewall bouncer is not running')) : __('Not running'));
            $add('nftables', __('Firewall'), ($svc['nftables'] ?? false) ? 'ok' : 'bad', ($svc['nftables'] ?? false) ? __('Active') : __('Not active'));
            $root = $p['ssh']['root_login'] ?? null;
            $pass = $p['ssh']['password_auth'] ?? null;
            $add('ssh', __('SSH'), $root === 'yes' || $pass === 'yes' ? 'warn' : 'ok', implode(' · ', array_filter([
                $root === 'yes' ? __('root may log in with a password') : null,
                $pass === 'yes' ? __('password logins allowed, keys are safer') : null,
            ])) ?: __('Key login only'));
            $age = $p['clamav_signature_age'] ?? null;
            $add('clamav', __('Virus scanner'), ! ($svc['clamav-daemon'] ?? false) ? 'warn' : ($age !== null && $age > 3 * 86400 ? 'warn' : 'ok'),
                ! ($svc['clamav-daemon'] ?? false) ? __('ClamAV is not running (needs 2.5 GB RAM)')
                    : ($age === null ? __('Running') : __('Signatures updated :time', ['time' => now()->subSeconds($age)->diffForHumans()])));
            $last = $p['last_malware_scan'] ?? null;
            $add('malware', __('Malware scan of websites'), ! ($p['malware_scanner'] ?? false) ? 'warn' : ($last && $last > time() - 2 * 86400 ? 'ok' : 'warn'),
                ! ($p['malware_scanner'] ?? false) ? __('Not installed') : ($last ? __('Last scan :time', ['time' => now()->setTimestamp($last)->diffForHumans()]) : __('Not run yet')));
        }

        $source = $this->options->source();
        $db = $p['vulndb'] ?? null;
        if ($source === 'wordfence') {
            $fresh = ($db['updated_at'] ?? 0) > time() - 2 * 86400;
            $add('vulndb', __('Vulnerability database'), $fresh ? 'ok' : 'warn', $db && $db['ready']
                ? __('Wordfence Intelligence, :n records, updated :time', ['n' => number_format((int) $db['records']), 'time' => now()->setTimestamp((int) $db['updated_at'])->diffForHumans()])
                : __('Wordfence Intelligence, not downloaded yet'));
        } else {
            $add('vulndb', __('Vulnerability database'), 'ok', __('WPVulnerability.net (live, no API key)'));
        }

        $no2fa = User::where('role', User::ROLE_ADMIN)->where('is_active', true)->whereNull('two_factor_confirmed_at')->count();
        $add('2fa', __('Two-factor login for admins'), $no2fa > 0 ? 'warn' : 'ok',
            $no2fa > 0 ? trans_choice(':count administrator without two-factor login|:count administrators without two-factor login', $no2fa) : __('All administrators use it'));

        $penalty = collect($checks)->sum(fn ($c) => ['bad' => 20, 'warn' => 7][$c['status']] ?? 0);
        $score = $error ? null : max(0, 100 - $penalty);

        return [
            'checks' => $checks, 'score' => $score, 'error' => $error,
            'grade' => $score === null ? null : ($score >= 90 ? 'ok' : ($score >= 70 ? 'warn' : 'bad')),
            'alerts_24h' => $alerts, 'blocked' => $blocked, 'checked_at' => now()->toIso8601String(),
        ];
    }
}
