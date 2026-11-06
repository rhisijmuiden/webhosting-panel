<?php

declare(strict_types=1);

namespace Rhis\Reports;

use Carbon\CarbonInterface;

/** Numbers and dates in the report, in the language the mail is rendered in. */
final class Format
{
    private static function sep(): array
    {
        return app()->getLocale() === 'nl' ? [',', '.'] : ['.', ','];
    }

    public static function number(int|float|null $v): string
    {
        [$d, $t] = self::sep();

        return number_format((float) $v, 0, $d, $t);
    }

    /** Uptime style: 100% only when it really was, otherwise two decimals rounded down (never overstated). */
    public static function percent(int|float $v): string
    {
        [$d, $t] = self::sep();
        if ((float) $v >= 100) {
            return '100%';
        }

        return number_format(floor((float) $v * 100) / 100, 2, $d, $t).'%';
    }

    public static function bytes(int|float|null $b): string
    {
        [$d, $t] = self::sep();
        $b = (float) $b;
        foreach (['TB' => 1024 ** 4, 'GB' => 1024 ** 3, 'MB' => 1024 ** 2] as $unit => $size) {
            if ($b >= $size) {
                return number_format($b / $size, $b / $size >= 100 ? 0 : 1, $d, $t).' '.$unit;
            }
        }

        return number_format($b / 1024, 0, $d, $t).' KB';
    }

    public static function megabytes(int|float|null $mb): string
    {
        return self::bytes((float) $mb * 1024 * 1024);
    }

    public static function day(?CarbonInterface $d): string
    {
        $loc = app()->getLocale();

        return $d ? $d->locale($loc)->translatedFormat($loc === 'nl' ? 'j F' : 'F j') : '';
    }

    public static function monthName(CarbonInterface $d): string
    {
        return $d->locale(app()->getLocale())->translatedFormat('F');
    }

    /** Minutes as "9 minutes" / "2 hours 5 minutes". */
    public static function duration(int $minutes): string
    {
        if ($minutes < 60) {
            return trans_choice(':count minute|:count minutes', $minutes);
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return trans_choice(':count hour|:count hours', $h).($m ? ' '.trans_choice(':count minute|:count minutes', $m) : '');
    }
}
