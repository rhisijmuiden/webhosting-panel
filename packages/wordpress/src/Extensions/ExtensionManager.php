<?php

declare(strict_types=1);

namespace Rhis\WordPress\Extensions;

use App\Services\Agent\AgentClient;
use Illuminate\Support\Facades\Cache;
use Rhis\WordPress\Models\WordPressInstall;

/** Plugins and themes of one install, through the agent (wp.ext_*). Keeps the lists on the install up to date. */
final class ExtensionManager
{
    public const KINDS = ['plugin', 'theme'];

    public const OPS = [
        'plugin' => ['activate', 'deactivate', 'delete', 'auto_update_on', 'auto_update_off'],
        'theme' => ['activate', 'delete', 'auto_update_on', 'auto_update_off'],
    ];

    public const SLUG = '/^[a-z0-9][a-z0-9._-]{0,99}$/';

    public function __construct(private readonly AgentClient $agent) {}

    /** Fresh lists from WordPress (no WordPress.org check), stored on the install. */
    public function load(WordPressInstall $install): WordPressInstall
    {
        $this->store($install, $this->agent->call('wp.ext_list', $install->target(), 150));

        return $install;
    }

    /** @param  list<string>  $slugs */
    public function apply(WordPressInstall $install, string $kind, string $op, array $slugs): array
    {
        $out = $this->agent->call('wp.ext_apply', $install->target() + ['kind' => $kind, 'op' => $op, 'slugs' => array_values($slugs)], 900);
        $this->store($install, $out);

        return $out;
    }

    public function install(WordPressInstall $install, string $kind, string $slug, bool $activate): array
    {
        $out = $this->agent->call('wp.ext_install', $install->target() + ['kind' => $kind, 'slug' => $slug, 'activate' => $activate], 900);
        $this->store($install, $out);

        return $out;
    }

    /** Search WordPress.org. Results are the same for every site, so they are kept a while. */
    public function search(WordPressInstall $install, string $kind, string $query, int $page = 1): array
    {
        $key = 'wordpress.ext-search.'.sha1($kind.'|'.mb_strtolower(trim($query)).'|'.$page);
        $minutes = (int) config('wordpress-extensions.search_cache_minutes', 30);

        return Cache::remember($key, now()->addMinutes($minutes), fn () => $this->agent->call('wp.ext_search', $install->target() + [
            'kind' => $kind, 'query' => $query, 'page' => $page,
        ], 120));
    }

    private function store(WordPressInstall $install, array $data): void
    {
        foreach (['plugins', 'themes'] as $list) {
            if (isset($data[$list]) && is_array($data[$list])) {
                $install->{$list} = $data[$list];
                $install->{'outdated_'.$list} = collect($data[$list])->where('update', true)->count();
            }
        }
        $install->save();
    }
}
