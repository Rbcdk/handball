<?php

namespace App\Game;

use App\Game\Engine\MatchEngine;
use App\Models\Club;
use App\Models\Player;
use App\Models\TransferListing;
use Illuminate\Support\Collection;

/**
 * Computer-managed clubs use the transfer market like a careful human: they sell
 * spare or ageing players, buy where their starting seven is weakest when they can
 * afford it, and fill gaps with free agents. Runs once an hour from the scheduler.
 */
final class AiMarket
{
    /** Chance per hourly run that a given AI club looks at the market at all (spreads activity out). */
    public const ACTIVITY = 0.12;

    /** AI clubs never spend more than this share of their balance on one player. */
    public const MAX_SHARE = 0.4;

    /** AI clubs keep a squad of 16 by signing free agents. */
    public const SQUAD_TARGET = 16;

    public function __construct(private TransferMarket $market)
    {
    }

    /** @return array{listed: int, bought: int, bids: int, signed: int} */
    public function tick(?callable $chance = null): array
    {
        $chance ??= fn (float $p) => mt_rand() / mt_getrandmax() < $p;
        $done = ['listed' => 0, 'bought' => 0, 'bids' => 0, 'signed' => 0];

        foreach (Club::whereNull('user_id')->with('league')->get() as $club) {
            $size = $club->players()->count();
            if ($size < self::SQUAD_TARGET) {
                $done['signed'] += $this->fillWithFreeAgents($club, self::SQUAD_TARGET - $size);
                if ($size < Economy::SQUAD_MIN) {
                    continue;
                }
            }
            if (! $chance(self::ACTIVITY)) {
                continue;
            }
            $done['listed'] += $this->sell($club, $chance);
            [$bought, $bids] = $this->buy($club, $chance);
            $done['bought'] += $bought;
            $done['bids'] += $bids;
        }

        return $done;
    }

    private function sell(Club $club, callable $chance): int
    {
        if ($club->listings()->open()->count() >= 2) {
            return 0;
        }
        $players = $club->players()->get();
        $starters = $club->startingLineup();
        $bench = $players->reject(fn (Player $p) => isset($starters[$p->id]) || $p->listing()->exists());
        $needsMoney = $club->balance < 0;
        $tooMany = $players->count() > 17;
        if (! $needsMoney && ! $tooMany && ! $chance(0.25)) {
            return 0;
        }
        if ($players->count() - $club->listings()->open()->count() <= Economy::SQUAD_MIN) {
            return 0;
        }

        // Short of money: sell the most valuable bench player. Otherwise the oldest.
        // Short of money: the most valuable bench player. Otherwise usually the oldest,
        // sometimes any bench player, so the market isn't only veterans.
        $player = match (true) {
            $needsMoney => $bench->sortByDesc(fn (Player $p) => $p->marketValue())->first(),
            $chance(0.4) => $bench->filter(fn (Player $p) => ! $p->isGoalkeeper())->shuffle()->first(),
            default => $bench->sortByDesc(fn (Player $p) => $p->age * 100 - $p->rating())->first(),
        };
        if (! $player || $player->isGoalkeeper() && $players->where('position', 'GK')->count() <= 2) {
            return 0;
        }

        $value = $player->marketValue();
        $auction = $chance(0.5);
        $price = $auction ? (int) round($value * 0.7, -3) : (int) round($value * (1.0 + mt_rand(0, 30) / 100), -3);
        [$min, $max] = Economy::priceRange($value);
        try {
            $this->market->list($club, $player, $auction ? 'auction' : 'fixed', max($min, min($max, $price)));

            return 1;
        } catch (TransferException) {
            return 0;
        }
    }

    /** @return array{0: int, 1: int} bought, bids */
    private function buy(Club $club, callable $chance): array
    {
        $tier = (int) $club->league->tier;
        $reserve = $tier === 1 ? 800_000 : 300_000;
        $budget = (int) min(($club->balance - $reserve), $club->balance * self::MAX_SHARE);
        if ($budget < 50_000 || $club->players()->count() >= Economy::SQUAD_MAX - 1) {
            return [0, 0];
        }

        // The three weakest spots in the starting seven, rated the way the engine rates them.
        $team = $club->toEngineTeam();
        $lineup = MatchEngine::startingLineup($team['players']);
        $byId = collect($team['players'])->keyBy('id');
        $weak = collect($lineup['slots'])
            ->map(fn ($id, $slot) => MatchEngine::slotRating($byId[$id], $slot))
            ->sort()->take(3);

        $candidates = TransferListing::open()
            ->where('seller_club_id', '!=', $club->id)
            ->with(['player', 'highestBid'])
            ->get()
            ->filter(function (TransferListing $l) use ($weak) {
                if (! $l->player || ! $weak->has($l->player->position)) {
                    return false;
                }

                return MatchEngine::slotRating($l->player->toEngineArray(), $l->player->position) > $weak[$l->player->position] + 1.5;
            });

        foreach ($this->byValue($candidates) as $l) {
            $value = $l->player->marketValue();
            try {
                if (! $l->isAuction()) {
                    if ($l->price <= $budget && $l->price <= $value * 1.35) {
                        $this->market->buyNow($l, $club);

                        return [1, 0];
                    }
                } else {
                    $bid = Economy::minimumBid($l->price, $l->highestBid?->amount);
                    $leading = $l->highestBid && (int) $l->highestBid->club_id === (int) $club->id;
                    if (! $leading && $bid <= $budget && $bid <= $value * 1.3 && $chance(0.6)) {
                        $this->market->bid($l, $club, $bid);

                        return [0, 1];
                    }
                }
            } catch (TransferException) {
                continue;
            }
        }

        return [0, 0];
    }

    private function fillWithFreeAgents(Club $club, int $needed): int
    {
        $signed = 0;
        $have = $club->players()->pluck('position')->countBy();
        $free = Player::whereNull('club_id')->get()
            ->sortByDesc(fn (Player $p) => ($have[$p->position] ?? 0) < 2 ? 1000 + $p->rating() : $p->rating());
        foreach ($free as $player) {
            if ($signed >= $needed) {
                break;
            }
            try {
                $this->market->signFreeAgent($player, $club, 2);
                $signed++;
            } catch (TransferException) {
                continue;
            }
        }

        return $signed;
    }

    /** Best value first: most rating per krone. */
    private function byValue(Collection $listings): Collection
    {
        return $listings->sortByDesc(fn (TransferListing $l) => $l->player->rating() / max(1, $l->isAuction()
            ? Economy::minimumBid($l->price, $l->highestBid?->amount) : $l->price));
    }
}
