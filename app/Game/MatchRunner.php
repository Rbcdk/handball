<?php

namespace App\Game;

use App\Game\Engine\MatchEngine;
use App\Models\Fixture;
use App\Models\MatchLog;
use App\Models\PlayerSeasonStat;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** Plays fixtures with the match engine and stores results, logs and player stats. */
final class MatchRunner
{
    public function __construct(private MatchEngine $engine, private Finance $finance, private Training $training, private Arena $arena, private Academy $academy)
    {
    }

    /** Plays every unplayed fixture whose kick-off time has passed. Returns the number played. */
    public function playDue(?CarbonInterface $now = null): int
    {
        $now ??= now();
        $played = 0;
        Fixture::upcoming()
            ->where('scheduled_at', '<=', $now)
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->each(function (Fixture $fixture) use (&$played) {
                $this->play($fixture);
                $played++;
            });

        return $played;
    }

    public function play(Fixture $fixture): void
    {
        DB::transaction(function () use ($fixture) {
            $fixture = Fixture::lockForUpdate()->with(['home', 'away'])->findOrFail($fixture->id);
            if ($fixture->isPlayed()) {
                return;
            }

            $result = $this->engine->simulate(
                $fixture->home->toEngineTeam(),
                $fixture->away->toEngineTeam(),
                (int) $fixture->seed,
            );

            $fixture->update([
                'played_at' => now(),
                'home_score' => $result['score'][0],
                'away_score' => $result['score'][1],
                'halftime_home' => $result['halftime'][0],
                'halftime_away' => $result['halftime'][1],
            ]);

            MatchLog::create([
                'fixture_id' => $fixture->id,
                'log' => MatchLog::encode($result),
            ]);

            $this->recordPlayerStats($fixture, $result);
            $this->finance->settleMatchDay($fixture);
            $this->training->afterMatch($fixture->home, $fixture, $result['playerStats']);
            $this->training->afterMatch($fixture->away, $fixture, $result['playerStats']);
            $this->arena->afterMatch($fixture);
            foreach ([$fixture->home, $fixture->away] as $club) {
                if ($club->isManaged()) {
                    $this->academy->afterMatch($club, $fixture);
                }
            }
        });
    }

    private function recordPlayerStats(Fixture $fixture, array $result): void
    {
        foreach (['home' => $fixture->home_club_id, 'away' => $fixture->away_club_id] as $side => $clubId) {
            foreach ($result[$side]['players'] as $p) {
                $s = $result['playerStats'][$p['id']];
                if ($s['secondsPlayed'] <= 0) {
                    continue;
                }
                $playerId = (int) substr($p['id'], 1);
                $row = PlayerSeasonStat::firstOrCreate(
                    ['player_id' => $playerId, 'season_id' => $fixture->season_id],
                    ['club_id' => $clubId, 'matches' => 0, 'goals' => 0, 'shots' => 0, 'assists' => 0,
                        'seven_m_goals' => 0, 'saves' => 0, 'shots_faced' => 0, 'suspensions' => 0],
                );
                $row->update([
                    'club_id' => $clubId,
                    'matches' => $row->matches + 1,
                    'goals' => $row->goals + $s['goals'],
                    'shots' => $row->shots + $s['shots'],
                    'assists' => $row->assists + $s['assists'],
                    'seven_m_goals' => $row->seven_m_goals + $s['sevenMGoals'],
                    'saves' => $row->saves + $s['saves'],
                    'shots_faced' => $row->shots_faced + $s['shotsFaced'],
                    'suspensions' => $row->suspensions + $s['suspensions'],
                ]);
            }
        }
    }
}
