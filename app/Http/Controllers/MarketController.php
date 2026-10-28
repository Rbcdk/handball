<?php

namespace App\Http\Controllers;

use App\Game\Economy;
use App\Game\Finance;
use App\Game\TransferException;
use App\Game\TransferMarket;
use App\Models\Player;
use App\Models\PlayerSeasonStat;
use App\Models\Season;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\TransferBid;
use App\Models\TransferListing;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Transfer market, player pages and the club's finances. */
class MarketController extends Controller
{
    public function __construct(private TransferMarket $market, private Finance $finance)
    {
    }

    // ----- Transfer market -----

    public function index(Request $request): View
    {
        $club = $request->user()->club;
        $tab = in_array($request->query('tab'), ['market', 'free', 'mine'], true) ? $request->query('tab') : 'market';
        $position = array_key_exists((string) $request->query('position'), Player::POSITIONS) ? $request->query('position') : null;
        $maxPrice = $request->integer('max') ?: null;

        $listings = TransferListing::open()->with(['player.club', 'seller', 'highestBid.club'])
            ->when($position, fn ($q) => $q->whereHas('player', fn ($p) => $p->where('position', $position)))
            ->orderBy('ends_at')->get()
            ->filter(fn (TransferListing $l) => $l->player !== null)
            ->filter(fn (TransferListing $l) => ! $maxPrice || $this->currentPrice($l) <= $maxPrice)
            ->values();

        $free = Player::whereNull('club_id')
            ->when($position, fn ($q) => $q->where('position', $position))
            ->get()->sortByDesc(fn (Player $p) => $p->rating())->values();

        $myListings = TransferListing::where('seller_club_id', $club->id)->with(['player', 'highestBid.club', 'buyer'])
            ->latest()->take(20)->get();
        $myBids = TransferBid::where('club_id', $club->id)->with(['listing.player', 'listing.highestBid'])
            ->latest()->take(20)->get()->unique('listing_id')->values();
        $recent = Transfer::with(['player', 'from', 'to'])->whereIn('kind', ['auction', 'fixed'])->latest()->take(10)->get();

        return view('game.transfers', compact('club', 'tab', 'position', 'maxPrice', 'listings', 'free', 'myListings', 'myBids', 'recent'));
    }

    public function list(Request $request, Player $player): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['auction', 'fixed'])],
            'price' => ['required', 'integer', 'min:1'],
        ]);

        return $this->attempt(fn () => $this->market->list($request->user()->club, $player, $data['kind'], (int) $data['price']),
            $data['kind'] === 'auction' ? "{$player->name} is up for auction for 24 hours." : "{$player->name} is for sale for three days.");
    }

    public function cancel(Request $request, TransferListing $listing): RedirectResponse
    {
        return $this->attempt(fn () => $this->market->cancel($listing, $request->user()->club), "{$listing->player->name} is off the transfer list.");
    }

    public function buy(Request $request, TransferListing $listing): RedirectResponse
    {
        return $this->attempt(fn () => $this->market->buyNow($listing, $request->user()->club), "{$listing->player->name} has joined your club.");
    }

    public function bid(Request $request, TransferListing $listing): RedirectResponse
    {
        $amount = (int) preg_replace('/\D/', '', (string) $request->input('amount'));

        return $this->attempt(fn () => $this->market->bid($listing, $request->user()->club, $amount),
            'Bid placed: '.Economy::format($amount).'. You will win him if nobody bids more before the auction ends.');
    }

    public function sign(Request $request, Player $player): RedirectResponse
    {
        $seasons = max(1, min(3, $request->integer('seasons', 2)));

        return $this->attempt(fn () => $this->market->signFreeAgent($player, $request->user()->club, $seasons), "{$player->name} has signed for your club.");
    }

    // ----- Player -----

    public function player(Request $request, Player $player): View
    {
        $player->load(['club.league', 'listing.highestBid.club', 'transfers.from', 'transfers.to']);
        $club = $request->user()?->club;
        $season = Season::current();
        $stats = PlayerSeasonStat::with('club')->where('player_id', $player->id)->orderByDesc('season_id')->get();
        $listing = $player->listing;
        $value = $player->marketValue();

        return view('game.player', [
            'player' => $player,
            'myClub' => $club,
            'isMine' => $club && (int) $player->club_id === (int) $club->id,
            'season' => $season,
            'stats' => $stats,
            'listing' => $listing,
            'value' => $value,
            'priceRange' => Economy::priceRange($value),
            'minBid' => $listing ? Economy::minimumBid($listing->price, $listing->highestBid?->amount) : null,
        ]);
    }

    public function extend(Request $request, Player $player): RedirectResponse
    {
        $seasons = max(1, min(3, $request->integer('seasons', 1)));

        return $this->attempt(fn () => $this->market->extendContract($player, $request->user()->club, $seasons),
            "{$player->name} has signed a new contract.");
    }

    public function release(Request $request, Player $player): RedirectResponse
    {
        $name = $player->name;

        return $this->attempt(fn () => $this->market->release($player, $request->user()->club), "{$name} has left the club.", route('squad'));
    }

    // ----- Finances -----

    public function finances(Request $request): View
    {
        $club = $request->user()->club->load('league');
        $season = Season::current();

        return view('game.finances', [
            'club' => $club,
            'season' => $season,
            'summary' => $this->finance->seasonSummary($club, $season),
            'transactions' => Transaction::where('club_id', $club->id)->latest('id')->take(20)->get(),
            'wageBill' => $club->wageBill(),
            'players' => $club->players()->orderByDesc('wage')->get(),
            'tier' => (int) $club->league->tier,
        ]);
    }

    // ----- Helpers -----

    private function currentPrice(TransferListing $l): int
    {
        return $l->isAuction() ? Economy::minimumBid($l->price, $l->highestBid?->amount) : $l->price;
    }

    private function attempt(Closure $action, string $success, ?string $redirect = null): RedirectResponse
    {
        try {
            $action();
        } catch (TransferException $e) {
            return back()->with('error', $e->getMessage());
        }

        return ($redirect ? redirect($redirect) : back())->with('status', $success);
    }
}
