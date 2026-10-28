<?php

namespace App\Game\Engine;

/**
 * Seeded random number generator (mulberry32).
 *
 * Produces exactly the same sequence as the TypeScript version, so a match
 * seed means the same thing on the server and in the browser.
 */
final class Rng
{
    private int $s;

    public function __construct(int $seed)
    {
        $this->s = $seed & 0xFFFFFFFF;
    }

    /** 32-bit integer multiply with wrap-around (JavaScript's Math.imul). */
    private static function imul(int $a, int $b): int
    {
        $a &= 0xFFFFFFFF;
        $b &= 0xFFFFFFFF;
        $ah = ($a >> 16) & 0xFFFF;
        $al = $a & 0xFFFF;
        $bh = ($b >> 16) & 0xFFFF;
        $bl = $b & 0xFFFF;

        return (($al * $bl) + (((($ah * $bl) + ($al * $bh)) & 0xFFFF) << 16)) & 0xFFFFFFFF;
    }

    public function next(): float
    {
        $this->s = ($this->s + 0x6D2B79F5) & 0xFFFFFFFF;
        $t = $this->s;
        $t = self::imul($t ^ ($t >> 15), $t | 1);
        $t = ($t ^ (($t + self::imul($t ^ ($t >> 7), $t | 61)) & 0xFFFFFFFF)) & 0xFFFFFFFF;

        return (($t ^ ($t >> 14)) & 0xFFFFFFFF) / 4294967296;
    }

    public function range(float $a, float $b): float
    {
        return $a + ($b - $a) * $this->next();
    }

    public function int(int $a, int $b): int
    {
        return (int) floor($this->range($a, $b + 1));
    }

    public function chance(float $p): bool
    {
        return $this->next() < $p;
    }

    /**
     * @template T
     * @param  array<int, T>  $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        $items = array_values($items);

        return $items[(int) floor($this->next() * count($items))];
    }

    /**
     * Pick from a list of [item, weight] pairs. Weights need not sum to 1.
     *
     * @param  array<int, array{0: mixed, 1: float|int}>  $items
     */
    public function weighted(array $items): mixed
    {
        $total = 0.0;
        foreach ($items as [, $w]) {
            $total += max(0, $w);
        }
        $r = $this->next() * $total;
        foreach ($items as [$item, $w]) {
            $r -= max(0, $w);
            if ($r <= 0) {
                return $item;
            }
        }

        return $items[count($items) - 1][0];
    }

    public function normal(float $mean, float $sd): float
    {
        $u = max(1e-9, $this->next());
        $v = $this->next();

        return $mean + $sd * sqrt(-2 * log($u)) * cos(2 * M_PI * $v);
    }
}
