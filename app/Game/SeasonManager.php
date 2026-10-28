<?php

namespace App\Game;

use App\Game\Engine\Rng;
use App\Game\World\FixtureScheduler;
use App\Game\World\NameGenerator;
use App\Game\World\PlayerFactory;
use App\Models\Club;
use App\Models\Fixture;
use App\Models\League;
use App\Models\Player;
use App\Models\Season;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Ends a season once every fixture is played: promotion and relegation,
 * player ageing and development, retirements, and next season's fixtures.
 */
final class SeasonManager
{
    public function __construct(private Standings $standings, private FixtureScheduler $scheduler, private Finance $finance)
    {
    }

    /** Returns the new season if one was started. */
    public function advanceIfComplete(): ?Season
    {
        $season = Season::current();
        if (! $season) {
            return null;
        }
        $remaining = Fixture::where('season_id', $season->id)->upcoming()->count();
        $total = Fixture::where('season_id', $season->id)->count();
        if ($total === 0 || $remaining > 0) {
            return null;
        }

        return DB::transaction(function () use ($season) {
            $this->finance->payPrizes($season);
            $this->promoteAndRelegate($season);
            $this->developPlayers();
            $this->endContracts($season);
            // Free agents age too; the oldest stop playing.
            Player::whereNull('club_id')->where('age', '>=', 33)->delete();
            Player::whereNull('club_id')->increment('age');
            $rng = new Rng(random_int(1, 2_000_000_000));
            \App\Game\World\WorldBuilder::topUpFreeAgents(new PlayerFactory($rng, new NameGenerator($rng)), $rng, $season->number + 1);

            $season->update(['status' => 'finished', 'finished_at' => now()]);
            $next = Season::create(['number' => $season->number + 1, 'status' => 'active']);

            $firstDay = CarbonImmutable::now(config('game.timezone'))->addDays(config('game.off_season_days'));
            $this->scheduler->schedule($next, $firstDay);

            return $next;
        });
    }

    private function promoteAndRelegate(Season $season): void
    {
        $top = League::where('tier', 1)->first();
        $lower = League::where('tier', 2)->orderBy('sort')->get();
        if (! $top || $lower->isEmpty()) {
            return;
        }
        $spots = config('game.promotion_spots');

        $topTable = $this->standings->table($season, $top);
        $relegated = $topTable->slice(-$spots * $lower->count())->values();

        foreach ($lower as $i => $league) {
            $table = $this->standings->table($season, $league);
            foreach ($table->take($spots) as $row) {
                $row->club->update(['league_id' => $top->id]);
            }
            // Relegated clubs are spread over the second-tier leagues.
            foreach ($relegated->slice($i * $spots, $spots) as $row) {
                $row->club->update(['league_id' => $league->id]);
            }
        }
    }

    /**
     * Season end: everyone gets a year older. Young players already improved through
     * training during the season; here players over 30 slow down, 36-year-olds retire,
     * and the off-season heals injuries and tiredness.
     */
    private function developPlayers(): void
    {
        $rng = new Rng(random_int(1, 2_000_000_000));
        $factory = new PlayerFactory($rng, new NameGenerator($rng));

        // Fix the list first, so youth players created below are not aged in the same pass.
        $ids = Player::whereNotNull('club_id')->pluck('id');
        $ids->chunk(500)->each(function ($chunk) use ($rng, $factory) {
            $players = Player::whereIn('id', $chunk)->with('club')->get();
            foreach ($players as $player) {
                $age = $player->age + 1;

                if ($age >= 36) {
                    $this->replaceWithYouth($player, $factory, $rng);

                    continue;
                }

                $changes = ['age' => $age, 'fitness' => 100, 'injured_days' => 0, 'injury' => null];
                if ($age >= 31) {
                    $skills = $player->isGoalkeeper()
                        ? ['reflexes', 'positioning', 'seven_meter', 'stamina', 'composure']
                        : ['shooting', 'passing', 'technique', 'speed', 'stamina', 'jumping', 'defense', 'composure'];
                    foreach ($skills as $attr) {
                        $drop = in_array($attr, ['speed', 'stamina', 'jumping'], true) ? $rng->int(1, 3) : $rng->int(0, 1);
                        $changes[$attr] = max(20, $player->{$attr} - $drop);
                    }
                }
                $player->update($changes);
            }
        });
    }

    private function replaceWithYouth(Player $player, PlayerFactory $factory, Rng $rng): void
    {
        $club = $player->club;
        $numbers = $club->players()->where('id', '!=', $player->id)->pluck('number')->all();
        $names = $club->players()->get()->map(fn ($p) => $p->name)->all();
        $avg = $club->players()->get()->avg(fn (Player $p) => $p->rating()) ?: 55;
        $row = $factory->player($player->position, $avg - 8, $numbers, $names, $player->position === 'GK' ? 2 : 1, $rng->int(18, 19));
        $player->delete();
        $youth = Player::create(['club_id' => $club->id, ...$row]);
        $youth->update(['wage' => $youth->fairWage(), 'contract_until' => (Season::current()?->number ?? 1) + 3]);
    }

    /**
     * Contracts that run out with this season. Computer clubs renew most of them;
     * players at a manager's club leave as free agents unless the manager extended them,
     * except that a club never drops below the minimum squad (the best are then kept for a season).
     */
    private function endContracts(Season $season): void
    {
        $expiring = Player::whereNotNull('club_id')->where('contract_until', '<=', $season->number)->with('club')->get();
        foreach ($expiring->groupBy('club_id') as $players) {
            $club = $players->first()->club;
            if (! $club->isManaged()) {
                foreach ($players as $p) {
                    if ($p->age >= 34 && random_int(0, 1) === 1) {
                        $this->letGo($p, $season);
                    } else {
                        $p->update(['wage' => $p->fairWage(), 'contract_until' => $season->number + random_int(1, 3)]);
                    }
                }

                continue;
            }
            $keepAtLeast = \App\Game\Economy::SQUAD_MIN;
            $staying = $club->players()->count() - $players->count();
            foreach ($players->sortByDesc(fn (Player $p) => $p->rating()) as $p) {
                if ($staying < $keepAtLeast) {
                    $p->update(['contract_until' => $season->number + 1]);
                    $staying++;
                } else {
                    $this->letGo($p, $season);
                }
            }
        }
    }

    private function letGo(Player $player, Season $season): void
    {
        \App\Models\TransferListing::where('player_id', $player->id)->where('status', 'open')
            ->update(['status' => 'cancelled', 'closed_at' => now()]);
        \App\Models\Transfer::create(['player_id' => $player->id, 'season_id' => $season->id, 'from_club_id' => $player->club_id, 'fee' => 0, 'kind' => 'contract_end']);
        $player->update(['club_id' => null]);
    }
}
