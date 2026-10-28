<?php

namespace App\Game;

use App\Models\Club;
use App\Models\Fixture;
use App\Models\News;
use App\Models\Season;
use DomainException;

/**
 * The arena and the fans: fans come and go with results, seats are built in a few match days.
 * Money moves through Finance so every payment shows up in the club's accounts.
 */
final class Arena
{
    public function __construct(private Finance $finance)
    {
    }

    /** Order more seats. Paid at once; ready after a few match days. */
    public function expand(Club $club, int $seats): void
    {
        if (! isset(Economy::EXPANSIONS[$seats])) {
            throw new DomainException('Choose one of the expansions on offer.');
        }
        if ($club->arena_expansion) {
            throw new DomainException('The builders are already working on your arena. Wait until they finish.');
        }
        if ($club->arena_capacity + $seats > Economy::MAX_CAPACITY) {
            throw new DomainException('The arena can\'t grow beyond '.number_format(Economy::MAX_CAPACITY, 0, ',', '.').' seats.');
        }
        $cost = Economy::expansionCost((int) $club->arena_capacity, $seats);
        if ($club->balance < $cost) {
            throw new DomainException('You don\'t have enough money for this expansion.');
        }

        $this->finance->book($club, -$cost, 'arena_build', 'Arena: '.number_format($seats, 0, ',', '.').' new seats ordered');
        $club->update(['arena_expansion' => $seats, 'arena_build_left' => Economy::EXPANSIONS[$seats]]);
    }

    /** After a played match: fans react to the result, builders make progress. */
    public function afterMatch(Fixture $fixture): void
    {
        foreach ([$fixture->home, $fixture->away] as $club) {
            $club->refresh();
            $club->fans = Economy::fansAfter((int) $club->fans, $fixture->resultFor($club));
            if ($club->arena_expansion) {
                $club->arena_build_left = max(0, (int) $club->arena_build_left - 1);
                if ($club->arena_build_left === 0) {
                    $club->arena_capacity += $club->arena_expansion;
                    News::create(['club_id' => $club->id, 'kind' => 'arena', 'message' => number_format($club->arena_expansion, 0, ',', '.').' new seats are ready. The arena now holds '.number_format($club->arena_capacity, 0, ',', '.').'.']);
                    $club->arena_expansion = null;
                    $club->arena_build_left = null;
                }
            }
            $club->save();
        }
    }

    /** Season end: promoted clubs win fans, relegated clubs lose some. AI clubs expand when they sell out. */
    public function endOfSeason(Season $season, array $promotedIds, array $relegatedIds): void
    {
        foreach (Club::whereIn('id', $promotedIds)->get() as $club) {
            $club->update(['fans' => (int) round($club->fans * (1 + Economy::FAN_PROMOTED))]);
        }
        foreach (Club::whereIn('id', $relegatedIds)->get() as $club) {
            $club->update(['fans' => max(Economy::MIN_FANS, (int) round($club->fans * (1 + Economy::FAN_RELEGATED)))]);
        }

        foreach (Club::whereNull('user_id')->whereNull('arena_expansion')->get() as $club) {
            $home = Fixture::where('season_id', $season->id)->where('home_club_id', $club->id)->whereNotNull('attendance');
            $games = $home->count();
            $soldOut = (clone $home)->where('attendance', '>=', $club->arena_capacity)->count();
            if ($games === 0 || $soldOut < $games / 2) {
                continue;
            }
            foreach ([1000, 500, 250] as $seats) {
                $cost = Economy::expansionCost((int) $club->arena_capacity, $seats);
                if ($club->balance >= 2 * $cost && $club->arena_capacity + $seats <= Economy::MAX_CAPACITY) {
                    $this->expand($club, $seats);
                    break;
                }
            }
        }
    }

    /** What the next home match would bring in, with the club's current table position. */
    public static function forecast(Club $club, float $form, int $tier): array
    {
        $attendance = Economy::attendance((int) $club->fans, (int) $club->arena_capacity, $form);

        return [
            'attendance' => $attendance,
            'income' => Economy::ticketIncome($tier, $attendance),
            'soldOut' => $attendance >= $club->arena_capacity,
            'wanting' => (int) round($club->fans * (0.55 + 0.35 * $form)),
        ];
    }
}
