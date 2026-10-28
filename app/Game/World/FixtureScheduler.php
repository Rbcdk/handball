<?php

namespace App\Game\World;

use App\Models\Club;
use App\Models\Fixture;
use App\Models\League;
use App\Models\Season;
use Carbon\CarbonImmutable;

final class FixtureScheduler
{
    /**
     * Double round robin (circle method). Every club meets every other club
     * home and away; the second half mirrors the first with venues swapped.
     *
     * @param  array<int, int>  $ids  club ids (even count)
     * @return array<int, array<int, array{0: int, 1: int}>> rounds of [home, away] pairs
     */
    public static function doubleRoundRobin(array $ids): array
    {
        $ids = array_values($ids);
        if (count($ids) % 2 === 1) {
            $ids[] = 0; // bye
        }
        $n = count($ids);
        $rounds = [];
        $rot = $ids;
        for ($r = 0; $r < $n - 1; $r++) {
            $pairs = [];
            for ($i = 0; $i < $n / 2; $i++) {
                $a = $rot[$i];
                $b = $rot[$n - 1 - $i];
                if ($a === 0 || $b === 0) {
                    continue;
                }
                // Alternate home advantage so nobody plays many home games in a row.
                $pairs[] = ($r + $i) % 2 === 0 ? [$a, $b] : [$b, $a];
            }
            $rounds[] = $pairs;
            // Keep the first element fixed, rotate the rest.
            $fixed = array_shift($rot);
            array_unshift($rot, array_pop($rot));
            array_unshift($rot, $fixed);
        }
        $second = array_map(fn ($pairs) => array_map(fn ($p) => [$p[1], $p[0]], $pairs), $rounds);

        return [...$rounds, ...$second];
    }

    /** Kick-off for a round: one round per day at the configured local time, stored in UTC. */
    public static function kickoff(CarbonImmutable $firstDay, int $round): CarbonImmutable
    {
        [$h, $m] = array_map('intval', explode(':', config('game.match_time')));

        return $firstDay->setTimezone(config('game.timezone'))
            ->startOfDay()
            ->addDays($round - 1)
            ->setTime($h, $m)
            ->utc();
    }

    /** Creates all fixtures for a season, every league starting on $firstDay. */
    public function schedule(Season $season, CarbonImmutable $firstDay): void
    {
        foreach (League::orderBy('sort')->get() as $league) {
            $ids = Club::where('league_id', $league->id)->pluck('id')->shuffle()->all();
            foreach (self::doubleRoundRobin($ids) as $i => $pairs) {
                $round = $i + 1;
                $at = self::kickoff($firstDay, $round);
                foreach ($pairs as [$home, $away]) {
                    Fixture::create([
                        'season_id' => $season->id,
                        'league_id' => $league->id,
                        'round' => $round,
                        'home_club_id' => $home,
                        'away_club_id' => $away,
                        'scheduled_at' => $at,
                        'seed' => random_int(1, 2_000_000_000),
                    ]);
                }
            }
        }
    }
}
