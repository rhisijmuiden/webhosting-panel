<?php

namespace App\Support;

/**
 * Languages the panel offers. English is built in; any lang/<code>.json file
 * adds a language. Packages add theirs with Locales::register().
 */
class Locales
{
    private const NAMES = [
        'en' => 'English', 'nl' => 'Nederlands', 'de' => 'Deutsch', 'fr' => 'Français', 'es' => 'Español',
        'it' => 'Italiano', 'pl' => 'Polski', 'pt' => 'Português', 'tr' => 'Türkçe', 'uk' => 'Українська',
    ];

    private static array $extra = [];

    public static function register(string $code, string $name): void
    {
        self::$extra[$code] = $name;
    }

    /** @return array<string, string> code => native name */
    public static function available(): array
    {
        $codes = ['en'];
        foreach (glob(lang_path('*.json')) ?: [] as $file) {
            $codes[] = basename($file, '.json');
        }
        $list = [];
        foreach (array_unique($codes) as $code) {
            $list[$code] = self::NAMES[$code] ?? strtoupper($code);
        }

        return array_merge($list, self::$extra);
    }

    public static function isAvailable(?string $code): bool
    {
        return $code !== null && array_key_exists($code, self::available());
    }
}
