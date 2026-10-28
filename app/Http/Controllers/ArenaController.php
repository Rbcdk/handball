<?php

namespace App\Http\Controllers;

use App\Game\Arena;
use App\Game\Economy;
use App\Game\Finance;
use App\Models\Fixture;
use App\Models\Season;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The club's arena: crowds, fans and building more seats. */
class ArenaController extends Controller
{
    public function show(Request $request, Finance $finance): View
    {
        $club = $request->user()->club;
        $season = Season::current();
        $tier = (int) $club->league->tier;
        $form = $finance->form($club, $season, $club->league);

        $homeGames = Fixture::where('home_club_id', $club->id)->whereNotNull('attendance')
            ->when($season, fn ($q) => $q->where('season_id', $season->id))
            ->with('away')->orderByDesc('played_at')->get();

        $options = collect(Economy::EXPANSIONS)->map(fn ($days, $seats) => [
            'seats' => $seats,
            'days' => $days,
            'cost' => Economy::expansionCost((int) $club->arena_capacity, $seats),
            'upkeep' => Economy::SEAT_UPKEEP * $seats * 2,
            'income' => Economy::ticketIncome($tier, $seats),
            'possible' => $club->arena_capacity + $seats <= Economy::MAX_CAPACITY,
        ])->values();

        return view('game.arena', [
            'club' => $club,
            'tier' => $tier,
            'forecast' => Arena::forecast($club, $form, $tier),
            'homeGames' => $homeGames,
            'options' => $options,
            'upkeep' => Economy::SEAT_UPKEEP * (int) $club->arena_capacity * 2,
            'price' => Economy::TICKET_PRICE[$tier],
        ]);
    }

    public function expand(Request $request, Arena $arena): RedirectResponse
    {
        $data = $request->validate(['seats' => ['required', 'integer', Rule::in(array_keys(Economy::EXPANSIONS))]]);
        try {
            $arena->expand($request->user()->club, (int) $data['seats']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('arena')->with('status', 'The builders have started. The new seats are ready in '.Economy::EXPANSIONS[(int) $data['seats']].' match days.');
    }
}
