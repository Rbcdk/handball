<?php

namespace App\Console\Commands;

use App\Game\Economy;
use App\Game\Engine\Rng;
use App\Game\Finance;
use App\Game\World\NameGenerator;
use App\Game\World\PlayerFactory;
use App\Game\World\WorldBuilder;
use App\Models\Club;
use App\Models\Player;
use App\Models\Season;
use App\Models\Transaction;
use Illuminate\Console\Command;

class SetupEconomy extends Command
{
    protected $signature = 'game:setup-economy';

    protected $description = 'One-off upgrade for a world created before money existed: starting money, wages, contracts and free agents';

    public function handle(Finance $finance): int
    {
        if (Transaction::exists()) {
            $this->info('This world already has an economy. Nothing to do.');

            return self::SUCCESS;
        }
        $season = Season::current()?->number ?? 1;
        $rng = new Rng(random_int(1, 2_000_000_000));

        foreach (Club::with('league')->get() as $club) {
            $tier = (int) $club->league->tier;
            $club->update(['arena_capacity' => Economy::START_CAPACITY[$tier], 'fans' => Economy::START_CAPACITY[$tier]]);
            $finance->book($club, Economy::START_BALANCE[$tier], 'start', 'Starting money');
        }
        Player::whereNotNull('club_id')->each(function (Player $p) use ($season, $rng) {
            $p->update(['wage' => $p->fairWage(), 'contract_until' => $season + $rng->int(0, 2)]);
        });
        WorldBuilder::topUpFreeAgents(new PlayerFactory($rng, new NameGenerator($rng)), $rng, $season);

        $this->info('Done: '.Club::count().' clubs have starting money, '.Player::whereNotNull('club_id')->count().' players have wages and contracts, '.Player::whereNull('club_id')->count().' free agents are on the market.');

        return self::SUCCESS;
    }
}
