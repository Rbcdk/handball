<?php

namespace App\Support;

final class Ordinal
{
    /** English ordinal suffix: 1st, 2nd, 3rd, 4th, 11th, 21st... */
    public static function suffix(int $n): string
    {
        if (in_array($n % 100, [11, 12, 13], true)) {
            return 'th';
        }

        return match ($n % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };
    }
}
