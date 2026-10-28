<?php

namespace App\Console\Commands;

use App\Game\Engine\Rng;
use App\Game\World\NameGenerator;
use App\Models\Club;
use App\Models\MatchLog;
use App\Models\Player;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RenameWorld extends Command
{
    protected $signature = 'game:rename-world {--seed= : Seed for the new names}';

    protected $description = 'Give every league, club and player its invented name. Results, stats and replays are kept (replays are updated to the new names).';

    public function handle(): int
    {
        if (! Club::exists()) {
            $this->error('There is no world to rename yet. Create one with: php artisan game:new-world');

            return self::FAILURE;
        }
        $rng = new Rng($this->option('seed') ? (int) $this->option('seed') : random_int(1, 2_000_000_000));
        $names = new NameGenerator($rng);

        DB::transaction(function () use ($names) {
            // Leagues take the current names from WorldBuilder::LEAGUES, matched by their order.
            foreach (\App\Models\League::orderBy('sort')->get()->values() as $i => $league) {
                if ($def = \App\Game\World\WorldBuilder::LEAGUES[$i] ?? null) {
                    $league->update(['name' => $def['name'], 'slug' => $def['slug']]);
                }
            }

            $takenNames = [];
            $takenShorts = [];
            foreach (Club::orderBy('id')->get() as $club) {
                $new = $names->club($takenNames, $takenShorts);
                $takenNames[] = $new['name'];
                $takenShorts[] = $new['short'];
                $club->update($new);
            }

            $used = [];
            foreach (Player::orderBy('id')->get() as $player) {
                do {
                    [$first, $last] = $names->playerName();
                } while (isset($used["$first $last"]));
                $used["$first $last"] = true;
                $player->update(['first_name' => $first, 'last_name' => $last]);
            }

            // Replays store names as they were at kick-off; bring them in line with the new names.
            $clubs = Club::all()->keyBy(fn ($c) => 'c'.$c->id);
            $players = Player::all()->keyBy(fn ($p) => 'p'.$p->id);
            MatchLog::query()->chunkById(200, function ($logs) use ($clubs, $players) {
                foreach ($logs as $log) {
                    $data = $log->data();
                    foreach (['home', 'away'] as $side) {
                        if ($club = $clubs[$data[$side]['id']] ?? null) {
                            $data[$side]['name'] = $club->name;
                            $data[$side]['short'] = $club->short;
                        }
                        foreach ($data[$side]['players'] as $i => $p) {
                            if ($player = $players[$p['id']] ?? null) {
                                $data[$side]['players'][$i]['name'] = $player->name;
                            }
                        }
                    }
                    $log->update(['log' => MatchLog::encode($data)]);
                }
            }, 'fixture_id');
        });

        $this->info('Renamed '.Club::count().' clubs and '.Player::count().' players.');
        $this->line('Examples: '.Club::inRandomOrder()->take(3)->pluck('name')->implode(', ').'.');

        return self::SUCCESS;
    }
}
