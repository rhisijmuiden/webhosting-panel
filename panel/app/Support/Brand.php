<?php

namespace App\Support;

use App\Services\Settings;
use Throwable;

/**
 * The one place that knows the brand.
 *
 * - name():     the software, "Dekwerk". Change it in config/panel.php (brand.name).
 * - provider(): the hosting company that runs this server and talks to the
 *               customers ("RHIS"). Admins change it under Tools & settings > Branding.
 * - logo(), icon(), favicon(): paths under public/ (config/panel.php brand.*).
 */
class Brand
{
    public static function name(): string
    {
        return (string) config('panel.brand.name', 'Dekwerk');
    }

    public static function provider(): string
    {
        try {
            $saved = trim((string) app(Settings::class)->get('brand.provider', ''));
        } catch (Throwable) {
            $saved = '';
        }

        return $saved !== '' ? $saved : (string) config('panel.brand.provider', 'RHIS');
    }

    /** Light logo, for the dark menu and mail headers. */
    public static function logo(): string
    {
        return (string) config('panel.brand.logo');
    }

    /** Dark logo, for light backgrounds. */
    public static function logoDark(): string
    {
        return (string) config('panel.brand.logo_dark');
    }

    public static function icon(): string
    {
        return (string) config('panel.brand.icon');
    }

    public static function favicon(): string
    {
        return (string) config('panel.brand.favicon');
    }

    /** Absolute path of the light logo, for mails that embed it. */
    public static function logoPath(): string
    {
        return public_path(self::logo());
    }
}
