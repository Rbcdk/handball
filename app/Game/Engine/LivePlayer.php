<?php

namespace App\Game\Engine;

/** A player's state during a match. */
final class LivePlayer
{
    public float $energy = 100.0;

    public float $suspendedUntil = 0.0;

    public int $suspensions = 0;

    public bool $red = false;

    /**
     * @param  array{id: string, name: string, number: int, position: string, age: int, attrs: array<string, int>, fitness?: int}  $p
     *         fitness: 0-100, how rested he is at kick-off (tiredness from earlier matches)
     */
    public function __construct(public array $p)
    {
        $this->energy = (float) max(10, min(100, $p['fitness'] ?? 100));
    }

    public function available(float $clock): bool
    {
        return ! $this->red && $this->suspendedUntil <= $clock;
    }
}
