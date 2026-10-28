<?php

namespace App\Game\World;

use App\Game\Engine\Rng;
use App\Models\Club;
use App\Models\League;
use App\Models\Player;
use App\Models\Season;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Creates the starting world: leagues, AI clubs with squads, and season 1's fixtures. */
final class WorldBuilder
{
    public const LEAGUES = [
        ['name' => 'Crown League', 'slug' => 'crown-league', 'tier' => 1, 'sort' => 1],
        ['name' => 'Ember League', 'slug' => 'ember-league', 'tier' => 2, 'sort' => 2],
        ['name' => 'Frost League', 'slug' => 'frost-league', 'tier' => 2, 'sort' => 3],
    ];

    /** Club colours that read well as player discs on the blue court. */
    public const COLORS = [
        '#d6453d', '#e0a21b', '#1f9d6b', '#7a4fd1', '#e86a92', '#14a3a8', '#a33b2e', '#5a8f29',
        '#c2185b', '#f2c94c', '#2e7d32', '#ef6c00', '#6d4c41', '#00897b', '#8e24aa', '#b8860b',
        '#d84315', '#558b2f', '#ad1457', '#f57f17', '#4e342e',
    ];

    /** Players without a club, available to sign on the transfer page. */
    public const FREE_AGENTS = 30;

    public function __construct(private FixtureScheduler $scheduler, private \App\Game\Finance $finance)
    {
    }

    public function build(?int $seed = null, ?CarbonImmutable $firstDay = null): Season
    {
        if (Season::exists()) {
            throw new RuntimeException('A world already exists. Use --fresh to rebuild it.');
        }

        $rng = new Rng($seed ?? random_int(1, 2_000_000_000));
        $names = new NameGenerator($rng);
        $factory = new PlayerFactory($rng, $names);
        $perLeague = config('game.clubs_per_league');
        $firstDay ??= CarbonImmutable::now(config('game.timezone'))->addDay();

        return DB::transaction(function () use ($rng, $names, $factory, $perLeague, $firstDay) {
            $taken = [];
            $shorts = [];
            $colorIndex = 0;
            foreach (self::LEAGUES as $def) {
                $league = League::create($def);
                for ($i = 0; $i < $perLeague; $i++) {
                    $club = $names->club($taken, $shorts);
                    $taken[] = $club['name'];
                    $shorts[] = $club['short'];
                    // The top league is stronger; within a league strengths are spread out.
                    $strength = ($def['tier'] === 1 ? 62 : 56) + ($i / max(1, $perLeague - 1)) * 8 + $rng->normal(0, 1);
                    $model = Club::create([
                        'league_id' => $league->id,
                        'name' => $club['name'],
                        'short' => $club['short'],
                        'city' => $club['city'],
                        'color' => self::COLORS[$colorIndex++ % count(self::COLORS)],
                        'tactics' => $this->randomTactics($rng),
                        'arena_capacity' => \App\Game\Economy::START_CAPACITY[$def['tier']],
                        'fans' => \App\Game\Economy::START_CAPACITY[$def['tier']],
                    ]);
                    $this->finance->book($model, \App\Game\Economy::START_BALANCE[$def['tier']], 'start', 'Starting money');
                    foreach ($factory->squad($strength) as $row) {
                        $player = Player::create(['club_id' => $model->id, ...$row]);
                        $player->update(['wage' => $player->fairWage(), 'contract_until' => $rng->int(1, 3)]);
                    }
                }
            }

            self::topUpFreeAgents($factory, $rng, 1);

            $season = Season::create(['number' => 1, 'status' => 'active']);
            $this->scheduler->schedule($season, $firstDay);

            return $season;
        });
    }

    /** Keeps the free-agent pool at FREE_AGENTS players of mixed positions and ages. */
    public static function topUpFreeAgents(PlayerFactory $factory, Rng $rng, int $seasonNumber): void
    {
        $missing = self::FREE_AGENTS - Player::whereNull('club_id')->count();
        $positions = ['GK', 'LW', 'LB', 'CB', 'RB', 'RW', 'PV', 'LB', 'RB', 'PV'];
        $numbers = [];
        $names = Player::whereNull('club_id')->get()->map(fn ($p) => $p->name)->all();
        for ($i = 0; $i < $missing; $i++) {
            $row = $factory->player($rng->pick($positions), $rng->range(50, 62), $numbers, $names, 1, $rng->int(19, 31));
            $numbers = [];
            $player = Player::create(['club_id' => null, ...$row]);
            $player->update(['wage' => $player->fairWage(), 'contract_until' => $seasonNumber]);
        }
    }

    /** AI clubs get varied, sensible tactics so leagues have different styles. */
    public function randomTactics(Rng $rng): array
    {
        return [
            'defense' => $rng->weighted([['6-0', 5], ['5-1', 3], ['3-2-1', 2]]),
            'tempo' => $rng->weighted([['slow', 2], ['normal', 5], ['fast', 3]]),
            'focus' => $rng->weighted([['balanced', 4], ['wings', 2], ['backs', 2], ['pivot', 2]]),
            'sevenVsSix' => $rng->weighted([['never', 2], ['whenShort', 5], ['whenBehind', 3]]),
            'rotation' => $rng->weighted([['low', 2], ['normal', 5], ['high', 3]]),
        ];
    }
}
