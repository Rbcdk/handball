<?php

namespace App\Console\Commands;

use App\Game\Engine\MatchEngine;
use App\Game\Engine\Rng;
use App\Game\World\NameGenerator;
use App\Game\World\PlayerFactory;
use Illuminate\Console\Command;

class Calibrate extends Command
{
    protected $signature = 'game:calibrate {matches=500} {--home=65} {--away=65}';

    protected $description = 'Simulate many matches between two generated squads and print average statistics';

    public function handle(MatchEngine $engine): int
    {
        $rng = new Rng(77);
        $factory = new PlayerFactory($rng, new NameGenerator($rng));
        $team = function (string $id, float $strength) use ($factory) {
            $players = [];
            foreach ($factory->squad($strength) as $i => $row) {
                $players[] = [
                    'id' => "$id-$i", 'name' => $row['first_name'].' '.$row['last_name'], 'number' => $row['number'],
                    'position' => $row['position'], 'age' => $row['age'],
                    'attrs' => [
                        'shooting' => $row['shooting'], 'passing' => $row['passing'], 'technique' => $row['technique'],
                        'speed' => $row['speed'], 'stamina' => $row['stamina'], 'jumping' => $row['jumping'],
                        'defense' => $row['defense'], 'composure' => $row['composure'], 'reflexes' => $row['reflexes'],
                        'positioning' => $row['positioning'], 'sevenMeter' => $row['seven_meter'],
                    ],
                ];
            }

            return ['id' => $id, 'name' => $id, 'short' => $id, 'color' => '#000', 'players' => $players,
                'tactics' => ['defense' => '6-0', 'tempo' => 'normal', 'focus' => 'balanced', 'sevenVsSix' => 'whenShort', 'rotation' => 'normal']];
        };
        $home = $team('H', (float) $this->option('home'));
        $away = $team('A', (float) $this->option('away'));

        $n = (int) $this->argument('matches');
        $sum = [];
        $wins = 0;
        $draws = 0;
        $start = microtime(true);
        for ($i = 0; $i < $n; $i++) {
            $m = $engine->simulate($home, $away, 1000 + $i);
            foreach (['home', 'away'] as $side) {
                foreach ($m['teamStats'][$side] as $k => $v) {
                    $sum[$k] = ($sum[$k] ?? 0) + $v;
                }
            }
            $wins += $m['score'][0] > $m['score'][1] ? 1 : 0;
            $draws += $m['score'][0] === $m['score'][1] ? 1 : 0;
        }
        $per = fn ($k) => round($sum[$k] / ($n * 2), 1);
        $this->table(['Per team per match', 'Value'], [
            ['Goals', $per('goals')],
            ['Shots', $per('shots')],
            ['Possessions', $per('possessions')],
            ['Shooting %', round($sum['goals'] / $sum['shots'] * 100, 1)],
            ['Save %', round($sum['saves'] / max(1, $sum['shotsFaced']) * 100, 1)],
            ['7 m goals / attempts', $per('sevenMGoals').' / '.$per('sevenMShots')],
            ['Fast-break goals', $per('fastBreakGoals')],
            ['Turnovers', $per('turnovers')],
            ['2-minute suspensions', $per('suspensions')],
        ]);
        $this->line(sprintf('Home wins %d%%, draws %d%%. %.1f ms per match.', $wins / $n * 100, $draws / $n * 100, (microtime(true) - $start) * 1000 / $n));

        return self::SUCCESS;
    }
}
