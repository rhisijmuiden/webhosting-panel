<?php

namespace App\Support;

/** Extra stylesheets and scripts that packages add to every panel page. */
class PanelAssets
{
    private static array $styles = [];

    private static array $scripts = [];

    public static function addStyle(string $url): void
    {
        self::$styles[$url] = $url;
    }

    public static function addScript(string $url): void
    {
        self::$scripts[$url] = $url;
    }

    public static function styles(): array
    {
        return array_values(self::$styles);
    }

    public static function scripts(): array
    {
        return array_values(self::$scripts);
    }
}
