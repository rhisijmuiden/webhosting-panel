<?php

declare(strict_types=1);

namespace Rhis\Backups\RestoreTests;

/** Human labels for the restore test report (scopes, checks, kinds). */
final class Labels
{
    public static function scope(string $s): string
    {
        return match (true) {
            $s === 'system' => __('Server configuration'),
            str_starts_with($s, 'site:') => __('Website :name', ['name' => substr($s, 5)]),
            str_starts_with($s, 'mail:') => __('Mail :name', ['name' => substr($s, 5)]),
            str_starts_with($s, 'db:') => __('Database :name', ['name' => substr($s, 3)]),
            default => $s,
        };
    }

    public static function icon(string $s): string
    {
        return match (true) {
            $s === 'system' => 'server', str_starts_with($s, 'site:') => 'globe',
            str_starts_with($s, 'mail:') => 'mail', str_starts_with($s, 'db:') => 'database', default => 'backup',
        };
    }

    public static function check(string $c): string
    {
        return match ($c) {
            'listing' => __('File list readable'),
            'disk_space' => __('Free disk space'),
            'restore' => __('Downloaded, decrypted and verified'),
            'complete' => __('Complete'),
            'symlinks' => __('Symbolic links'),
            'php_syntax' => __('PHP files parse'),
            'wordpress' => __('WordPress configuration'),
            'mailboxes' => __('Mailboxes'),
            'configuration' => __('Configuration folders'),
            'dump_complete' => __('Dump is complete'),
            'import' => __('Imports in a test database'),
            'tables' => __('Tables pass CHECK TABLE'),
            default => $c,
        };
    }

    public static function kind(?string $k): string
    {
        return match ($k) {
            'full' => __('Full'), 'incremental' => __('Incremental'), default => (string) $k,
        };
    }

    public static function itemStatus(?string $s): string
    {
        return match ($s) {
            'ok' => __('Passed'), 'warning' => __('Warning'), 'failed' => __('Failed'), 'skipped' => __('Skipped'), default => (string) $s,
        };
    }

    public static function tone(?string $s): string
    {
        return match ($s) {
            'ok' => 'ok', 'warning', 'skipped' => 'warn', 'failed' => 'bad', default => 'neutral',
        };
    }

    /** Bytes for people (1.4 GB). */
    public static function bytes(?int $b): string
    {
        if ($b === null) {
            return '–';
        }
        $u = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $v = (float) $b;
        while ($v >= 1024 && $i < 4) {
            $v /= 1024;
            $i++;
        }

        return round($v, $i ? 1 : 0).' '.$u[$i];
    }

    /** The engine reports details in English; show them in the user's language with readable sizes. */
    public static function detail(string $d): string
    {
        $n = fn ($v) => number_format((int) $v, 0, ',', '.');
        $b = fn ($v) => self::bytes((int) $v);
        $rules = [
            '/^(\d+) files, (\d+) bytes in the snapshot$/' => fn ($m) => __(':files files, :size in the backup', ['files' => $n($m[1]), 'size' => $b($m[2])]),
            '/^downloaded, decrypted and every file content verified$/' => fn () => __('downloaded, decrypted and the content of every file verified'),
            '/^(\d+) files and (\d+) bytes restored, as in the backup$/' => fn ($m) => __(':files files and :size restored, as in the backup', ['files' => $n($m[1]), 'size' => $b($m[2])]),
            '/^restored (\d+) of (\d+) files, (\d+) of (\d+) bytes$/' => fn ($m) => __('only :a of :b files (:c of :d) restored', ['a' => $n($m[1]), 'b' => $n($m[2]), 'c' => $b($m[3]), 'd' => $b($m[4])]),
            '/^restored (\d+) of (\d+) symbolic links$/' => fn ($m) => __('only :a of :b symbolic links restored', ['a' => $m[1], 'b' => $m[2]]),
            '/^(\d+) random PHP files parse$/' => fn ($m) => __(':n random PHP files are valid', ['n' => $m[1]]),
            '/^(\d+) of (\d+) PHP files do not parse \(first: (.*)\); this can also be the live code$/' => fn ($m) => __(':a of :b PHP files are not valid (first: :file); this can also be the case in the live code', ['a' => $m[1], 'b' => $m[2], 'file' => $m[3]]),
            '/^wp-config\.php present and readable$/' => fn () => __('wp-config.php present and readable'),
            '/^wp-config\.php has no database settings$/' => fn () => __('wp-config.php has no database settings'),
            '/^(\d+) messages restored$/' => fn ($m) => __(':n messages restored', ['n' => $n($m[1])]),
            '/^server configuration folders present$/' => fn () => __('server configuration folders present'),
            '/^missing: (.*)$/' => fn ($m) => __('missing: :list', ['list' => $m[1]]),
            '/^dump downloaded and decrypted \((\d+) bytes\)$/' => fn ($m) => __('dump downloaded and decrypted (:size)', ['size' => $b($m[1])]),
            '/^Dump completed on (.*)$/' => fn ($m) => __('dump completed on :date', ['date' => $m[1]]),
            '/^the dump has no end marker: it was cut off while it was made$/' => fn () => __('the dump has no end marker: it was cut off while it was made'),
            '/^imported in a private test database: (\d+) tables, about (\d+) rows$/' => fn ($m) => __('imported in a private test database: :t tables, about :r rows', ['t' => $n($m[1]), 'r' => $n($m[2])]),
            '/^imported (\d+) of (\d+) tables$/' => fn ($m) => __('only :a of :b tables imported', ['a' => $m[1], 'b' => $m[2]]),
            '/^every table passes CHECK TABLE$/' => fn () => __('every table passes CHECK TABLE'),
            '/^no private MariaDB instance available here; only the dump file was checked$/' => fn () => __('no private MariaDB instance available; only the dump file was checked'),
            '/^restoring (\d+) bytes would leave less than (\d+)% of the disk free \((\d+) bytes free now\)$/' => fn ($m) => __('restoring :size would leave less than :p% of the disk free (:free free now)', ['size' => $b($m[1]), 'p' => $m[2], 'free' => $b($m[3])]),
            '/^restic restore failed: (.*)$/' => fn ($m) => __('restore failed: :error', ['error' => $m[1]]),
            '/^import failed: (.*)$/' => fn ($m) => __('import failed: :error', ['error' => $m[1]]),
            '/^could not read the dump: (.*)$/' => fn ($m) => __('could not read the dump: :error', ['error' => $m[1]]),
            '/^could not read the file list of the snapshot: (.*)$/' => fn ($m) => __('could not read the file list of the backup: :error', ['error' => $m[1]]),
        ];
        foreach ($rules as $re => $fn) {
            if (preg_match($re, $d, $m)) {
                return $fn($m);
            }
        }

        return $d;
    }
}
