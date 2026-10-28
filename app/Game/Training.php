<?php

namespace App\Game;

use App\Game\Engine\Rng;
use App\Models\Club;
use App\Models\Fixture;
use App\Models\News;
use App\Models\Player;

/**
 * What happens to a squad between matches: tiredness, injuries and training.
 * Runs once per club after each played match. Everything is seeded from the
 * fixture, so a replayed match day gives the same outcome.
 */
final class Training
{
    /** Training focus per player: which skills improve. */
    public const FOCUS = [
        'balanced' => ['label' => 'Balanced', 'skills' => ['shooting', 'passing', 'technique', 'speed', 'stamina', 'jumping', 'defense', 'composure']],
        'shooting' => ['label' => 'Shooting', 'skills' => ['shooting', 'jumping', 'composure']],
        'playmaking' => ['label' => 'Passing and technique', 'skills' => ['passing', 'technique', 'composure']],
        'athletics' => ['label' => 'Speed and stamina', 'skills' => ['speed', 'stamina', 'jumping']],
        'defense' => ['label' => 'Defense', 'skills' => ['defense', 'jumping', 'stamina']],
        'goalkeeping' => ['label' => 'Goalkeeping', 'skills' => ['reflexes', 'positioning', 'seven_meter']],
    ];

    /** Club-wide intensity: how fast players improve, how fast they recover, how often they get hurt. */
    public const INTENSITY = [
        'light' => ['label' => 'Light', 'xp' => 0.6, 'recovery' => 26, 'injury' => 0.6],
        'normal' => ['label' => 'Normal', 'xp' => 1.0, 'recovery' => 20, 'injury' => 1.0],
        'hard' => ['label' => 'Hard', 'xp' => 1.5, 'recovery' => 14, 'injury' => 1.7],
    ];

    /** Training points per match day before intensity, by age. 100 points = +1 to one skill. */
    public static function baseXp(int $age): int
    {
        return match (true) {
            $age <= 20 => 60,
            $age <= 23 => 42,
            $age <= 26 => 26,
            $age <= 29 => 12,
            default => 0,
        };
    }

    /** Skills stop improving here: a little above his hidden potential. */
    public static function ceiling(Player $player): int
    {
        return min(96, (int) $player->potential + 6);
    }

    /** Injury chance per match for a player who played, before intensity and tiredness. */
    public const INJURY_BASE = 0.006;

    public const INJURIES = [
        'Ankle sprain', 'Hamstring strain', 'Knee bruise', 'Shoulder strain', 'Finger fracture',
        'Calf strain', 'Back spasm', 'Groin strain', 'Wrist sprain', 'Concussion',
    ];

    /**
     * @param  array<string, array<string, int>>  $playerStats  engine output, keyed 'p{id}'
     */
    public function afterMatch(Club $club, Fixture $fixture, array $playerStats): void
    {
        $rng = new Rng(((int) $fixture->seed ^ ((int) $club->id * 2654435761)) & 0x7FFFFFFF);
        $intensity = self::INTENSITY[$club->training_intensity] ?? self::INTENSITY['normal'];
        $managed = $club->isManaged();

        foreach ($club->players()->orderBy('id')->get() as $player) {
            $stats = $playerStats['p'.$player->id] ?? null;
            $played = $stats && $stats['secondsPlayed'] > 0;
            $changes = [];

            // Injured players count down; they neither play nor train.
            if ($player->isInjured()) {
                $left = $player->injured_days - 1;
                $changes = ['injured_days' => $left];
                if ($left === 0) {
                    $changes += ['injury' => null, 'fitness' => 70];
                    if ($managed) {
                        $this->news($club, 'recovered', "{$player->name} is fit again after his ".strtolower((string) $player->injury).'.');
                    }
                }
                $player->update($changes);

                continue;
            }

            // Tiredness carries over: minutes played wear him down (less with good stamina),
            // and every day brings some recovery. A player used in full every day slowly tires,
            // so rotating the squad pays off.
            $end = $played ? ($stats['energyEnd'] ?? 100) : 100;
            $load = $played ? $stats['secondsPlayed'] / 3600 * 40 * (1.3 - $player->stamina / 100) : 0;
            if ($player->isGoalkeeper()) {
                $load /= 2; // keepers run far less than court players
            }
            $recovery = $intensity['recovery'] + ($played ? 0 : 10);
            $fitness = (int) round(max(40, min(100, $player->fitness - $load + $recovery)));
            $changes['fitness'] = $fitness;

            // Injury: more likely when tired and when training hard.
            if ($played) {
                $tired = 1 + (100 - $end) / 100;
                if ($rng->chance(self::INJURY_BASE * $intensity['injury'] * $tired)) {
                    $days = $rng->weighted([[$rng->int(1, 3), 6], [$rng->int(4, 8), 3], [$rng->int(9, 20), 1]]);
                    $injury = $rng->pick(self::INJURIES);
                    $changes += ['injured_days' => $days, 'injury' => $injury, 'fitness' => 60];
                    $player->update($changes);
                    if ($managed) {
                        $this->news($club, 'injury', "{$player->name} is injured ({$injury}) and out for {$days} match ".($days === 1 ? 'day' : 'days').'.');
                    }

                    continue;
                }
            }

            // Training: points by age and intensity, a bonus for match minutes.
            $xp = self::baseXp((int) $player->age) * $intensity['xp'] * ($played ? 1.2 : 1.0);
            $total = (int) $player->training_xp + (int) round($xp);
            $focus = $player->isGoalkeeper() ? 'goalkeeping' : ($player->training_focus === 'goalkeeping' ? 'balanced' : $player->training_focus);
            $skills = (self::FOCUS[$focus] ?? self::FOCUS['balanced'])['skills'];
            $gained = [];
            while ($total >= 100) {
                $total -= 100;
                // The weakest skill in the focus improves first, up to his ceiling.
                $open = array_filter($skills, fn ($s) => ($changes[$s] ?? $player->{$s}) < self::ceiling($player));
                if (! $open) {
                    $total = 0;
                    break;
                }
                usort($open, fn ($a, $b) => ($changes[$a] ?? $player->{$a}) <=> ($changes[$b] ?? $player->{$b}));
                $skill = $rng->chance(0.7) ? $open[0] : $rng->pick($open);
                $changes[$skill] = ($changes[$skill] ?? $player->{$skill}) + 1;
                $gained[] = $skill;
            }
            $changes['training_xp'] = $total;
            $player->update($changes);

            if ($managed && $gained) {
                $list = implode(', ', array_map(fn ($s) => str_replace('_', '-', $s === 'seven_meter' ? '7-metre saves' : $s), $gained));
                $this->news($club, 'training', "{$player->name} improved in training: {$list} +1.");
            }
        }
    }

    private function news(Club $club, string $kind, string $message): void
    {
        News::create(['club_id' => $club->id, 'kind' => $kind, 'message' => $message]);
    }
}
