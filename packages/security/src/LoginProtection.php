<?php

declare(strict_types=1);

namespace Rhis\Security;

use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Settings;
use Rhis\Security\Models\SiteProtection;

/**
 * The login protection settings, and pushing them to the server.
 *
 * Settings live in the panel (admin thresholds and allowlist in Settings,
 * per-site choices in site_protections). Every change sends the complete set
 * to the agent (security.apply), which writes the CrowdSec configuration.
 */
final class LoginProtection
{
    public const SERVICES = ['ssh', 'mail', 'panel', 'wordpress'];

    public const DEFAULTS = [
        'ssh' => ['enabled' => true, 'temp_after' => 5, 'window_minutes' => 10, 'ban_minutes' => 60, 'perm_after' => 30, 'perm_window_hours' => 24],
        'mail' => ['enabled' => true, 'temp_after' => 10, 'window_minutes' => 10, 'ban_minutes' => 60, 'perm_after' => 50, 'perm_window_hours' => 24],
        'panel' => ['enabled' => true, 'temp_after' => 5, 'window_minutes' => 10, 'ban_minutes' => 60, 'perm_after' => 30, 'perm_window_hours' => 24],
        'wordpress' => ['enabled' => true, 'temp_after' => 5, 'window_minutes' => 10, 'ban_minutes' => 60, 'perm_after' => 30, 'perm_window_hours' => 24],
    ];

    private const KEY_SERVICES = 'security.services';

    private const KEY_PERMANENT = 'security.permanent_days';

    private const KEY_ALLOWLIST = 'security.allowlist';

    private const KEY_LAST = 'security.last_apply';

    public function __construct(private readonly Settings $settings, private readonly AgentClient $agent) {}

    /** @return array<string, array<string, int|bool>> */
    public function services(): array
    {
        $stored = json_decode((string) $this->settings->get(self::KEY_SERVICES, '{}'), true) ?: [];
        $out = [];
        foreach (self::DEFAULTS as $name => $defaults) {
            $out[$name] = array_merge($defaults, array_intersect_key($stored[$name] ?? [], $defaults));
        }

        return $out;
    }

    public function permanentDays(): int
    {
        return (int) $this->settings->get(self::KEY_PERMANENT, 0);
    }

    /** @return list<array{address: string, comment: string}> */
    public function allowlist(): array
    {
        return array_values(json_decode((string) $this->settings->get(self::KEY_ALLOWLIST, '[]'), true) ?: []);
    }

    public function saveServices(array $services, int $permanentDays): void
    {
        $this->settings->set(self::KEY_SERVICES, json_encode($services));
        $this->settings->set(self::KEY_PERMANENT, (string) $permanentDays);
    }

    public function saveAllowlist(array $entries): void
    {
        $this->settings->set(self::KEY_ALLOWLIST, json_encode(array_values($entries)));
    }

    /** Protection for one site, without saving a row for sites nobody changed. */
    public function forSite(Website $website): SiteProtection
    {
        return SiteProtection::firstOrNew(['website_id' => $website->id], ['enabled' => true, 'wordpress' => true, 'allowlist' => []]);
    }

    /** Everything the agent needs, in the shape of security.apply. */
    public function payload(): array
    {
        $sites = SiteProtection::query()->with('website:id,domain')->get()
            ->filter(fn (SiteProtection $p) => $p->website !== null)
            ->filter(fn (SiteProtection $p) => ! $p->enabled || ! $p->wordpress || $p->allowlist)
            ->map(fn (SiteProtection $p) => [
                'domain' => $p->website->domain,
                'enabled' => (bool) $p->enabled,
                'wordpress' => (bool) $p->wordpress,
                'allowlist' => array_column($p->allowlist ?? [], 'address'),
            ])->sortBy('domain')->values()->all();

        return [
            'services' => $this->services(),
            'permanent_days' => $this->permanentDays(),
            'allowlist' => $this->allowlist(),
            'sites' => $sites,
        ];
    }

    /**
     * Push the settings to the server. Returns the agent's answer; throws when
     * the agent is unreachable or CrowdSec rejected the configuration.
     */
    public function apply(): array
    {
        try {
            $result = $this->agent->call('security.apply', $this->payload(), 180);
            $this->settings->set(self::KEY_LAST, json_encode(['ok' => true, 'at' => now()->toIso8601String(), 'warning' => $result['warning'] ?? null]));

            return $result;
        } catch (AgentException $e) {
            $this->settings->set(self::KEY_LAST, json_encode(['ok' => false, 'at' => now()->toIso8601String(), 'error' => $e->getMessage()]));
            throw $e;
        }
    }

    /** @return array{ok: bool, at: string, error?: string, warning?: string|null}|null */
    public function lastApply(): ?array
    {
        return json_decode((string) $this->settings->get(self::KEY_LAST, 'null'), true);
    }

    /** IPv4 /16 and IPv6 /48 at most for the admin; customers get a smaller limit. */
    public static function validNetwork(string $value, int $maxV4 = 16, int $maxV6 = 48): bool
    {
        [$ip, $prefix] = array_pad(explode('/', trim($value), 2), 2, null);
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        $v6 = str_contains($ip, ':');
        if ($prefix === null) {
            return true;
        }
        if (! ctype_digit($prefix)) {
            return false;
        }
        $p = (int) $prefix;

        return $v6 ? ($p >= $maxV6 && $p <= 128) : ($p >= $maxV4 && $p <= 32);
    }

    /** "203.0.113.7" and "203.0.113.7/32" are the same entry. */
    public static function normalise(string $value): string
    {
        $value = strtolower(trim($value));
        [$ip, $prefix] = array_pad(explode('/', $value, 2), 2, null);
        $ip = inet_ntop(inet_pton($ip));
        $full = str_contains($ip, ':') ? '128' : '32';

        return $prefix === null || $prefix === $full ? $ip : $ip.'/'.$prefix;
    }
}
