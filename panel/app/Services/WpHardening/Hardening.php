<?php

namespace App\Services\WpHardening;

use App\Models\WpHardeningState;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Carbon;
use Rhis\WordPress\Models\WordPressInstall;

/**
 * One-click WordPress security measures (agent: wphard.*). Every measure can be
 * switched off again; the server's files are the truth, the panel keeps the
 * last answer in wp_hardening_states for overviews.
 *
 * Other features (bulk actions) can call apply() with any subset of MEASURES.
 */
final class Hardening
{
    /** Measure keys, in the order the page shows them. All are recommended. */
    public const MEASURES = ['file_edit', 'php_folders', 'wp_config', 'hide_version', 'user_enum'];

    public function __construct(private readonly AgentClient $agent) {}

    /** Titles, explanations and side effects (English source strings, translated in the view). */
    public static function definitions(): array
    {
        return [
            'file_edit' => [
                'icon' => 'code',
                'title' => 'Turn off the file editor in WP admin',
                'text' => 'Adds DISALLOW_FILE_EDIT to wp-config.php. Someone who steals an administrator login can no longer change plugin or theme code from the dashboard.',
                'note' => 'Installing and updating plugins keeps working. Edit code through the file manager, FTP or Git instead.',
            ],
            'php_folders' => [
                'icon' => 'shield',
                'title' => 'Block PHP in uploads and system folders',
                'text' => 'PHP files in wp-content/uploads, upgrade and cache, in wp-includes and in wp-admin/includes cannot be opened from the web. A backdoor uploaded through a weak plugin then does nothing.',
                'note' => 'A rare old plugin that opens its own PHP file from the uploads folder stops working; switch this off again if that happens.',
            ],
            'wp_config' => [
                'icon' => 'lock',
                'title' => 'Protect wp-config.php',
                'text' => 'wp-config.php holds the database password. It becomes readable by this website only (permissions 600) and is never served over the web.',
                'note' => 'WordPress, WP-CLI and the panel keep working. A plugin that writes to wp-config.php can still do so.',
            ],
            'hide_version' => [
                'icon' => 'search',
                'title' => 'Hide the WordPress version',
                'text' => 'Removes the version from the page source, feeds and file links, and blocks readme.html and license.txt. Scanners that look for an old version find nothing.',
                'note' => 'Hiding is no replacement for updating: keep WordPress up to date.',
            ],
            'user_enum' => [
                'icon' => 'user',
                'title' => 'Hide user names',
                'text' => 'Blocks /?author=1 scans and hides the user list of the REST API and the user sitemap from visitors who are not logged in. Attackers then have to guess the login name as well.',
                'note' => 'Author pages (/author/name/) stay. Logged-in editors still see the user list in the block editor.',
            ],
        ];
    }

    public function state(WordPressInstall $install): ?WpHardeningState
    {
        return WpHardeningState::where('wordpress_install_id', $install->id)->first();
    }

    /** @throws AgentException */
    public function status(WordPressInstall $install): array
    {
        return $this->remember($install, $this->agent->call('wphard.status', $install->target(), 60));
    }

    /**
     * Switch measures on (true) or off (false); keys not given stay as they are.
     *
     * @param  array<string, bool>  $measures
     *
     * @throws AgentException
     */
    public function apply(WordPressInstall $install, array $measures): array
    {
        $measures = array_intersect_key(array_map('boolval', $measures), array_flip(self::MEASURES));

        return $this->remember($install, $this->agent->call('wphard.apply', [...$install->target(), 'measures' => $measures], 180));
    }

    /** @throws AgentException */
    public function hardenAll(WordPressInstall $install): array
    {
        return $this->apply($install, array_fill_keys(self::MEASURES, true));
    }

    /** New secret keys (logs everybody out), or the previous ones back. @throws AgentException */
    public function salts(WordPressInstall $install, bool $undo = false): array
    {
        return $this->remember($install, $this->agent->call('wphard.salts', [...$install->target(), 'undo' => $undo], 120));
    }

    private function remember(WordPressInstall $install, array $status): array
    {
        $at = $status['salts']['refreshed_at'] ?? null;
        WpHardeningState::updateOrCreate(['wordpress_install_id' => $install->id], [
            'measures' => collect($status['measures'] ?? [])->map(fn ($m) => (bool) ($m['on'] ?? false))->all(),
            'on_count' => (int) ($status['on'] ?? 0),
            'total' => (int) ($status['total'] ?? count(self::MEASURES)),
            'salts_refreshed_at' => $at ? Carbon::createFromTimestamp((int) $at) : null,
            'checked_at' => now(),
        ]);

        return $status;
    }
}
