<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Website;
use App\Services\Agent\AgentClient;

/**
 * PHP fine-tuning. The admin sets a default and a maximum per setting for the
 * whole server; customers choose their own value per website up to that maximum.
 * The values reach PHP as php_admin_value, so scripts cannot raise them with ini_set().
 */
class PhpSettings
{
    /** key => [label, unit, default, maximum, lowest allowed, highest allowed]. */
    public const KEYS = [
        'memory_limit' => ['Memory limit', 'MB', 256, 1024, 32, 16384],
        'max_execution_time' => ['Max execution time', 's', 120, 600, 5, 86400],
        'max_input_time' => ['Max input time', 's', 120, 600, 5, 86400],
        'max_input_vars' => ['Max input vars', null, 5000, 20000, 1000, 1000000],
        'post_max_size' => ['Max POST size', 'MB', 128, 512, 1, 100000],
        'upload_max_filesize' => ['Max upload size', 'MB', 128, 512, 1, 100000],
    ];

    public const DEFAULT_WORKERS = 10;

    private const SETTING = 'php.server';

    public function __construct(private readonly Settings $settings, private readonly AgentClient $agent) {}

    /** Server level: ['memory_limit' => ['default' => 256, 'max' => 1024], ..., 'display_errors' => bool, 'workers' => int]. */
    public function server(): array
    {
        $saved = json_decode((string) $this->settings->get(self::SETTING, ''), true) ?: [];
        $out = [];
        foreach (self::KEYS as $key => [, , $default, $max]) {
            $out[$key] = [
                'default' => (int) ($saved[$key]['default'] ?? $default),
                'max' => (int) ($saved[$key]['max'] ?? $max),
            ];
        }
        $out['display_errors'] = (bool) ($saved['display_errors'] ?? false);
        $out['customer_display_errors'] = (bool) ($saved['customer_display_errors'] ?? true);
        $out['workers'] = (int) ($saved['workers'] ?? self::DEFAULT_WORKERS);

        return $out;
    }

    public function saveServer(array $server): void
    {
        $this->settings->set(self::SETTING, json_encode($server));
        ActivityLog::record('php.server_settings_changed', null, $server);
    }

    /** What the site runs with: its own value or the default, never above the server maximum. */
    public function effective(Website $website): array
    {
        $server = $this->server();
        $own = $website->php_settings ?? [];
        $out = [];
        foreach (self::KEYS as $key => [, , , , $lowest]) {
            $value = isset($own[$key]) ? (int) $own[$key] : $server[$key]['default'];
            $out[$key] = max($lowest, min($value, $server[$key]['max']));
        }
        // An upload is part of the POST body.
        $out['post_max_size'] = max($out['post_max_size'], $out['upload_max_filesize']);
        $out['display_errors'] = $server['customer_display_errors'] && array_key_exists('display_errors', $own)
            ? (bool) $own['display_errors'] : $server['display_errors'];

        return $out;
    }

    public function workers(Website $website): int
    {
        return $website->package?->php_workers ?? $this->server()['workers'];
    }

    /** Write the effective settings to the server (pool, Nginx upload size and timeouts). */
    public function apply(Website $website): void
    {
        $e = $this->effective($website);
        $settings = [];
        foreach (self::KEYS as $key => [, $unit]) {
            $settings[$key] = $unit === 'MB' ? $e[$key].'M' : (string) $e[$key];
        }
        $settings['display_errors'] = $e['display_errors'] ? 'on' : 'off';
        $this->agent->call('site.php_settings', [
            'domain' => $website->domain,
            'settings' => $settings,
            'php_workers' => $this->workers($website),
        ], 120);
    }

    /** Validation rules for the PHP form of a website (fields php[<key>], empty = default). */
    public function siteRules(): array
    {
        $server = $this->server();
        $rules = ['php' => ['array']];
        foreach (self::KEYS as $key => [, , , , $lowest]) {
            $rules["php.$key"] = ['nullable', 'integer', "min:$lowest", 'max:'.$server[$key]['max']];
        }
        $rules['php.display_errors'] = ['nullable', 'in:on,off'];

        return $rules;
    }

    /** The validated form input as stored in websites.php_settings (only what differs from the default). */
    public function fromInput(array $input): array
    {
        $out = [];
        foreach (self::KEYS as $key => $_) {
            if (isset($input[$key]) && $input[$key] !== '') {
                $out[$key] = (int) $input[$key];
            }
        }
        if (in_array($input['display_errors'] ?? null, ['on', 'off'], true) && $this->server()['customer_display_errors']) {
            $out['display_errors'] = $input['display_errors'] === 'on';
        }

        return $out;
    }
}
