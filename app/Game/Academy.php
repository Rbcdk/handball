<?php

namespace App\Game;

use App\Game\Engine\Rng;
use App\Game\World\NameGenerator;
use App\Game\World\PlayerFactory;
use App\Models\Club;
use App\Models\Fixture;
use App\Models\News;
use App\Models\Player;
use App\Models\Prospect;
use App\Models\Season;
use DomainException;

/**
 * The youth academy of a managed club. Each season it brings in a few 16-17 year olds;
 * they train every match day and can be promoted to the first team before they turn 20.
 * A better academy brings more and better prospects, trains them faster and judges their
 * talent more accurately, but costs more to run.
 */
final class Academy
{
    /**
     * intake: prospects per season; skill: their average level on arrival; headroom: how far
     * above that their potential lies on average; xp: training speed; cost: price of upgrading
     * to this level; upkeep: cost per match day; eye: how far off the talent stars can be.
     */
    public const LEVELS = [
        1 => ['label' => 'Basic', 'intake' => 2, 'skill' => 44, 'headroom' => 14, 'xp' => 1.0, 'cost' => 0, 'upkeep' => 1_000, 'eye' => 8],
        2 => ['label' => 'Local', 'intake' => 2, 'skill' => 47, 'headroom' => 16, 'xp' => 1.2, 'cost' => 300_000, 'upkeep' => 3_000, 'eye' => 6],
        3 => ['label' => 'Regional', 'intake' => 3, 'skill' => 50, 'headroom' => 18, 'xp' => 1.4, 'cost' => 700_000, 'upkeep' => 5_000, 'eye' => 4.5],
        4 => ['label' => 'National', 'intake' => 3, 'skill' => 53, 'headroom' => 20, 'xp' => 1.6, 'cost' => 1_300_000, 'upkeep' => 7_500, 'eye' => 3],
        5 => ['label' => 'Elite', 'intake' => 4, 'skill' => 56, 'headroom' => 22, 'xp' => 1.8, 'cost' => 2_000_000, 'upkeep' => 10_000, 'eye' => 1.5],
    ];

    public const MAX_PROSPECTS = 8;

    /** Prospects leave the academy when they turn this old. */
    public const LEAVE_AGE = 20;

    public function __construct(private Finance $finance)
    {
    }

    /** New prospects for this season, once per season. */
    public function intake(Club $club, Season $season): int
    {
        if ($club->academy_intake_season !== null && $club->academy_intake_season >= $season->number) {
            return 0;
        }
        $level = self::LEVELS[$club->academy_level] ?? self::LEVELS[1];
        $room = self::MAX_PROSPECTS - $club->prospects()->count();
        $count = max(0, min($level['intake'], $room));

        $rng = new Rng(random_int(1, 2_000_000_000));
        $factory = new PlayerFactory($rng, $names = new NameGenerator($rng));
        $taken = Player::where('club_id', $club->id)->get()->map->name->merge($club->prospects()->get()->map->name)->all();
        $made = [];
        for ($i = 0; $i < $count; $i++) {
            $pos = $rng->weighted([['GK', 1], ['LW', 1], ['RW', 1], ['LB', 1.3], ['RB', 1.3], ['CB', 1.2], ['PV', 1]]);
            $skill = $level['skill'] + $rng->normal(0, 4);
            do {
                [$first, $last] = $names->playerName();
            } while (in_array("$first $last", $taken, true));
            $taken[] = "$first $last";
            $made[] = Prospect::create([
                'club_id' => $club->id,
                'first_name' => $first,
                'last_name' => $last,
                'position' => $pos,
                'age' => $rng->int(16, 17),
                ...$factory->attributes($pos, $skill),
                'potential' => (int) round(max(35, min(94, $skill + $level['headroom'] + $rng->normal(0, 6)))),
                'face' => $rng->int(10_000_000, 99_999_999),
                'joined_season' => $season->number,
            ]);
        }
        $club->update(['academy_intake_season' => $season->number]);
        if ($made) {
            $this->news($club, count($made).' new '.(count($made) === 1 ? 'prospect has' : 'prospects have').' joined your academy: '.implode(', ', array_map(fn ($p) => $p->name, $made)).'.');
        }

        return count($made);
    }

    /** Every match day: prospects train, and the academy costs its upkeep. */
    public function afterMatch(Club $club, Fixture $fixture): void
    {
        $level = self::LEVELS[$club->academy_level] ?? self::LEVELS[1];
        $this->finance->book($club, -$level['upkeep'], 'academy', "Round {$fixture->round}: youth academy", $fixture->season);

        $rng = new Rng(((int) $fixture->seed ^ ((int) $club->id * 40503)) & 0x7FFFFFFF);
        foreach ($club->prospects()->orderBy('id')->get() as $p) {
            $total = (int) $p->training_xp + (int) round(Training::baseXp((int) $p->age) * $level['xp']);
            $skills = $p->isGoalkeeper() ? Training::FOCUS['goalkeeping']['skills'] : Training::FOCUS['balanced']['skills'];
            $ceiling = min(96, $p->potential + 6);
            while ($total >= 100) {
                $total -= 100;
                $open = array_values(array_filter($skills, fn ($s) => $p->{$s} < $ceiling));
                if (! $open) {
                    $total = 0;
                    break;
                }
                usort($open, fn ($a, $b) => $p->{$a} <=> $p->{$b});
                $skill = $rng->chance(0.7) ? $open[0] : $rng->pick($open);
                $p->{$skill}++;
            }
            $p->training_xp = $total;
            $p->save();
        }
    }

    /** Season end: prospects get a year older; those who turn 20 leave. */
    public function endOfSeason(): void
    {
        foreach (Prospect::with('club')->get() as $p) {
            if ($p->age + 1 >= self::LEAVE_AGE) {
                $this->news($p->club, "{$p->name} turned ".self::LEAVE_AGE.' and left the academy without a first-team contract.');
                $p->delete();
            } else {
                $p->update(['age' => $p->age + 1]);
            }
        }
    }

    /** Give a prospect a first-team contract. */
    public function promote(Club $club, Prospect $p): Player
    {
        $this->assertOwn($club, $p);
        if ($club->players()->count() >= Economy::SQUAD_MAX) {
            throw new DomainException('Your squad is full ('.Economy::SQUAD_MAX.' players). Sell or release a player first.');
        }
        $numbers = $club->players()->pluck('number')->all();
        $number = $p->isGoalkeeper() && ! in_array(12, $numbers, true) ? 12 : null;
        for ($n = 2; $number === null && $n <= 99; $n++) {
            if (! in_array($n, $numbers, true)) {
                $number = $n;
            }
        }
        $player = Player::create([
            'club_id' => $club->id,
            'first_name' => $p->first_name,
            'last_name' => $p->last_name,
            'number' => $number,
            'position' => $p->position,
            'age' => $p->age,
            ...$p->only(Prospect::SKILLS),
            'potential' => $p->potential,
            'training_xp' => $p->training_xp,
            'face' => $p->face,
            'contract_until' => (Season::current()?->number ?? 1) + 3,
        ]);
        $player->update(['wage' => $player->fairWage()]);
        $p->delete();
        $this->news($club, "{$player->name} was promoted from the academy and signed his first contract.");

        return $player;
    }

    public function release(Club $club, Prospect $p): void
    {
        $this->assertOwn($club, $p);
        $p->delete();
    }

    /** Move the academy one level up (paid) or down (free, saves upkeep). */
    public function changeLevel(Club $club, int $level): void
    {
        if (! isset(self::LEVELS[$level]) || abs($level - $club->academy_level) !== 1) {
            throw new DomainException('The academy can only move one level at a time.');
        }
        if ($level > $club->academy_level) {
            $cost = self::LEVELS[$level]['cost'];
            if ($club->balance < $cost) {
                throw new DomainException('You don\'t have enough money for this upgrade.');
            }
            $this->finance->book($club, -$cost, 'academy_build', 'Youth academy upgraded to '.self::LEVELS[$level]['label']);
        }
        $club->update(['academy_level' => $level]);
    }

    private function assertOwn(Club $club, Prospect $p): void
    {
        if ((int) $p->club_id !== (int) $club->id) {
            throw new DomainException('That prospect is not in your academy.');
        }
    }

    private function news(Club $club, string $message): void
    {
        News::create(['club_id' => $club->id, 'kind' => 'academy', 'message' => $message]);
    }
}
