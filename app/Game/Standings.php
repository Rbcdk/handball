<?php

namespace App\Game;

use App\Models\Club;
use App\Models\Fixture;
use App\Models\League;
use App\Models\Season;
use Illuminate\Support\Collection;

/** League tables, computed from played fixtures. Handball scoring: 2 points for a win, 1 for a draw. */
final class Standings
{
    /**
     * @return Collection<int, object{club: Club, position: int, played: int, won: int, drawn: int, lost: int, for: int, against: int, diff: int, points: int, form: array<int, string>}>
     */
    public function table(Season $season, League $league): Collection
    {
        $fixtures = Fixture::where('season_id', $season->id)
            ->where('league_id', $league->id)
            ->orderBy('scheduled_at')
            ->get();

        $clubIds = $fixtures->pluck('home_club_id')->merge($fixtures->pluck('away_club_id'))->unique();
        if ($clubIds->isEmpty()) {
            $clubIds = $league->clubs()->pluck('id');
        }
        $clubs = Club::whereIn('id', $clubIds)->get()->keyBy('id');

        $rows = [];
        foreach ($clubs as $id => $club) {
            $rows[$id] = (object) [
                'club' => $club, 'position' => 0, 'played' => 0, 'won' => 0, 'drawn' => 0, 'lost' => 0,
                'for' => 0, 'against' => 0, 'diff' => 0, 'points' => 0, 'form' => [],
            ];
        }

        foreach ($fixtures->whereNotNull('played_at') as $f) {
            foreach ([[(int) $f->home_club_id, (int) $f->home_score, (int) $f->away_score], [(int) $f->away_club_id, (int) $f->away_score, (int) $f->home_score]] as [$id, $for, $against]) {
                $r = $rows[$id];
                $r->played++;
                $r->for += $for;
                $r->against += $against;
                if ($for > $against) {
                    $r->won++;
                    $r->points += 2;
                    $r->form[] = 'W';
                } elseif ($for === $against) {
                    $r->drawn++;
                    $r->points += 1;
                    $r->form[] = 'D';
                } else {
                    $r->lost++;
                    $r->form[] = 'L';
                }
            }
        }

        $sorted = collect($rows)
            ->each(function ($r) {
                $r->diff = $r->for - $r->against;
                $r->form = array_slice($r->form, -5);
            })
            ->sort(fn ($a, $b) => [$b->points, $b->diff, $b->for, $a->club->name] <=> [$a->points, $a->diff, $a->for, $b->club->name])
            ->values();

        $sorted->each(fn ($r, $i) => $r->position = $i + 1);

        return $sorted;
    }
}
