<?php

declare(strict_types=1);

namespace Rhis\Apps\PluginSets;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Looks up plugin and theme slugs on wordpress.org, so a typo shows up when the set is saved. */
final class WordPressDirectory
{
    private const API = [
        'plugin' => 'https://api.wordpress.org/plugins/info/1.2/',
        'theme' => 'https://api.wordpress.org/themes/info/1.2/',
    ];

    /**
     * The display name of a slug ("Wordfence Security"), false when wordpress.org does not
     * know it, null when wordpress.org could not be reached (the set is saved anyway).
     */
    public function lookup(string $type, string $slug): string|false|null
    {
        $key = "apps.wporg.$type.$slug";
        if (Cache::has($key)) {
            return Cache::get($key);
        }
        try {
            $res = Http::timeout(5)->acceptJson()->get(self::API[$type], [
                'action' => $type.'_information',
                'request' => ['slug' => $slug, 'fields' => ['sections' => false, 'versions' => false]],
            ]);
        } catch (Throwable) {
            return null;
        }
        $data = $res->json();
        if ($res->status() === 404 || (is_array($data) && isset($data['error']))) {
            $name = false;
        } elseif ($res->successful() && is_array($data) && isset($data['name'])) {
            $name = html_entity_decode(strip_tags((string) $data['name']), ENT_QUOTES | ENT_HTML5);
        } else {
            return null;
        }
        Cache::put($key, $name, $name === false ? 600 : 86400);

        return $name;
    }
}
