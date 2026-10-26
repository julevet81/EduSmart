<?php

declare(strict_types=1);

namespace App\Support\Schedule;

/** Times are stored as "HH:MM:SS" strings so that SQL string comparison equals time comparison. */
final class Time
{
    public static function normalize(string $time): string
    {
        return strlen($time) === 5 ? $time.':00' : $time;
    }

    public static function short(string $time): string
    {
        return substr($time, 0, 5);
    }
}
