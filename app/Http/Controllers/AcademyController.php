<?php

namespace App\Http\Controllers;

use App\Game\Academy;
use App\Game\Economy;
use App\Models\Prospect;
use App\Models\Season;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The youth academy: prospects, promotions and the academy's level. */
class AcademyController extends Controller
{
    public function show(Request $request, Academy $academy): View
    {
        $club = $request->user()->club;
        // A club's first visit brings its first intake, so new managers don't wait a season.
        if ($club->academy_intake_season === null && ($season = Season::current())) {
            $academy->intake($club, $season);
        }
        $order = array_keys(\App\Models\Player::POSITIONS);

        return view('game.academy', [
            'club' => $club,
            'level' => Academy::LEVELS[$club->academy_level],
            'next' => Academy::LEVELS[$club->academy_level + 1] ?? null,
            'prospects' => $club->prospects()->get()->sortBy(fn ($p) => [array_search($p->position, $order, true), -$p->age])->values(),
            'squadFull' => $club->players()->count() >= Economy::SQUAD_MAX,
            'season' => Season::current(),
        ]);
    }

    public function level(Request $request, Academy $academy): RedirectResponse
    {
        $data = $request->validate(['level' => ['required', 'integer']]);

        return $this->attempt(fn () => $academy->changeLevel($request->user()->club, (int) $data['level']),
            'The academy is now '.(Academy::LEVELS[(int) $data['level']]['label'] ?? '').'.');
    }

    public function promote(Request $request, Prospect $prospect, Academy $academy): RedirectResponse
    {
        return $this->attempt(fn () => $academy->promote($request->user()->club, $prospect),
            "{$prospect->name} is now in your first team. He starts on a three-season contract.");
    }

    public function release(Request $request, Prospect $prospect, Academy $academy): RedirectResponse
    {
        return $this->attempt(fn () => $academy->release($request->user()->club, $prospect), "{$prospect->name} has left the academy.");
    }

    private function attempt(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('academy')->with('status', $success);
    }
}
