<?php

namespace App\Http\Controllers;

use App\Game\Engine\MatchEngine;
use App\Game\Training;
use App\Models\Club;
use App\Models\Fixture;
use App\Models\Player;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Line-up and training: the manager's choices between matches. */
class TeamController extends Controller
{
    public const SLOT_NAMES = [
        'LW' => 'Left wing', 'LB' => 'Left back', 'CB' => 'Centre back',
        'RB' => 'Right back', 'RW' => 'Right wing', 'PV' => 'Pivot',
    ];

    // ----- Line-up -----

    public function lineup(Request $request): View
    {
        $club = $request->user()->club;
        $players = $club->players()->orderBy('position')->get();
        $engine = $players->mapWithKeys(fn (Player $p) => [$p->id => $p->toEngineArray()]);
        $saved = $club->lineup ?? [];

        // Who will actually start: the saved choices where valid, automatic for the rest.
        $starting = $club->startingLineup();
        $next = Fixture::involving($club)->upcoming()->with(['home', 'away'])->orderBy('scheduled_at')->first();

        // Everything the court needs to rate players and preview the automatic pick in the browser.
        $order = array_keys(Player::POSITIONS);
        $players = $players->sortBy(fn (Player $p) => [array_search($p->position, $order, true), -$p->rating()])->values();
        $cards = $players->map(function (Player $p) use ($engine) {
            $e = $engine[$p->id];
            $fit = 0.7 + 0.3 * (($e['fitness'] ?? 100) / 100);
            $ratings = [];
            $scores = [];
            foreach (array_keys(self::SLOT_NAMES) as $slot) {
                $r = MatchEngine::slotRating($e, $slot);
                $ratings[$slot] = (int) round($r);
                $scores[$slot] = round($r * $fit, 3);
            }
            $ratings['GK'] = $p->rating();
            $scores['GK'] = round(($e['attrs']['reflexes'] + $e['attrs']['positioning']) * $fit, 3);

            return [
                'id' => $p->id, 'name' => $p->name, 'short' => self::shortName($p->name), 'pos' => $p->position,
                'number' => $p->number, 'gk' => $p->isGoalkeeper(), 'fitness' => $p->fitness,
                'injured' => $p->isInjured(), 'injury' => $p->injury, 'days' => $p->injured_days,
                'rating' => $p->rating(), 'ratings' => $ratings, 'scores' => $scores,
            ];
        });

        return view('game.lineup', [
            'club' => $club,
            'players' => $players,
            'cards' => $cards,
            'saved' => $saved,
            'starting' => $starting,
            'next' => $next,
        ]);
    }

    public function updateLineup(Request $request): RedirectResponse
    {
        $club = $request->user()->club;
        $ids = $club->players()->pluck('id')->all();
        $data = $request->validate([
            'slots' => ['array'],
            'slots.*' => ['nullable', Rule::in($ids)],
            'gk' => ['nullable', Rule::in($ids)],
            'sevenMeter' => ['nullable', Rule::in($ids)],
        ]);

        $slots = array_filter(array_intersect_key($data['slots'] ?? [], self::SLOT_NAMES));
        $chosen = array_values(array_filter([...array_values($slots), $data['gk'] ?? null]));
        if (count($chosen) !== count(array_unique($chosen))) {
            throw ValidationException::withMessages(['slots' => 'A player can only play one position. Pick a different player for one of them.']);
        }

        $all = array_filter($slots) || ($data['gk'] ?? null) || ($data['sevenMeter'] ?? null);
        $club->update(['lineup' => $all ? [
            'slots' => array_map('intval', $slots),
            'gk' => isset($data['gk']) ? (int) $data['gk'] : null,
            'sevenMeter' => isset($data['sevenMeter']) ? (int) $data['sevenMeter'] : null,
        ] : null]);

        return redirect()->route('lineup')->with('status', 'Line-up saved. It is used from your next match.');
    }

    public function resetLineup(Request $request): RedirectResponse
    {
        $request->user()->club->update(['lineup' => null]);

        return redirect()->route('lineup')->with('status', 'Line-up set to automatic: the best rested player starts in each position.');
    }

    // ----- Training -----

    public function training(Request $request): View
    {
        $club = $request->user()->club;
        $order = array_keys(Player::POSITIONS);
        $players = $club->players()->get()
            ->sortBy(fn (Player $p) => [array_search($p->position, $order, true), $p->age])->values();

        return view('game.training', [
            'club' => $club,
            'players' => $players,
            'intensity' => $club->training_intensity ?? 'normal',
        ]);
    }

    public function updateTraining(Request $request): RedirectResponse
    {
        $club = $request->user()->club;
        $ids = $club->players()->pluck('id')->all();
        $data = $request->validate([
            'intensity' => ['required', Rule::in(array_keys(Training::INTENSITY))],
            'focus' => ['array'],
            'focus.*' => [Rule::in(array_keys(Training::FOCUS))],
        ]);
        $club->update(['training_intensity' => $data['intensity']]);
        foreach ($data['focus'] ?? [] as $id => $focus) {
            if (in_array((int) $id, $ids, true)) {
                Player::whereKey($id)->update(['training_focus' => $focus]);
            }
        }

        return redirect()->route('training')->with('status', 'Training plan saved. Players train after every match day.');
    }

    /** "Kaelor Thornvane" -> "K. Thornvane", for the small labels on the court. */
    public static function shortName(string $name): string
    {
        $parts = explode(' ', $name, 2);

        return count($parts) === 2 ? mb_substr($parts[0], 0, 1).'. '.$parts[1] : $name;
    }

    /** How fast a player improves at his age, in words. */
    public static function growthLabel(int $age): string
    {
        return match (true) {
            $age <= 20 => 'Fast',
            $age <= 23 => 'Good',
            $age <= 26 => 'Some',
            $age <= 29 => 'Slow',
            default => 'None',
        };
    }
}
