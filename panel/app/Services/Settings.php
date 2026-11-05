<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Key/value settings stored in the database. Secrets are encrypted with APP_KEY. */
class Settings
{
    private const CACHE_KEY = 'panel.settings';

    public function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            try {
                if (! Schema::hasTable('settings')) {
                    return [];
                }
            } catch (Throwable) {
                return [];
            }

            return Setting::all()->mapWithKeys(function (Setting $s) {
                try {
                    $value = $s->encrypted && $s->value !== null ? Crypt::decryptString($s->value) : $s->value;
                } catch (Throwable) {
                    $value = null;
                }

                return [$s->key => $value];
            })->all();
        });
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function set(string $key, mixed $value, bool $encrypted = false): void
    {
        $stored = $value === null ? null : (string) $value;
        if ($encrypted && $stored !== null) {
            $stored = Crypt::encryptString($stored);
        }
        Setting::updateOrCreate(['key' => $key], ['value' => $stored, 'encrypted' => $encrypted]);
        Cache::forget(self::CACHE_KEY);
    }

    public function forget(string $key): void
    {
        Setting::whereKey($key)->delete();
        Cache::forget(self::CACHE_KEY);
    }
}
