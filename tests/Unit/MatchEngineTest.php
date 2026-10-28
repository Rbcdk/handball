<?php

namespace Tests\Unit;

use App\Game\Engine\MatchEngine;
use App\Game\Engine\Rng;
use App\Game\World\FixtureScheduler;
use App\Game\World\NameGenerator;
use App\Game\World\PlayerFactory;
use PHPUnit\Framework\TestCase;

class MatchEngineTest extends TestCase
{
    private function team(string $id, float $strength, int $seed): array
    {
        $rng = new Rng($seed);
        $players = [];
        foreach ((new PlayerFactory($rng, new NameGenerator($rng)))->squad($strength) as $i => $r) {
            $players[] = [
                'id' => "$id$i", 'name' => "{$r['first_name']} {$r['last_name']}", 'number' => $r['number'],
                'position' => $r['position'], 'age' => $r['age'],
                'attrs' => [
                    'shooting' => $r['shooting'], 'passing' => $r['passing'], 'technique' => $r['technique'], 'speed' => $r['speed'],
                    'stamina' => $r['stamina'], 'jumping' => $r['jumping'], 'defense' => $r['defense'], 'composure' => $r['composure'],
                    'reflexes' => $r['reflexes'], 'positioning' => $r['positioning'], 'sevenMeter' => $r['seven_meter'],
                ],
            ];
        }

        return ['id' => $id, 'name' => $id, 'short' => $id, 'color' => '#000', 'players' => $players,
            'tactics' => ['defense' => '6-0', 'tempo' => 'normal', 'focus' => 'balanced', 'sevenVsSix' => 'whenShort', 'rotation' => 'normal']];
    }

    public function test_same_seed_gives_the_same_match(): void
    {
        $engine = new MatchEngine;
        $a = $engine->simulate($this->team('H', 65, 1), $this->team('A', 65, 2), 1234);
        $b = $engine->simulate($this->team('H', 65, 1), $this->team('A', 65, 2), 1234);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_scores_match_the_event_log_and_look_like_handball(): void
    {
        $engine = new MatchEngine;
        $total = 0;
        for ($i = 0; $i < 50; $i++) {
            $m = $engine->simulate($this->team('H', 65, 1), $this->team('A', 65, 2), $i);
            $goals = 0;
            foreach ($m['possessions'] as $p) {
                foreach ($p['actions'] as $a) {
                    $goals += ($a['t'] === 'shot' && $a['result'] === 'goal') ? 1 : 0;
                }
            }
            $this->assertSame($m['score'][0] + $m['score'][1], $goals);
            $total += $goals;
        }
        $perTeam = $total / 100;
        $this->assertGreaterThan(24, $perTeam);
        $this->assertLessThan(34, $perTeam);
    }

    public function test_stronger_team_usually_wins(): void
    {
        $engine = new MatchEngine;
        $wins = 0;
        for ($i = 0; $i < 100; $i++) {
            $m = $engine->simulate($this->team('S', 72, 1), $this->team('W', 58, 2), $i);
            $wins += $m['score'][0] > $m['score'][1] ? 1 : 0;
        }
        $this->assertGreaterThan(75, $wins);
    }

    public function test_double_round_robin_pairs_every_club_twice(): void
    {
        $rounds = FixtureScheduler::doubleRoundRobin(range(1, 14));
        $this->assertCount(26, $rounds);
        $seen = [];
        foreach ($rounds as $pairs) {
            $this->assertCount(7, $pairs);
            foreach ($pairs as [$h, $a]) {
                $seen["$h-$a"] = ($seen["$h-$a"] ?? 0) + 1;
            }
        }
        $this->assertCount(14 * 13, $seen);
        $this->assertSame([1], array_values(array_unique($seen)));
    }
}
