<?php

namespace App\Game;

use App\Models\Club;
use App\Models\Player;
use App\Models\Season;
use App\Models\Transfer;
use App\Models\TransferBid;
use App\Models\TransferListing;
use Illuminate\Support\Facades\DB;

/**
 * The transfer market. Sellers choose per player:
 *  - auction: bids for 24 hours, the highest valid bid wins
 *  - fixed:   anyone can buy at the asking price straight away (listing runs 3 days)
 * Free agents (players without a club) can be signed for their wage only.
 */
final class TransferMarket
{
    public const FIXED_DAYS = 3;

    /** Bids in the last minutes push the deadline back, so nobody wins by sniping. */
    public const ANTI_SNIPE_MINUTES = 5;

    public function __construct(private Finance $finance)
    {
    }

    // ------------------------------------------------------------------
    // Selling
    // ------------------------------------------------------------------

    public function list(Club $seller, Player $player, string $kind, int $price): TransferListing
    {
        if (! in_array($kind, ['auction', 'fixed'], true)) {
            throw new TransferException('Choose auction or fixed price.');
        }
        if ((int) $player->club_id !== (int) $seller->id) {
            throw new TransferException("{$player->name} doesn't play for your club.");
        }
        if ($player->listing()->exists()) {
            throw new TransferException("{$player->name} is already on the transfer list.");
        }
        [$min, $max] = Economy::priceRange($player->marketValue());
        if ($price < $min || $price > $max) {
            throw new TransferException('The price must be between '.Economy::format($min).' and '.Economy::format($max).' (half to four times his market value).');
        }
        $left = $seller->players()->count() - $seller->listings()->open()->count();
        if ($left <= Economy::SQUAD_MIN) {
            throw new TransferException('You need at least '.Economy::SQUAD_MIN.' players. Sign someone before you sell more.');
        }
        $this->checkKeepsTwoGoalkeepers($seller, $player);

        return TransferListing::create([
            'player_id' => $player->id,
            'seller_club_id' => $seller->id,
            'kind' => $kind,
            'price' => $price,
            'ends_at' => $kind === 'auction' ? now()->addHours(Economy::AUCTION_HOURS) : now()->addDays(self::FIXED_DAYS),
        ]);
    }

    public function cancel(TransferListing $listing, Club $club): void
    {
        if ((int) $listing->seller_club_id !== (int) $club->id || $listing->status !== 'open') {
            throw new TransferException('This listing is no longer open.');
        }
        if ($listing->bids()->exists()) {
            throw new TransferException('There are bids on this player, so the auction must run to the end.');
        }
        $listing->update(['status' => 'cancelled', 'closed_at' => now()]);
    }

    // ------------------------------------------------------------------
    // Buying
    // ------------------------------------------------------------------

    public function buyNow(TransferListing $listing, Club $buyer): void
    {
        DB::transaction(function () use ($listing, $buyer) {
            $listing = TransferListing::lockForUpdate()->with(['player', 'seller'])->findOrFail($listing->id);
            if ($listing->status !== 'open' || $listing->isAuction()) {
                throw new TransferException('This player is no longer for sale at a fixed price.');
            }
            $this->checkBuyer($listing, $buyer, $listing->price);
            $this->complete($listing, $buyer, $listing->price);
        });
    }

    public function bid(TransferListing $listing, Club $buyer, int $amount): TransferBid
    {
        return DB::transaction(function () use ($listing, $buyer, $amount) {
            $listing = TransferListing::lockForUpdate()->with(['player', 'seller', 'highestBid'])->findOrFail($listing->id);
            if ($listing->status !== 'open' || ! $listing->isAuction() || $listing->ends_at->isPast()) {
                throw new TransferException('This auction has ended.');
            }
            $minimum = Economy::minimumBid($listing->price, $listing->highestBid?->amount);
            if ($amount < $minimum) {
                throw new TransferException('Your bid must be at least '.Economy::format($minimum).'.');
            }
            if ($listing->highestBid && (int) $listing->highestBid->club_id === (int) $buyer->id) {
                throw new TransferException('You already have the highest bid.');
            }
            $this->checkBuyer($listing, $buyer, $amount + $this->committed($buyer, $listing->id));

            if ($listing->ends_at->diffInMinutes(now(), true) < self::ANTI_SNIPE_MINUTES) {
                $listing->update(['ends_at' => now()->addMinutes(self::ANTI_SNIPE_MINUTES)]);
            }

            return TransferBid::create(['listing_id' => $listing->id, 'club_id' => $buyer->id, 'amount' => $amount]);
        });
    }

    public function signFreeAgent(Player $player, Club $club, int $seasons): void
    {
        DB::transaction(function () use ($player, $club, $seasons) {
            $player = Player::lockForUpdate()->findOrFail($player->id);
            if (! $player->isFreeAgent()) {
                throw new TransferException("{$player->name} has already signed for a club.");
            }
            $this->checkSquadSpace($club);
            $wage = $player->fairWage();
            if ($club->balance < $wage * 5) {
                throw new TransferException('You need at least five match days of his wage in the bank ('.Economy::format($wage * 5).').');
            }
            $season = Season::current();
            $player->update([
                'club_id' => $club->id,
                'number' => $this->freeNumber($club, $player->number),
                'wage' => $wage,
                'contract_until' => ($season?->number ?? 1) + max(1, min(3, $seasons)) - 1,
            ]);
            Transfer::create(['player_id' => $player->id, 'season_id' => $season?->id, 'to_club_id' => $club->id, 'fee' => 0, 'kind' => 'free_agent']);
        });
    }

    // ------------------------------------------------------------------
    // Contracts
    // ------------------------------------------------------------------

    /** New contract running 1 to 3 seasons beyond this one, at the wage he asks for now. */
    public function extendContract(Player $player, Club $club, int $seasons): void
    {
        if ((int) $player->club_id !== (int) $club->id) {
            throw new TransferException("{$player->name} doesn't play for your club.");
        }
        if ($player->age >= 34 && $seasons > 1) {
            throw new TransferException("{$player->name} is {$player->age} and only signs for one season at a time.");
        }
        $current = Season::current()?->number ?? 1;
        $player->update([
            'wage' => $player->fairWage(),
            'contract_until' => $current + max(1, min(3, $seasons)),
        ]);
    }

    /** Releases a player; the club pays him ten match days of wages. */
    public function release(Player $player, Club $club): void
    {
        DB::transaction(function () use ($player, $club) {
            if ((int) $player->club_id !== (int) $club->id) {
                throw new TransferException("{$player->name} doesn't play for your club.");
            }
            if ($player->listing()->exists()) {
                throw new TransferException('Take him off the transfer list first.');
            }
            if ($club->players()->count() <= Economy::SQUAD_MIN) {
                throw new TransferException('You need at least '.Economy::SQUAD_MIN.' players.');
            }
            $this->checkKeepsTwoGoalkeepers($club, $player);
            $this->finance->book($club, -$player->wage * 10, 'wages', "Released {$player->name}: ten match days of wages");
            Transfer::create(['player_id' => $player->id, 'season_id' => Season::current()?->id, 'from_club_id' => $club->id, 'fee' => 0, 'kind' => 'released']);
            $player->update(['club_id' => null]);
        });
    }

    // ------------------------------------------------------------------
    // Closing listings (scheduler, every minute)
    // ------------------------------------------------------------------

    /**
     * Closes ended auctions and expired fixed-price listings. Returns how many were closed.
     * $asOf lets the development fast-forward close listings as if time had passed.
     */
    public function settleDue(?\Carbon\CarbonInterface $asOf = null): int
    {
        $closed = 0;
        TransferListing::open()->where('ends_at', '<=', $asOf ?? now())->orderBy('ends_at')->each(function (TransferListing $listing) use (&$closed) {
            DB::transaction(function () use ($listing) {
                $listing = TransferListing::lockForUpdate()->with(['player', 'seller'])->find($listing->id);
                if (! $listing || $listing->status !== 'open') {
                    return;
                }
                if ($listing->isAuction()) {
                    // Highest bid first; skip bidders who can no longer pay or have no room.
                    foreach ($listing->bids()->with('club')->orderByDesc('amount')->orderBy('id')->get() as $bid) {
                        try {
                            $this->checkBuyer($listing, $bid->club, $bid->amount);
                            $this->complete($listing, $bid->club, $bid->amount);

                            return;
                        } catch (TransferException) {
                            continue;
                        }
                    }
                }
                $listing->update(['status' => 'expired', 'closed_at' => now()]);
            });
            $closed++;
        });

        return $closed;
    }

    // ------------------------------------------------------------------
    // Rules shared by every purchase
    // ------------------------------------------------------------------

    private function checkBuyer(TransferListing $listing, Club $buyer, int $amount): void
    {
        $buyer->refresh();
        if ((int) $buyer->id === (int) $listing->seller_club_id) {
            throw new TransferException("You can't buy your own player.");
        }
        if ($this->sameManagerNetwork($listing->seller, $buyer)) {
            throw new TransferException('Transfers between clubs managed from the same internet connection are blocked.');
        }
        $this->checkSquadSpace($buyer);
        if ($buyer->balance < $amount) {
            throw new TransferException('You don\'t have enough money. Your balance is '.Economy::format($buyer->balance).'.');
        }
    }

    private function checkKeepsTwoGoalkeepers(Club $club, Player $player): void
    {
        if (! $player->isGoalkeeper()) {
            return;
        }
        $keepers = $club->players()->where('position', 'GK')->pluck('id');
        $listed = $club->listings()->open()->whereIn('player_id', $keepers)->count();
        if ($keepers->count() - $listed <= 2) {
            throw new TransferException('You need two goalkeepers. Sign another one before you let him go.');
        }
    }

    private function checkSquadSpace(Club $club): void
    {
        if ($club->players()->count() >= Economy::SQUAD_MAX) {
            throw new TransferException('Your squad is full ('.Economy::SQUAD_MAX.' players). Sell or release someone first.');
        }
    }

    /** Money this club has promised in other auctions where it is the highest bidder. */
    private function committed(Club $club, int $exceptListingId): int
    {
        $total = 0;
        foreach (TransferListing::open()->where('kind', 'auction')->where('id', '!=', $exceptListingId)->with('highestBid')->get() as $l) {
            if ($l->highestBid && (int) $l->highestBid->club_id === (int) $club->id) {
                $total += $l->highestBid->amount;
            }
        }

        return $total;
    }

    /** Two human managers on the same IP address can't trade with each other (multi-account protection). */
    private function sameManagerNetwork(Club $a, Club $b): bool
    {
        $ipA = $a->user?->last_ip;
        $ipB = $b->user?->last_ip;

        return $ipA !== null && $ipA === $ipB;
    }

    private function complete(TransferListing $listing, Club $buyer, int $price): void
    {
        $player = $listing->player;
        $seller = $listing->seller;
        $season = Season::current();

        $this->finance->book($buyer, -$price, 'transfer_in', "Bought {$player->name} from {$seller->name}", $season);
        $this->finance->book($seller, $price, 'transfer_out', "Sold {$player->name} to {$buyer->name}", $season);

        $player->update(['club_id' => $buyer->id, 'number' => $this->freeNumber($buyer, $player->number)]);
        $listing->update(['status' => 'sold', 'buyer_club_id' => $buyer->id, 'final_price' => $price, 'closed_at' => now()]);
        Transfer::create([
            'player_id' => $player->id, 'season_id' => $season?->id, 'from_club_id' => $seller->id,
            'to_club_id' => $buyer->id, 'fee' => $price, 'kind' => $listing->kind,
        ]);
    }

    /** Keeps his shirt number if it's free at the new club, otherwise the lowest free one. */
    private function freeNumber(Club $club, int $wanted): int
    {
        $taken = $club->players()->pluck('number')->map(fn ($n) => (int) $n)->all();
        if (! in_array($wanted, $taken, true)) {
            return $wanted;
        }
        for ($n = 2; $n <= 99; $n++) {
            if ($n !== 16 && ! in_array($n, $taken, true)) {
                return $n;
            }
        }

        return $wanted;
    }
}
