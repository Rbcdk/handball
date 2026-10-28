<?php

namespace App\Game\Engine;

/**
 * Handball match engine (PHP port of the TypeScript prototype engine).
 *
 * The match is simulated possession by possession. Each possession decides
 * who has the ball, the pass sequence and the outcome (goal, save, miss,
 * block, turnover, 7-metre, suspension). Tactics and attributes shift the
 * probabilities. The result is an event log that the 2D viewer replays.
 * The same input and seed always produce the same match.
 *
 * Input team shape:
 *   ['id' => string, 'name' => string, 'short' => string, 'color' => string,
 *    'tactics' => ['defense', 'tempo', 'focus', 'sevenVsSix', 'rotation'],
 *    'players' => [['id', 'name', 'number', 'position', 'age', 'attrs' => [...]], ...]]
 */
final class MatchEngine
{
    public const HALF = 1800;

    public const FULL = 3600;

    public const SLOTS = ['LW', 'LB', 'CB', 'RB', 'RW', 'PV'];

    public const BACKS = ['LB', 'CB', 'RB', 'X7'];

    /** Tunable balance values. Check changes with `php artisan game:calibrate`. */
    public const BALANCE = [
        'duration' => ['slow' => 44, 'normal' => 37, 'fast' => 31],
        'passes' => ['slow' => [5, 8], 'normal' => [4, 6], 'fast' => [3, 5]],
        'fastBreakBase' => ['steal' => 0.42, 'save' => 0.16, 'miss' => 0.14, 'block' => 0.1, 'turnover' => 0.12, 'goal' => 0.06, 'start' => 0, 'emptyNet' => 0],
        'fastBreakTempo' => ['slow' => 0.65, 'normal' => 1, 'fast' => 1.35],
        'turnoverBase' => 0.195,
        'turnoverByDefense' => ['6-0' => 0, '5-1' => 0.015, '3-2-1' => 0.045],
        'turnoverByTempo' => ['slow' => -0.01, 'normal' => 0, 'fast' => 0.025],
        'sevenMBase' => 0.075,
        'sevenMByDefense' => ['6-0' => 0, '5-1' => 0.01, '3-2-1' => 0.02],
        'sevenMByFocus' => ['balanced' => 0, 'wings' => -0.005, 'backs' => 0.005, 'pivot' => 0.01],
        'suspensionBase' => 0.042,
        'suspensionByDefense' => ['6-0' => 1, '5-1' => 1.2, '3-2-1' => 1.55],
        'shotBase' => [
            'wing' => 0.58, 'back9' => 0.45, 'breakthrough' => 0.62, 'pivot' => 0.69, 'fastBreak' => 0.78,
            'secondWave' => 0.66, 'sevenMeter' => 0.76, 'rebound' => 0.55, 'emptyGoal' => 0.86,
        ],
        // How each defensive system changes shot success, by shot type.
        'defenseVsShot' => [
            '6-0' => ['back9' => 0.1, 'breakthrough' => -0.03, 'pivot' => -0.12, 'wing' => 0],
            '5-1' => ['back9' => -0.05, 'breakthrough' => 0.01, 'pivot' => 0.03, 'wing' => 0.01],
            '3-2-1' => ['back9' => -0.07, 'breakthrough' => 0.02, 'pivot' => 0.04, 'wing' => 0.07],
        ],
        'focusQuality' => 0.01,
        'focusAmplify' => 1.8,
        // How each defensive system changes which shots the attack gets.
        'defenseVsChance' => [
            '6-0' => ['back9' => 1.25, 'breakthrough' => 0.8, 'pivot' => 0.65, 'wing' => 1],
            '5-1' => ['back9' => 0.8, 'breakthrough' => 1, 'pivot' => 1.25, 'wing' => 1.05],
            '3-2-1' => ['back9' => 0.7, 'breakthrough' => 1.1, 'pivot' => 1.3, 'wing' => 1.3],
        ],
        'focusVsChance' => [
            'balanced' => [],
            'wings' => ['wing' => 1.8],
            'backs' => ['back9' => 1.6, 'breakthrough' => 1.2],
            'pivot' => ['pivot' => 1.7],
        ],
        'skillWeight' => 0.4,
        'gkWeight' => 0.4,
        'defenseWeight' => 0.12,
        'manAdvantage' => 0.045,
        'rotationThreshold' => ['low' => 40, 'normal' => 58, 'high' => 70],
    ];

    private Rng $rng;

    private float $clock = 0.0;

    /** @var array<string, TeamState> */
    private array $teams = [];

    /** @var array<string, array<string, int>> */
    private array $playerStats = [];

    /**
     * @param  array<string, mixed>  $home
     * @param  array<string, mixed>  $away
     * @return array<string, mixed> MatchResult (same shape as the TypeScript type)
     */
    public function simulate(array $home, array $away, int $seed): array
    {
        $this->rng = new Rng($seed);
        $this->clock = 0.0;
        $this->teams = ['home' => $this->initTeam($home, 'home'), 'away' => $this->initTeam($away, 'away')];
        $this->playerStats = [];
        foreach ([...$home['players'], ...$away['players']] as $p) {
            $this->playerStats[$p['id']] = [
                'goals' => 0, 'shots' => 0, 'assists' => 0, 'sevenMGoals' => 0, 'sevenMShots' => 0, 'turnovers' => 0,
                'steals' => 0, 'blocks' => 0, 'suspensions' => 0, 'saves' => 0, 'shotsFaced' => 0, 'secondsPlayed' => 0,
            ];
        }

        $possessions = [];
        $half = 1;
        $firstAttack = $this->rng->chance(0.5) ? 'home' : 'away';
        $attacking = $firstAttack;
        $prev = ['kind' => 'start', 'stolenBy' => null];
        $halftime = [0, 0];

        while (true) {
            $halfEnd = $half === 1 ? self::HALF : self::FULL;
            if ($this->clock >= $halfEnd) {
                if ($half === 2) {
                    break;
                }
                $halftime = [$this->teams['home']->score, $this->teams['away']->score];
                $half = 2;
                $this->clock = self::HALF;
                $attacking = self::other($firstAttack);
                $prev = ['kind' => 'start', 'stolenBy' => null];
                foreach ($this->teams as $ts) {
                    $ts->gkChangedThisHalf = false;
                    $ts->gkHalfShots = 0;
                    $ts->gkHalfSaves = 0;
                    $ts->halfTimeouts = 0;
                    foreach ($ts->live as $l) {
                        $l->energy = min(100, $l->energy + 25);
                    }
                }

                continue;
            }

            $possessions[] = $this->possession(
                $this->teams[$attacking],
                $this->teams[self::other($attacking)],
                $half,
                $halfEnd,
                $prev,
                count($possessions),
            );
            $attacking = self::other($attacking);
        }

        return [
            'seed' => $seed,
            'home' => $home,
            'away' => $away,
            'possessions' => $possessions,
            'score' => [$this->teams['home']->score, $this->teams['away']->score],
            'halftime' => $halftime,
            'teamStats' => ['home' => $this->teams['home']->stats, 'away' => $this->teams['away']->stats],
            'playerStats' => $this->withEndEnergy(),
            'rightFirstHalf' => $firstAttack,
        ];
    }

    // ------------------------------------------------------------------
    // One possession
    // ------------------------------------------------------------------

    /**
     * @param  array{kind: string, stolenBy: ?string}  $prev  updated by reference for the next possession
     * @return array<string, mixed>
     */
    private function possession(TeamState $att, TeamState $def, int $half, int $halfEnd, array &$prev, int $n): array
    {
        $rng = $this->rng;
        $pre = [];
        $actions = [];
        $tempo = $att->tactic('tempo');

        // Team timeout after conceding three in a row.
        $boost = 0.0;
        if ($att->concededRun >= 3 && $att->timeouts > 0 && $att->halfTimeouts < 2 && $halfEnd - $this->clock > 60) {
            $pre[] = ['t' => 'timeout', 'side' => $att->side];
            $att->timeouts--;
            $att->halfTimeouts++;
            $att->stats['timeouts']++;
            $att->concededRun = 0;
            $boost = 0.04;
        }

        $this->rotate($att, $pre);
        $this->rotate($def, $pre);

        // Possession type
        $kind = 'positional';
        if ($prev['kind'] === 'emptyNet') {
            $kind = 'emptyGoal';
        } else {
            $fb = self::BALANCE['fastBreakBase'][$prev['kind']];
            if ($fb > 0) {
                $speedDiff = ($this->avgSpeed($att) - $this->avgSpeed($def)) / 100;
                $p = $fb * self::BALANCE['fastBreakTempo'][$tempo] * (1 + $speedDiff * 2);
                if ($rng->chance($p)) {
                    $kind = $prev['kind'] === 'goal' ? 'secondWave' : 'fastBreak';
                }
            }
        }

        // Who is on court
        $attackers = $this->fieldOnCourt($att);
        $short = count($attackers) < 6;
        $use7v6 = false;
        if ($kind === 'positional') {
            $mode = $att->tactic('sevenVsSix');
            $use7v6 = $mode === 'always'
                || ($mode !== 'never' && $short)
                || ($mode === 'whenBehind' && $half === 2 && $att->score < $def->score);
        }
        if ($use7v6) {
            $extra = $this->benchFor($att, 'X7', array_values($att->slots))[0] ?? null;
            if ($extra) {
                $attackers[] = ['slot' => 'X7', 'id' => $extra->p['id']];
                $pre[] = ['t' => 'extraAttacker', 'side' => $att->side, 'player' => $extra->p['id']];
            } else {
                $use7v6 = false;
            }
        }
        $defLineup = $this->fieldOnCourt($def);
        $defenders = array_column($defLineup, 'id');
        $defGk = $kind === 'emptyGoal' ? null : $def->gk;
        $attGk = $use7v6 ? null : $att->gk;
        $defense = $def->tactic('defense');
        $focus = $att->tactic('focus');
        // Closures bind by reference: suspensions change $defenders mid-possession.
        $diff = function () use (&$attackers, &$defenders): int {
            return count($attackers) - count($defenders);
        };
        $slotOf = function (string $id) use (&$attackers): ?string {
            foreach ($attackers as $o) {
                if ($o['id'] === $id) {
                    return $o['slot'];
                }
            }

            return null;
        };

        // Duration
        if ($kind === 'positional') {
            $dur = max(14, $rng->normal(self::BALANCE['duration'][$tempo], 7));
        } elseif ($kind === 'fastBreak') {
            $dur = max(4, $rng->normal(8, 2));
        } elseif ($kind === 'secondWave') {
            $dur = max(7, $rng->normal(12, 3));
        } else {
            $dur = max(4, $rng->normal(6, 2));
        }
        $end = min($halfEnd, $this->clock + $dur);
        if ($end - $this->clock < 3) {
            $end = min($halfEnd, $this->clock + 3);
        }

        $defAvg = function () use (&$defenders, $def): float {
            $sum = 0.0;
            foreach ($defenders as $id) {
                $sum += $this->eff($def, $id, 'defense');
            }

            return $sum / max(1, count($defenders));
        };
        $gkSkill = function (string $k) use ($defGk, $def): float {
            if ($defGk === null) {
                return 0.0;
            }
            if ($k === 'sevenMeter') {
                return $this->eff($def, $defGk, 'sevenMeter') * 0.6 + $this->eff($def, $defGk, 'reflexes') * 0.4;
            }

            return $this->eff($def, $defGk, 'reflexes') * 0.6 + $this->eff($def, $defGk, 'positioning') * 0.4;
        };

        $endKind = 'turnover';
        $stolenBy = null;
        $ps = &$this->playerStats;

        // Resolve a shot, push the action and return the result.
        $takeShot = function (string $shooter, string $k) use (&$actions, &$defenders, $att, $def, $defGk, $defense, $focus, $diff, $defAvg, $gkSkill, $slotOf, &$boost, $rng, &$ps): string {
            $slot = $slotOf($shooter);
            $at = $this->shotLocation($k, $slot);
            $B = self::BALANCE;
            $p = $B['shotBase'][$k];
            $p += (($this->shotSkill($att, $shooter, $k, $slot) - 62) / 100) * $B['skillWeight'];
            if ($k === 'emptyGoal') {
                $p = $B['shotBase']['emptyGoal'] + (($this->eff($att, $shooter, 'shooting') - 62) / 100) * 0.2;
            } else {
                $p -= (($gkSkill($k) - 62) / 100) * $B['gkWeight'];
                if ($k !== 'sevenMeter' && $k !== 'fastBreak') {
                    $p -= (($defAvg() - 62) / 100) * $B['defenseWeight'];
                    // Focusing on a zone amplifies the matchup.
                    $focused = ! empty($B['focusVsChance'][$focus][$k]);
                    $p += ($B['defenseVsShot'][$defense][$k] ?? 0) * ($focused ? $B['focusAmplify'] : 1);
                    if ($focused) {
                        $p += $B['focusQuality'];
                    }
                    $p += $diff() * $B['manAdvantage'];
                }
                $p += $boost;
            }
            $p = max(0.1, min(0.95, $p));

            $blocker = null;
            if ($rng->chance($p)) {
                $result = 'goal';
            } else {
                $blockP = $k === 'back9' ? ($defense === '6-0' ? 0.33 : ($defense === '3-2-1' ? 0.2 : 0.27)) : 0;
                if ($blockP && $rng->chance($blockP) && count($defenders)) {
                    $result = 'block';
                    $blocker = $this->pickDefender($def, $defenders, fn ($id) => $this->eff($def, $id, 'jumping') + $this->eff($def, $id, 'defense'));
                    $ps[$blocker]['blocks']++;
                } elseif ($defGk) {
                    $result = $rng->weighted([['save', 0.7], ['miss', 0.2], ['post', 0.1]]);
                } else {
                    $result = $rng->weighted([['miss', 0.7], ['post', 0.3]]);
                }
            }
            if ($result === 'miss') {
                $aim = ($rng->chance(0.5) ? -1 : 1) * $rng->range(1.7, 2.6);
            } elseif ($result === 'post') {
                $aim = $rng->chance(0.5) ? -1.5 : 1.5;
            } else {
                $aim = $rng->range(-1.35, 1.35);
            }
            $action = ['t' => 'shot', 'by' => $shooter, 'kind' => $k, 'at' => $at, 'aim' => round($aim, 3), 'result' => $result, 'gk' => $defGk];
            if ($blocker) {
                $action['blocker'] = $blocker;
            }
            $actions[] = $action;

            $ps[$shooter]['shots']++;
            $att->stats['shots']++;
            if ($k === 'sevenMeter') {
                $ps[$shooter]['sevenMShots']++;
                $att->stats['sevenMShots']++;
            }
            if ($result === 'goal') {
                $ps[$shooter]['goals']++;
                $att->stats['goals']++;
                $att->score++;
                if ($k === 'sevenMeter') {
                    $ps[$shooter]['sevenMGoals']++;
                    $att->stats['sevenMGoals']++;
                }
                if ($k === 'fastBreak' || $k === 'secondWave') {
                    $att->stats['fastBreakGoals']++;
                }
                // Assist: the last pass to the shooter.
                if ($k !== 'sevenMeter' && $k !== 'rebound' && $k !== 'emptyGoal') {
                    for ($i = count($actions) - 2; $i >= 0; $i--) {
                        $a = $actions[$i];
                        if ($a['t'] === 'pass' && $a['to'] === $shooter) {
                            $ps[$a['from']]['assists']++;
                            break;
                        }
                        if ($a['t'] !== 'pass') {
                            break;
                        }
                    }
                }
            }
            if ($defGk && ($result === 'goal' || $result === 'save')) {
                $ps[$defGk]['shotsFaced']++;
                $def->stats['shotsFaced']++;
                $def->gkHalfShots++;
                if ($result === 'save') {
                    $ps[$defGk]['saves']++;
                    $def->stats['saves']++;
                    $def->gkHalfSaves++;
                }
            }

            return $result;
        };

        $turnover = function (string $by, string $tk, ?string $stealer = null) use (&$actions, $att, $def, &$ps): void {
            $a = ['t' => 'turnover', 'by' => $by, 'kind' => $tk];
            if ($stealer) {
                $a['stolenBy'] = $stealer;
            }
            $actions[] = $a;
            $ps[$by]['turnovers']++;
            $att->stats['turnovers']++;
            if ($stealer) {
                $ps[$stealer]['steals']++;
                $def->stats['steals']++;
            }
        };

        $sevenMeterTaker = function () use (&$attackers, $att): string {
            // The manager's chosen taker shoots if he is on court.
            $chosen = $att->team['lineup']['sevenMeter'] ?? null;
            foreach ($attackers as $o) {
                if ($o['id'] === $chosen) {
                    return $chosen;
                }
            }
            $best = $attackers[0];
            foreach ($attackers as $o) {
                if ($this->shotSkill($att, $o['id'], 'sevenMeter') > $this->shotSkill($att, $best['id'], 'sevenMeter')) {
                    $best = $o;
                }
            }

            return $best['id'];
        };

        $resultToKind = fn (string $r) => match ($r) {
            'goal' => 'goal',
            'save' => 'save',
            'block' => 'block',
            default => 'miss',
        };

        // ----- resolve the possession -----

        if ($kind === 'emptyGoal') {
            $shooter = $rng->weighted(array_map(fn ($o) => [$o['id'], $this->eff($att, $o['id'], 'shooting')], $attackers));
            $endKind = $resultToKind($takeShot($shooter, 'emptyGoal'));
        } elseif ($kind === 'fastBreak' || $kind === 'secondWave') {
            $runner = $rng->weighted(array_map(function ($o) use ($att) {
                $s = $this->eff($att, $o['id'], 'speed') / 60;

                return [$o['id'], $s * $s * $s * (($o['slot'] === 'LW' || $o['slot'] === 'RW') ? 1.6 : 1)];
            }, $attackers));
            if ($kind === 'fastBreak') {
                $passer = $att->gk;
                if ($prev['stolenBy']) {
                    foreach ($attackers as $o) {
                        if ($o['id'] === $prev['stolenBy']) {
                            $passer = $prev['stolenBy'];
                        }
                    }
                }
                if ($passer !== $runner) {
                    $actions[] = ['t' => 'pass', 'from' => $passer, 'to' => $runner];
                }
            } else {
                array_push($actions, ...$this->passChain($attackers, $runner, $rng->int(2, 3)));
            }
            $shooter = $runner;
            if ($rng->chance(0.2)) {
                $others = array_values(array_filter($attackers, fn ($o) => $o['id'] !== $runner));
                $trailer = $rng->pick($others)['id'];
                $actions[] = ['t' => 'pass', 'from' => $runner, 'to' => $trailer];
                $shooter = $trailer;
            }
            $roll = $rng->next();
            if ($roll < 0.06) {
                $turnover($shooter, $rng->chance(0.5) ? 'badPass' : 'technical');
                $endKind = 'turnover';
            } elseif ($roll < 0.13 && count($defenders)) {
                $fouler = $this->pickDefender($def, $defenders, fn () => 1);
                $actions[] = ['t' => 'foul7m', 'on' => $shooter, 'by' => $fouler];
                if ($rng->chance(0.5)) {
                    $this->suspend($def, $fouler, $actions);
                    $defenders = array_values(array_filter($defenders, fn ($d) => $d !== $fouler));
                }
                $endKind = $resultToKind($takeShot($sevenMeterTaker(), 'sevenMeter'));
            } else {
                $endKind = $resultToKind($takeShot($shooter, $kind));
            }
        } else {
            // Positional attack.
            // Defensive 2-minute suspension: free throw, the attack continues with an advantage.
            if (count($defenders) > 3 && $rng->chance(self::BALANCE['suspensionBase'] * self::BALANCE['suspensionByDefense'][$defense])) {
                $fouler = $this->pickDefender($def, $defenders, fn ($id) => 120 - $this->eff($def, $id, 'composure'));
                $this->suspend($def, $fouler, $actions);
                $defenders = array_values(array_filter($defenders, fn ($d) => $d !== $fouler));
            }

            $attPass = 0.0;
            foreach ($attackers as $o) {
                $attPass += ($this->eff($att, $o['id'], 'passing', $o['slot']) + $this->eff($att, $o['id'], 'technique', $o['slot'])) / 2;
            }
            $attPass /= count($attackers);
            $B = self::BALANCE;
            $pTO = max(0.04, $B['turnoverBase'] + $B['turnoverByDefense'][$defense] + $B['turnoverByTempo'][$tempo]
                - ($attPass - 62) / 450 + ($defAvg() - 62) / 550 - $diff() * 0.02);
            $p7 = max(0.02, $B['sevenMBase'] + $B['sevenMByDefense'][$defense] + $B['sevenMByFocus'][$focus] + $diff() * 0.01);
            [$pMin, $pMax] = $B['passes'][$tempo];
            $nPasses = $rng->int($pMin, $pMax);
            $roll = $rng->next();

            if ($roll < $pTO) {
                $tk = $rng->weighted([
                    ['steal', 0.38 + ($defense === '3-2-1' ? 0.12 : ($defense === '5-1' ? 0.04 : 0))],
                    ['badPass', 0.22],
                    ['technical', 0.22],
                    ['offensiveFoul', 0.12],
                    ['passive', $tempo === 'slow' ? 0.16 : ($tempo === 'normal' ? 0.06 : 0.02)],
                ]);
                if ($tk === 'offensiveFoul') {
                    $culprit = $rng->weighted(array_map(fn ($o) => [$o['id'], ($o['slot'] === 'PV' || $o['slot'] === 'X7') ? 2 : (in_array($o['slot'], self::BACKS, true) ? 1.5 : 0.5)], $attackers));
                } else {
                    $culprit = $rng->weighted(array_map(fn ($o) => [$o['id'], max(5, 110 - $this->eff($att, $o['id'], 'technique', $o['slot'])) * (in_array($o['slot'], self::BACKS, true) ? 1.3 : 1)], $attackers));
                }
                array_push($actions, ...$this->passChain($attackers, $culprit, max(1, $nPasses - 2)));
                $stealer = null;
                if ($tk === 'steal' && count($defenders)) {
                    $stealer = $this->pickDefender($def, $defenders, fn ($id) => $this->eff($def, $id, 'defense') + $this->eff($def, $id, 'speed'));
                }
                $turnover($culprit, $tk, $stealer);
                if ($tk === 'offensiveFoul' && $rng->chance(0.1)) {
                    $this->suspend($att, $culprit, $actions);
                }
                $endKind = $tk === 'steal' ? 'steal' : 'turnover';
                $stolenBy = $stealer;
            } elseif ($roll < $pTO + $p7) {
                $fouled = $rng->weighted(array_map(fn ($o) => [$o['id'], ($o['slot'] === 'PV' || $o['slot'] === 'X7') ? 3 : (in_array($o['slot'], self::BACKS, true) ? 1.5 : 0.8)], $attackers));
                array_push($actions, ...$this->passChain($attackers, $fouled, $nPasses));
                $fouler = $this->pickDefender($def, $defenders, fn ($id) => 120 - $this->eff($def, $id, 'composure'));
                $actions[] = ['t' => 'foul7m', 'on' => $fouled, 'by' => $fouler];
                if ($rng->chance($defense === '3-2-1' ? 0.4 : 0.3)) {
                    $this->suspend($def, $fouler, $actions);
                    $defenders = array_values(array_filter($defenders, fn ($d) => $d !== $fouler));
                }
                $endKind = $resultToKind($takeShot($sevenMeterTaker(), 'sevenMeter'));
            } else {
                // Open play shot: choose the type of chance, then the shooter.
                $has = fn (array $slots) => array_values(array_filter($attackers, fn ($o) => in_array($o['slot'], $slots, true)));
                $wings = $has(['LW', 'RW']);
                $backs = $has(['LB', 'CB', 'RB', 'X7']);
                $pivots = $has(['PV', 'X7']);
                $mult = fn (string $k) => ($B['defenseVsChance'][$defense][$k] ?? 1)
                    * ($B['focusVsChance'][$focus][$k] ?? 1)
                    * (($diff() > 0 && ($k === 'wing' || $k === 'pivot')) ? 1.3 : 1);
                $k = $rng->weighted([
                    ['wing', count($wings) ? 0.21 * $mult('wing') : 0],
                    ['back9', count($backs) ? 0.33 * $mult('back9') : 0],
                    ['breakthrough', count($backs) ? 0.21 * $mult('breakthrough') : 0],
                    ['pivot', count($pivots) ? 0.17 * $mult('pivot') : 0],
                ]);
                $pool = $k === 'wing' ? $wings : ($k === 'pivot' ? $pivots : $backs);
                $shooter = $rng->weighted(array_map(function ($o) use ($att, $k) {
                    $s = $this->shotSkill($att, $o['id'], $k, $o['slot']) / 60;

                    return [$o['id'], $s * $s * (($k === 'back9' && ($o['slot'] === 'LB' || $o['slot'] === 'RB')) ? 1.2 : 1)];
                }, $pool));
                array_push($actions, ...$this->passChain($attackers, $shooter, $nPasses));
                $r = $takeShot($shooter, $k);
                $endKind = $resultToKind($r);
                // Offensive rebound: one quick second chance.
                if ($r !== 'goal') {
                    $rebP = $r === 'block' ? 0.3 : ($r === 'save' ? 0.07 : 0.1);
                    if ($rng->chance($rebP)) {
                        $reb = $rng->weighted(array_map(fn ($o) => [$o['id'], $o['slot'] === 'PV' ? 3 : ($o['slot'] === 'X7' ? 2 : 1)], $attackers));
                        $endKind = $resultToKind($takeShot($reb, 'rebound'));
                    }
                }
            }
        }

        // Empty-net chance for the other team after a lost 7-vs-6 attack.
        if ($use7v6 && $endKind !== 'goal') {
            $p = match ($endKind) {
                'steal' => 0.6,
                'turnover' => 0.45,
                'block' => 0.25,
                default => 0.15,
            };
            if ($rng->chance($p)) {
                $endKind = 'emptyNet';
            }
        }

        // Energy and playing time
        $elapsed = $end - $this->clock;
        $attIds = array_column($attackers, 'id');
        if ($attGk) {
            $attIds[] = $attGk;
        }
        $defIds = array_column($this->fieldOnCourt($def), 'id');
        $defIds[] = $def->gk;
        $this->drain($att, $attIds, $elapsed);
        $this->drain($def, $defIds, $elapsed);

        $att->stats['possessions']++;
        if ($endKind === 'goal') {
            $def->concededRun++;
            $att->concededRun = 0;
        }

        $possession = [
            'n' => $n,
            'half' => $half,
            'side' => $att->side,
            'start' => round($this->clock, 2),
            'end' => round($end, 2),
            'kind' => $kind,
            'attackers' => $attackers,
            'attGk' => $attGk,
            'defenders' => $defenders,
            'defLineup' => $defLineup,
            'defGk' => $defGk,
            'defense' => $defense,
            'sevenVsSix' => $use7v6,
            'pre' => $pre,
            'actions' => $actions,
            'score' => [$this->teams['home']->score, $this->teams['away']->score],
        ];

        $this->clock = $end;
        $prev = ['kind' => $endKind, 'stolenBy' => $stolenBy];

        return $possession;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Player stats plus how much energy each player had left at the final whistle (0-100). */
    private function withEndEnergy(): array
    {
        $out = $this->playerStats;
        foreach ($this->teams as $ts) {
            foreach ($ts->live as $id => $l) {
                $out[$id]['energyEnd'] = (int) round($l->energy);
            }
        }

        return $out;
    }

    private static function other(string $side): string
    {
        return $side === 'home' ? 'away' : 'home';
    }

    /** How well a player fits a slot (1 = natural position). */
    public static function fit(string $pos, string $slot): float
    {
        if ($pos === $slot) {
            return 1.0;
        }
        if ($slot === 'X7') {
            return (in_array($pos, self::BACKS, true) || $pos === 'PV') ? 1.0 : 0.9;
        }
        $back = fn ($s) => in_array($s, ['LB', 'CB', 'RB'], true);
        $wing = fn ($s) => $s === 'LW' || $s === 'RW';
        if ($back($pos) && $back($slot)) {
            return 0.95;
        }
        if ($wing($pos) && $wing($slot)) {
            return 0.93;
        }

        return 0.85;
    }

    /** Rough suitability rating of a player for a slot (lineup and substitutions). */
    public static function slotRating(array $p, string $slot): float
    {
        $a = $p['attrs'];
        $r = match ($slot) {
            'LW', 'RW' => $a['speed'] * 0.3 + $a['technique'] * 0.25 + $a['shooting'] * 0.25 + $a['jumping'] * 0.1 + $a['defense'] * 0.1,
            'CB' => $a['passing'] * 0.35 + $a['technique'] * 0.2 + $a['shooting'] * 0.2 + $a['composure'] * 0.15 + $a['defense'] * 0.1,
            'PV' => $a['defense'] * 0.3 + $a['technique'] * 0.25 + $a['composure'] * 0.2 + $a['shooting'] * 0.25,
            default => $a['shooting'] * 0.35 + $a['jumping'] * 0.2 + $a['passing'] * 0.15 + $a['defense'] * 0.2 + $a['speed'] * 0.1,
        };

        return $r * self::fit($p['position'], $slot);
    }

    /**
     * The starting seven the engine uses: the best remaining field player for each slot,
     * filled in slot order, and the goalkeeper with the best reflexes + positioning.
     *
     * A manager's chosen line-up ($chosen: ['slots' => [slot => id], 'gk' => id]) is used where
     * it names players in the squad; any slot left empty or invalid is filled automatically.
     *
     * @param  array<int, array<string, mixed>>  $players  engine player arrays
     * @return array{slots: array<string, string>, gk: string} player ids
     */
    public static function startingLineup(array $players, ?array $chosen = null): array
    {
        $slots = [];
        $used = [];
        $inSquad = array_flip(array_column($players, 'id'));
        $gk = $chosen['gk'] ?? null;
        if ($gk !== null && ! isset($inSquad[$gk])) {
            $gk = null;
        }
        if ($gk !== null) {
            $used[$gk] = true;
        }
        foreach (self::SLOTS as $slot) {
            $id = $chosen['slots'][$slot] ?? null;
            if ($id !== null && isset($inSquad[$id]) && ! isset($used[$id])) {
                $slots[$slot] = $id;
                $used[$id] = true;
            }
        }
        foreach (self::SLOTS as $slot) {
            if (isset($slots[$slot])) {
                continue;
            }
            $cands = array_values(array_filter($players, fn ($p) => $p['position'] !== 'GK' && ! isset($used[$p['id']])));
            // Automatic choice: the best player for the slot, rested players preferred.
            $score = fn ($p) => self::slotRating($p, $slot) * (0.7 + 0.3 * (($p['fitness'] ?? 100) / 100));
            usort($cands, fn ($a, $b) => $score($b) <=> $score($a));
            $slots[$slot] = $cands[0]['id'];
            $used[$cands[0]['id']] = true;
        }
        if ($gk !== null) {
            return ['slots' => self::orderSlots($slots), 'gk' => $gk];
        }
        $gks = array_values(array_filter($players, fn ($p) => $p['position'] === 'GK' && ! isset($used[$p['id']])));
        if (! $gks) {
            // No goalkeeper in the squad: the best remaining court player goes in goal.
            $gks = array_values(array_filter($players, fn ($p) => ! isset($used[$p['id']])));
        }
        $gkScore = fn ($p) => ($p['attrs']['reflexes'] + $p['attrs']['positioning']) * (0.7 + 0.3 * (($p['fitness'] ?? 100) / 100));
        usort($gks, fn ($a, $b) => $gkScore($b) <=> $gkScore($a));

        return ['slots' => self::orderSlots($slots), 'gk' => $gks[0]['id']];
    }

    /** @param array<string, string> $slots */
    private static function orderSlots(array $slots): array
    {
        $out = [];
        foreach (self::SLOTS as $s) {
            $out[$s] = $slots[$s];
        }

        return $out;
    }

    private function initTeam(array $team, string $side): TeamState
    {
        $ts = new TeamState($side, $team);
        foreach ($team['players'] as $p) {
            $ts->live[$p['id']] = new LivePlayer($p);
        }
        $lineup = self::startingLineup($team['players'], $team['lineup'] ?? null);
        $ts->slots = $lineup['slots'];
        $ts->gk = $lineup['gk'];

        return $ts;
    }

    private function eff(TeamState $ts, string $id, string $attr, ?string $slot = null): float
    {
        $l = $ts->live[$id];
        $energy = 0.82 + 0.18 * ($l->energy / 100);
        $f = $slot ? self::fit($l->p['position'], $slot) : 1.0;

        return $l->p['attrs'][$attr] * $energy * $f;
    }

    /** @return array<int, array{slot: string, id: string}> */
    private function fieldOnCourt(TeamState $ts): array
    {
        $out = [];
        foreach (self::SLOTS as $s) {
            if ($ts->live[$ts->slots[$s]]->available($this->clock)) {
                $out[] = ['slot' => $s, 'id' => $ts->slots[$s]];
            }
        }

        return $out;
    }

    private function avgSpeed(TeamState $ts): float
    {
        $on = $this->fieldOnCourt($ts);
        $sum = 0.0;
        foreach ($on as $o) {
            $sum += $this->eff($ts, $o['id'], 'speed');
        }

        return $sum / max(1, count($on));
    }

    /**
     * Available bench players for a slot, best first.
     *
     * @param  array<int, string>  $exclude
     * @return array<int, LivePlayer>
     */
    private function benchFor(TeamState $ts, string $slot, array $exclude): array
    {
        $out = [];
        foreach ($ts->team['players'] as $p) {
            if ($p['position'] === 'GK' || in_array($p['id'], $exclude, true)) {
                continue;
            }
            $l = $ts->live[$p['id']];
            if ($l->available($this->clock)) {
                $out[] = $l;
            }
        }
        $score = fn (LivePlayer $l) => self::slotRating($l->p, $slot) * (0.6 + 0.4 * $l->energy / 100);
        usort($out, fn ($a, $b) => $score($b) <=> $score($a));

        return $out;
    }

    /** @param array<int, string> $ids */
    private function pickDefender(TeamState $ts, array $ids, callable $w): string
    {
        return $this->rng->weighted(array_map(fn ($id) => [$id, max(1, $w($id))], $ids));
    }

    /** @param array<int, array<string, mixed>> $pre */
    private function rotate(TeamState $ts, array &$pre): void
    {
        $threshold = self::BALANCE['rotationThreshold'][$ts->tactic('rotation')];
        foreach (self::SLOTS as $slot) {
            $id = $ts->slots[$slot];
            $l = $ts->live[$id];
            $inSlots = array_values($ts->slots);
            if ($l->red && $this->clock >= $l->suspendedUntil) {
                $sub = $this->benchFor($ts, $slot, $inSlots)[0] ?? null;
                if ($sub) {
                    $ts->slots[$slot] = $sub->p['id'];
                    $pre[] = ['t' => 'sub', 'side' => $ts->side, 'out' => $id, 'in' => $sub->p['id']];
                }

                continue;
            }
            if (! $l->available($this->clock) || $l->energy >= $threshold) {
                continue;
            }
            foreach ($this->benchFor($ts, $slot, $inSlots) as $b) {
                if ($b->energy > $l->energy + 15 && self::fit($b->p['position'], $slot) >= 0.93) {
                    $ts->slots[$slot] = $b->p['id'];
                    $pre[] = ['t' => 'sub', 'side' => $ts->side, 'out' => $id, 'in' => $b->p['id']];
                    break;
                }
            }
        }
        // Keeper change when the keeper is having a bad half.
        if (! $ts->gkChangedThisHalf && $ts->gkHalfShots >= 8 && $ts->gkHalfSaves / $ts->gkHalfShots < 0.2) {
            foreach ($ts->team['players'] as $p) {
                if ($p['position'] === 'GK' && $p['id'] !== $ts->gk) {
                    $pre[] = ['t' => 'gkChange', 'side' => $ts->side, 'out' => $ts->gk, 'in' => $p['id']];
                    $ts->gk = $p['id'];
                    $ts->gkChangedThisHalf = true;
                    $ts->gkHalfShots = 0;
                    $ts->gkHalfSaves = 0;
                    break;
                }
            }
        }
    }

    /** @param array<int, array<string, mixed>> $actions */
    private function suspend(TeamState $ts, string $id, array &$actions): void
    {
        $l = $ts->live[$id];
        $l->suspensions++;
        $l->suspendedUntil = $this->clock + 120;
        $red = $l->suspensions >= 3;
        if ($red) {
            $l->red = true;
        }
        $ts->stats['suspensions']++;
        $this->playerStats[$id]['suspensions']++;
        $actions[] = ['t' => 'suspension', 'player' => $id, 'side' => $ts->side, 'red' => $red];
    }

    /**
     * Random walk of passes over neighbouring slots, ending with the ball at $actor.
     *
     * @param  array<int, array{slot: string, id: string}>  $att
     * @return array<int, array<string, string>>
     */
    private function passChain(array $att, string $actor, int $count): array
    {
        $bySlot = [];
        $slotOf = [];
        foreach ($att as $o) {
            $bySlot[$o['slot']] = $o['id'];
            $slotOf[$o['id']] = $o['slot'];
        }
        $adj = [
            'LW' => [['LB', 1]],
            'LB' => [['LW', 0.7], ['CB', 1], ['PV', 0.3], ['RB', 0.2], ['X7', 0.3]],
            'CB' => [['LB', 1], ['RB', 1], ['PV', 0.35], ['X7', 0.5]],
            'RB' => [['RW', 0.7], ['CB', 1], ['PV', 0.3], ['LB', 0.2], ['X7', 0.3]],
            'RW' => [['RB', 1]],
            'PV' => [['CB', 0.6], ['LB', 0.5], ['RB', 0.5]],
            'X7' => [['CB', 0.8], ['LB', 0.5], ['RB', 0.5]],
        ];
        $cur = $bySlot['CB'] ?? $bySlot['LB'] ?? $bySlot['RB'] ?? $att[0]['id'];
        $out = [];
        for ($i = 0; $i < $count - 1; $i++) {
            $opts = array_values(array_filter($adj[$slotOf[$cur]], fn ($o) => isset($bySlot[$o[0]])));
            if (count($opts)) {
                $next = $bySlot[$this->rng->weighted($opts)];
            } else {
                $next = $this->rng->pick(array_values(array_filter($att, fn ($o) => $o['id'] !== $cur)))['id'];
            }
            $out[] = ['t' => 'pass', 'from' => $cur, 'to' => $next];
            $cur = $next;
        }
        if ($cur !== $actor) {
            $out[] = ['t' => 'pass', 'from' => $cur, 'to' => $actor];
        }

        return $out;
    }

    private function shotSkill(TeamState $ts, string $id, string $kind, ?string $slot = null): float
    {
        $e = fn (string $a) => $this->eff($ts, $id, $a, $slot);

        return match ($kind) {
            'wing' => $e('shooting') * 0.5 + $e('technique') * 0.3 + $e('jumping') * 0.2,
            'back9' => $e('shooting') * 0.6 + $e('jumping') * 0.25 + $e('composure') * 0.15,
            'breakthrough' => $e('speed') * 0.35 + $e('technique') * 0.35 + $e('shooting') * 0.3,
            'pivot', 'rebound' => $e('technique') * 0.4 + $e('shooting') * 0.3 + $e('composure') * 0.3,
            'sevenMeter' => $e('shooting') * 0.5 + $e('composure') * 0.5,
            default => $e('shooting') * 0.5 + $e('composure') * 0.3 + $e('speed') * 0.2,
        };
    }

    /** @return array{d: float, y: float} */
    private function shotLocation(string $kind, ?string $slot): array
    {
        $rng = $this->rng;
        $sideY = ($slot === 'LW' || $slot === 'LB') ? -1 : (($slot === 'RW' || $slot === 'RB') ? 1 : ($rng->chance(0.5) ? -1 : 1));
        $pt = fn (float $d, float $y) => ['d' => round($d, 2), 'y' => round($y, 2)];

        switch ($kind) {
            case 'wing':
                $d = $rng->range(2.5, 5);

                return $pt($d, $sideY * $rng->range(6.6, 8.6));
            case 'back9':
                $base = $slot === 'LB' ? -5 : ($slot === 'RB' ? 5 : 0);
                $d = $rng->range(8.8, 11.5);

                return $pt($d, $base + $rng->range(-1.5, 1.5));
            case 'breakthrough':
                $base = $slot === 'LB' ? -3 : ($slot === 'RB' ? 3 : 0);
                $d = $rng->range(6.3, 7.3);

                return $pt($d, $base + $rng->range(-1, 1));
            case 'pivot':
            case 'rebound':
                $d = $rng->range(6.1, 6.6);

                return $pt($d, $rng->range(-2.5, 2.5));
            case 'sevenMeter':
                return $pt(7, 0);
            case 'emptyGoal':
                $d = $rng->range(18, 30);

                return $pt($d, $rng->range(-6, 6));
            default:
                $d = $rng->range(6.2, 7);

                return $pt($d, $rng->range(-3, 3));
        }
    }

    /** @param array<int, string> $onCourt */
    private function drain(TeamState $ts, array $onCourt, float $elapsed): void
    {
        $tempo = $ts->tactic('tempo');
        $tempoF = $tempo === 'fast' ? 1.2 : ($tempo === 'slow' ? 0.85 : 1);
        $on = array_flip($onCourt);
        foreach ($ts->live as $id => $l) {
            if (isset($on[$id])) {
                $l->energy = max(10, $l->energy - ($elapsed / 37) * 0.6 * $tempoF * (1.45 - $l->p['attrs']['stamina'] / 100));
                $this->playerStats[$id]['secondsPlayed'] += (int) round($elapsed);
            } elseif ($l->p['position'] !== 'GK') {
                $l->energy = min(100, $l->energy + ($elapsed / 37) * 1.3);
            }
        }
    }
}
