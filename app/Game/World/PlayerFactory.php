<?php

namespace App\Game\World;

use App\Game\Engine\Rng;

/** Generates player attributes by position and level (same rules as the prototype). */
final class PlayerFactory
{
    /** Squad template: 16 players. The first listed at each position is the intended starter. */
    public const SQUAD = ['GK', 'GK', 'LW', 'LW', 'LB', 'LB', 'CB', 'CB', 'RB', 'RB', 'RW', 'RW', 'PV', 'PV', 'LB', 'RB'];

    private const BONUS = [
        'LW' => ['speed' => 9, 'technique' => 6, 'jumping' => 4, 'shooting' => 2, 'defense' => -5, 'passing' => -3],
        'RW' => ['speed' => 9, 'technique' => 6, 'jumping' => 4, 'shooting' => 2, 'defense' => -5, 'passing' => -3],
        'LB' => ['shooting' => 8, 'jumping' => 6, 'passing' => 2, 'speed' => -2, 'defense' => 2],
        'RB' => ['shooting' => 8, 'jumping' => 6, 'passing' => 2, 'speed' => -2, 'defense' => 2],
        'CB' => ['passing' => 10, 'technique' => 6, 'composure' => 5, 'speed' => 1],
        'PV' => ['defense' => 8, 'technique' => 2, 'composure' => 4, 'speed' => -6, 'passing' => -5, 'shooting' => -2],
    ];

    public function __construct(private Rng $rng, private NameGenerator $names)
    {
    }

    private static function clamp(float $v, int $a = 20, int $b = 96): int
    {
        return (int) round(max($a, min($b, $v)));
    }

    /** @return array<string, int> attribute columns */
    public function attributes(string $pos, float $level): array
    {
        $r = fn (float $bonus) => self::clamp($this->rng->normal($level + $bonus, 5));
        if ($pos === 'GK') {
            return [
                'shooting' => self::clamp($this->rng->normal(30, 6)),
                'passing' => $r(-8),
                'technique' => self::clamp($this->rng->normal(35, 6)),
                'speed' => $r(-12),
                'stamina' => $r(0),
                'jumping' => $r(-5),
                'defense' => self::clamp($this->rng->normal(30, 6)),
                'composure' => $r(2),
                'reflexes' => $r(4),
                'positioning' => $r(2),
                'seven_meter' => $r(0),
            ];
        }
        $b = self::BONUS[$pos];

        return [
            'shooting' => $r($b['shooting'] ?? 0),
            'passing' => $r($b['passing'] ?? 0),
            'technique' => $r($b['technique'] ?? 0),
            'speed' => $r($b['speed'] ?? 0),
            'stamina' => $r($b['stamina'] ?? 0),
            'jumping' => $r($b['jumping'] ?? 0),
            'defense' => $r($b['defense'] ?? 0),
            'composure' => $r($b['composure'] ?? 0),
            'reflexes' => self::clamp($this->rng->normal(25, 5)),
            'positioning' => self::clamp($this->rng->normal(25, 5)),
            'seven_meter' => self::clamp($this->rng->normal(25, 5)),
        ];
    }

    /**
     * A full 16-player squad at the given strength (average starter level, ~55-72).
     *
     * @return array<int, array<string, mixed>> rows for Player::create()
     */
    public function squad(float $strength): array
    {
        $rows = [];
        $usedNumbers = [];
        $usedNames = [];
        $seen = [];
        foreach (self::SQUAD as $pos) {
            $seen[$pos] = ($seen[$pos] ?? 0) + 1;
            $level = $strength + ($seen[$pos] === 1 ? 3 : -4) + $this->rng->normal(0, 3);
            $rows[] = $this->player($pos, $level, $usedNumbers, $usedNames, $seen[$pos]);
        }

        return $rows;
    }

    /**
     * One player row. Young players get a higher potential.
     *
     * @param  array<int, int>  $usedNumbers
     * @param  array<int, string>  $usedNames
     */
    public function player(string $pos, float $level, array &$usedNumbers, array &$usedNames, int $nth = 1, ?int $age = null): array
    {
        do {
            [$first, $last] = $this->names->playerName();
        } while (in_array("$first $last", $usedNames, true));
        $usedNames[] = "$first $last";

        $number = $pos === 'GK' ? ($nth === 1 ? 1 : 16) : $this->rng->int(2, 99);
        while (in_array($number, $usedNumbers, true)) {
            $number = $this->rng->int(2, 99);
        }
        $usedNumbers[] = $number;

        $age ??= $this->rng->int(19, 34);
        $headroom = $age <= 21 ? 14 : ($age <= 24 ? 9 : ($age <= 27 ? 4 : 1));

        return [
            'first_name' => $first,
            'last_name' => $last,
            'number' => $number,
            'position' => $pos,
            'age' => $age,
            ...$this->attributes($pos, $level),
            'potential' => self::clamp($level + $headroom + $this->rng->normal(0, 3), 30, 96),
        ];
    }
}
