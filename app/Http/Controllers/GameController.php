<?php

namespace App\Http\Controllers;

use App\Game\Standings;
use App\Models\Club;
use App\Models\Fixture;
use App\Models\League;
use App\Models\Player;
use App\Models\PlayerSeasonStat;
use App\Models\Season;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Pages for the signed-in manager and the public game pages. */
class GameController extends Controller
{
    public function __construct(private Standings $standings)
    {
    }

    // ----- Landing -----

    public function home(Request $request): View|RedirectResponse
    {
        if ($request->user()?->club) {
            return redirect()->route('office');
        }

        $season = Season::current();
        $top = League::where('tier', 1)->first();

        // Hero replay: the most recent top-league match, falling back to any played match.
        $replay = Fixture::played()->with(['home', 'away', 'league', 'matchLog'])
            ->when($top, fn ($q) => $q->orderByRaw('league_id = ? desc', [$top->id]))
            ->orderByDesc('played_at')
            ->first();

        return view('game.home', [
            'season' => $season,
            'top' => $top,
            'table' => $season && $top ? $this->standings->table($season, $top)->take(8) : collect(),
            'replay' => $replay,
            'freeClubs' => Club::available()->whereHas('league', fn ($q) => $q->where('tier', 2))->count(),
            'nextKickoff' => Fixture::upcoming()->orderBy('scheduled_at')->value('scheduled_at'),
        ]);
    }

    // ----- Taking over a club -----

    public function chooseClub(Request $request): View|RedirectResponse
    {
        if ($request->user()->club) {
            return redirect()->route('office');
        }
        $season = Season::current();
        $leagues = League::where('tier', 2)->orderBy('sort')->get();
        if ($leagues->isEmpty()) {
            return view('game.no-world');
        }

        $groups = $leagues->map(function (League $league) use ($season) {
            $table = $season ? $this->standings->table($season, $league)->keyBy(fn ($r) => $r->club->id) : collect();
            $clubs = Club::available()->where('league_id', $league->id)->with('players')->get()
                ->map(fn (Club $c) => (object) [
                    'club' => $c,
                    'rating' => (int) round($c->players->sortByDesc(fn (Player $p) => $p->rating())->take(9)->avg(fn (Player $p) => $p->rating())),
                    'position' => $table[$c->id]->position ?? null,
                    'points' => $table[$c->id]->points ?? 0,
                ])
                ->sortBy(fn ($r) => $r->position ?? 99)
                ->values();

            return (object) ['league' => $league, 'clubs' => $clubs];
        });

        return view('game.choose-club', ['groups' => $groups]);
    }

    public function takeOver(Request $request, Club $club): RedirectResponse
    {
        $user = $request->user();

        $taken = DB::transaction(function () use ($user, $club) {
            $club = Club::lockForUpdate()->with('league')->find($club->id);
            if ($user->club()->exists() || $club->user_id !== null || (int) $club->league->tier !== 2) {
                return false;
            }
            $club->update(['user_id' => $user->id, 'taken_over_at' => now()]);

            return true;
        });

        if (! $taken) {
            return redirect()->route('clubs.choose')->with('error', 'That club is no longer available. Pick another one.');
        }
        $user->unsetRelation('club');

        return redirect()->route('office')->with('status', "Welcome to {$club->name}. Your first match is below.");
    }

    // ----- Manager pages -----

    public function office(Request $request): View
    {
        $club = $request->user()->club->load('league');
        $season = Season::current();

        $next = Fixture::involving($club)->upcoming()->with(['home', 'away', 'league'])->orderBy('scheduled_at')->first();
        $recent = Fixture::involving($club)->played()->with(['home', 'away'])->orderByDesc('played_at')->take(5)->get();
        $table = $season ? $this->standings->table($season, $club->league) : collect();
        $scorers = $season
            ? PlayerSeasonStat::with('player')->where('season_id', $season->id)->where('club_id', $club->id)
                ->orderByDesc('goals')->take(5)->get()
            : collect();

        $user = $request->user();
        $guide = $user->showsGuide() ? [
            'squad' => $user->hasDone('squad'),
            'tactics' => $user->hasDone('tactics'),
            'replay' => $user->hasDone('replay'),
            'league' => $user->hasDone('league'),
        ] : null;

        return view('game.office', [
            'news' => $club->news()->latest('id')->take(8)->get(),
            'injured' => $club->players()->where('injured_days', '>', 0)->get(),
            'guide' => $guide,
            'club' => $club,
            'season' => $season,
            'next' => $next,
            'last' => $recent->first(),
            'recent' => $recent,
            'table' => $table,
            'myRow' => $table->first(fn ($r) => $r->club->id === $club->id),
            'scorers' => $scorers,
        ]);
    }

    public function squad(Request $request): View
    {
        $club = $request->user()->club->load('league');
        $request->user()->markDone('squad');

        return view('game.squad', $this->squadData($club));
    }

    public function tactics(Request $request): View
    {
        $club = $request->user()->club;
        $next = Fixture::involving($club)->upcoming()->with(['home', 'away'])->orderBy('scheduled_at')->first();

        return view('game.tactics', [
            'club' => $club,
            'tactics' => array_merge(Club::DEFAULT_TACTICS, $club->tactics ?? []),
            'next' => $next,
        ]);
    }

    public function updateTactics(Request $request): RedirectResponse
    {
        $rules = [];
        foreach (Club::TACTIC_OPTIONS as $key => $def) {
            $rules[$key] = ['required', Rule::in(array_keys($def['options']))];
        }
        $data = $request->validate($rules);
        $request->user()->club->update(['tactics' => $data]);
        $request->user()->markDone('tactics');

        return redirect()->route('tactics.edit')->with('status', 'Tactics saved. They apply from your next match.');
    }

    public function fixtures(Request $request): View
    {
        $club = $request->user()->club;
        $season = Season::current();
        $fixtures = $season
            ? Fixture::involving($club)->where('season_id', $season->id)->with(['home', 'away'])->orderBy('round')->get()
            : collect();

        return view('game.fixtures', ['club' => $club, 'season' => $season, 'fixtures' => $fixtures]);
    }

    public function dismissGuide(Request $request): RedirectResponse
    {
        $request->user()->markDone('dismissed');

        return redirect()->route('office');
    }

    // ----- Public pages -----

    public function help(): View
    {
        return view('game.help', [
            'leagues' => League::orderBy('sort')->get(),
            'clubsPerLeague' => config('game.clubs_per_league'),
        ]);
    }

    public function league(Request $request, ?League $league = null): View
    {
        $league ??= $request->user()?->club?->league ?? League::orderBy('sort')->first();
        if (! $league) {
            return view('game.no-world');
        }
        if ($request->user()?->club) {
            $request->user()->markDone('league');
        }
        $season = Season::current();
        $table = $season ? $this->standings->table($season, $league) : collect();

        $fixtures = $season
            ? Fixture::where('season_id', $season->id)->where('league_id', $league->id)->with(['home', 'away'])->orderBy('id')->get()
            : collect();
        $lastPlayed = (int) $fixtures->whereNotNull('played_at')->max('round');
        $rounds = (int) $fixtures->max('round');
        $round = (int) $request->integer('round', $lastPlayed ?: 1);
        $round = max(1, min($round, max(1, $rounds)));

        return view('game.league', [
            'league' => $league,
            'leagues' => League::orderBy('sort')->get(),
            'season' => $season,
            'table' => $table,
            'round' => $round,
            'rounds' => $rounds,
            'roundFixtures' => $fixtures->where('round', $round)->values(),
            'myClubId' => $request->user()?->club?->id,
        ]);
    }

    public function club(Request $request, Club $club): View
    {
        $club->load(['league', 'user']);
        $season = Season::current();
        $results = Fixture::involving($club)->played()->with(['home', 'away'])->orderByDesc('played_at')->take(8)->get();
        $row = $season ? $this->standings->table($season, $club->league)->first(fn ($r) => $r->club->id === $club->id) : null;

        return view('game.club', [
            ...$this->squadData($club),
            'results' => $results,
            'row' => $row,
            'isMine' => $request->user()?->club?->id === $club->id,
        ]);
    }

    public function match(Request $request, Fixture $fixture): View
    {
        $fixture->load(['home', 'away', 'league', 'season', 'matchLog']);
        if ($fixture->isPlayed() && $request->user()?->club) {
            $request->user()->markDone('replay');
        }
        $season = $fixture->season;
        $table = $this->standings->table($season, $fixture->league)->keyBy(fn ($r) => $r->club->id);

        return view('game.match', [
            'fixture' => $fixture,
            'log' => $fixture->matchLog?->json(),
            'homeRow' => $table[$fixture->home_club_id] ?? null,
            'awayRow' => $table[$fixture->away_club_id] ?? null,
            'myClubId' => $request->user()?->club?->id,
        ]);
    }

    // ----- Helpers -----

    private function squadData(Club $club): array
    {
        $season = Season::current();
        $order = array_keys(Player::POSITIONS);
        $starters = $club->startingLineup();
        // Starters first, in court order; then the bench by position and rating.
        $players = $club->players()->get()
            ->sortBy(fn (Player $p) => [
                isset($starters[$p->id]) ? 0 : 1,
                array_search($starters[$p->id] ?? $p->position, $order, true),
                -$p->rating(),
            ])
            ->values();
        $stats = $season
            ? PlayerSeasonStat::where('season_id', $season->id)->whereIn('player_id', $players->pluck('id'))->get()->keyBy('player_id')
            : collect();

        return ['club' => $club, 'season' => $season, 'players' => $players, 'stats' => $stats, 'starters' => $starters];
    }
}
