<?php

namespace App\Game\Engine;

/** A team's state during a match. */
final class TeamState
{
    /** @var array<string, LivePlayer> */
    public array $live = [];

    /** @var array<string, string> slot => player id */
    public array $slots = [];

    public string $gk = '';

    public int $timeouts = 3;

    public int $halfTimeouts = 0;

    public int $concededRun = 0;

    public int $gkHalfShots = 0;

    public int $gkHalfSaves = 0;

    public bool $gkChangedThisHalf = false;

    public int $score = 0;

    /** @var array<string, int> */
    public array $stats = [
        'goals' => 0, 'shots' => 0, 'sevenMGoals' => 0, 'sevenMShots' => 0, 'fastBreakGoals' => 0,
        'saves' => 0, 'shotsFaced' => 0, 'turnovers' => 0, 'steals' => 0, 'suspensions' => 0,
        'possessions' => 0, 'timeouts' => 0,
    ];

    /** @param array<string, mixed> $team */
    public function __construct(public string $side, public array $team)
    {
    }

    public function tactic(string $key): string
    {
        return $this->team['tactics'][$key];
    }

    public function live(string $id): LivePlayer
    {
        return $this->live[$id];
    }
}
