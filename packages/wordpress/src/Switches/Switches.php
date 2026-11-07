<?php

declare(strict_types=1);

namespace Rhis\WordPress\Switches;

use App\Services\Agent\AgentClient;
use Rhis\WordPress\Models\WordPressInstall;

/** Maintenance mode, debug mode, search engine indexing and clearing caches (agent wp.* switch actions). */
final class Switches
{
    public const SWITCHES = ['maintenance', 'debug', 'indexing'];

    public function __construct(private readonly AgentClient $agent) {}

    public function stored(WordPressInstall $install): SwitchState
    {
        return SwitchState::firstOrNew(['wordpress_install_id' => $install->id]);
    }

    /** The state, read again from WordPress when $fresh or when the stored copy is old. */
    public function state(WordPressInstall $install, bool $fresh = false): SwitchState
    {
        $state = $this->stored($install);
        if ($fresh || ! $state->exists || $state->isStale()) {
            $state->fillFromAgent($this->agent->call('wp.switches', $install->target(), 90))->save();
        }

        return $state;
    }

    /**
     * Turn a switch on or off.
     *
     * @param  array{title?: string, message?: string, lang?: string, hours?: int|null}  $options
     */
    public function set(WordPressInstall $install, string $switch, bool $enabled, array $options = []): SwitchState
    {
        $params = $install->target() + ['enabled' => $enabled];
        if ($switch === 'maintenance' && $enabled) {
            $params += [
                'title' => (string) ($options['title'] ?? ''), 'message' => (string) ($options['message'] ?? ''),
                'lang' => in_array($options['lang'] ?? null, ['en', 'nl'], true) ? $options['lang'] : 'en',
            ];
        }
        $answer = $this->agent->call("wp.{$switch}_set", $params, 180);
        $state = $this->stored($install)->fillFromAgent($answer);
        if ($switch === 'debug') {
            $hours = $options['hours'] ?? null;
            $state->debug_until = $enabled && $hours ? now()->addHours((int) $hours) : null;
        }
        $state->save();

        return $state;
    }

    public function clearCache(WordPressInstall $install): array
    {
        $result = $this->agent->call('wp.cache_clear', $install->target(), 420);
        $this->stored($install)->fill(['cache_cleared_at' => now()])->save();

        return $result;
    }

    /** @param  array{level?: string, q?: string, limit?: int}  $filters */
    public function log(WordPressInstall $install, array $filters = []): array
    {
        return $this->agent->call('wp.debug_log', $install->target() + array_filter($filters, fn ($v) => $v !== null && $v !== ''), 60);
    }

    public function clearLog(WordPressInstall $install, string $which = 'panel'): void
    {
        $this->agent->call('wp.debug_log_clear', $install->target() + ['which' => $which], 60);
        $state = $this->stored($install);
        if ($which === 'public') {
            $state->public_log_size = null;
        } else {
            $state->log_size = 0;
        }
        $state->save();
    }

    /** Switch debug mode off on every install whose time is up. Returns how many were switched off. */
    public function expireDebug(): int
    {
        $n = 0;
        $due = SwitchState::with('install.website')->where('debug', true)->whereNotNull('debug_until')
            ->where('debug_until', '<=', now())->get();
        foreach ($due as $state) {
            if (! $state->install) {
                continue;
            }
            try {
                $this->set($state->install, 'debug', false);
                \App\Models\ActivityLog::record('wordpress.debug_expired', $state->install->label(), [], $state->install->website?->customer_id);
                $n++;
            } catch (\App\Services\Agent\AgentException $e) {
                report($e);
            }
        }

        return $n;
    }
}
