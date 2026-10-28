<?php

namespace App\Game;

use App\Models\Club;
use App\Models\Fixture;
use App\Models\League;
use App\Models\Season;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/** Every change to a club's money goes through here, so the transaction log always adds up. */
final class Finance
{
    public function __construct(private Standings $standings)
    {
    }

    /**
     * Adds (or with a negative amount, takes) money and logs it.
     * Call inside a transaction when other rows change at the same time.
     */
    public function book(Club $club, int $amount, string $type, string $description, ?Season $season = null): Transaction
    {
        return DB::transaction(function () use ($club, $amount, $type, $description, $season) {
            $fresh = Club::lockForUpdate()->findOrFail($club->id);
            $fresh->balance += $amount;
            $fresh->save();
            $club->balance = $fresh->balance;

            return Transaction::create([
                'club_id' => $club->id,
                'season_id' => ($season ?? Season::current())?->id,
                'amount' => $amount,
                'type' => $type,
                'description' => $description,
                'balance_after' => $fresh->balance,
            ]);
        });
    }

    /** Money for one played fixture: wages for both clubs, sponsor share, tickets for the home club. */
    public function settleMatchDay(Fixture $fixture): void
    {
        $fixture->loadMissing(['home', 'away', 'league', 'season']);
        $league = $fixture->league;
        $tier = (int) $league->tier;
        $rounds = (int) Fixture::where('season_id', $fixture->season_id)->where('league_id', $league->id)->max('round');
        $label = "Round {$fixture->round}";

        // The current table position decides how full the arena is: winning teams draw bigger crowds.
        $table = $this->standings->table($fixture->season, $league);
        $clubs = max(1, $table->count());
        $row = $table->first(fn ($r) => (int) $r->club->id === (int) $fixture->home_club_id);
        $form = ($row && $row->played > 0) ? 1 - ($row->position - 1) / max(1, $clubs - 1) : 0.5;

        $home = $fixture->home;
        $tickets = Economy::ticketIncome($tier, (int) $home->arena_capacity, $form);
        $this->book($home, $tickets, 'tickets', "{$label}: home match against {$fixture->away->name}", $fixture->season);

        foreach ([$fixture->home, $fixture->away] as $club) {
            $this->book($club, Economy::sponsorPerRound($tier, $rounds), 'sponsor', "{$label}: sponsor payment", $fixture->season);
            $wages = $club->wageBill();
            if ($wages > 0) {
                $this->book($club, -$wages, 'wages', "{$label}: wages for {$club->players()->count()} players", $fixture->season);
            }
        }
    }

    /** Prize money by final position, paid when a season ends. */
    public function payPrizes(Season $season): void
    {
        foreach (League::all() as $league) {
            $table = $this->standings->table($season, $league);
            foreach ($table as $row) {
                $amount = Economy::prizeMoney((int) $league->tier, $row->position, $table->count());
                $this->book($row->club, $amount, 'prize', "Season {$season->number}: finished {$row->position}".\App\Support\Ordinal::suffix($row->position)." in {$league->name}", $season);
            }
        }
    }

    /** Income and costs this season, by type. */
    public function seasonSummary(Club $club, ?Season $season): array
    {
        if (! $season) {
            return [];
        }

        return Transaction::where('club_id', $club->id)->where('season_id', $season->id)
            ->selectRaw('type, sum(amount) as total')->groupBy('type')->pluck('total', 'type')
            ->map(fn ($v) => (int) $v)->all();
    }
}
