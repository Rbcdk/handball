<?php

namespace App\Game;

/**
 * The game's money rules, as pure functions (no database), so they can be
 * balanced with plain PHP simulations. Amounts are whole gilden, the game's own currency.
 *
 * Rough shape of a season for an average second-tier club:
 *   income  ≈ tickets 1.1M + sponsor 0.5M + prize 0.1-0.4M
 *   costs   ≈ wages 1.0-2.3M (wage = market value / 140 per match day)
 * so a well-run club saves a few hundred thousand a season to spend on transfers.
 */
final class Economy
{
    /** Starting money for a club in each tier. */
    public const START_BALANCE = [1 => 1_500_000, 2 => 600_000];

    /** Seats in the arena at the start. */
    public const START_CAPACITY = [1 => 2_000, 2 => 1_300];

    /** Ticket price per seat. */
    public const TICKET_PRICE = [1 => 100, 2 => 95];

    /** Sponsor money for a whole season, paid in equal parts each match day. */
    public const SPONSOR = [1 => 1_200_000, 2 => 500_000];

    /** Prize money for first place; it falls in a straight line to LAST_PRIZE_SHARE of that for last place. */
    public const PRIZE_TOP = [1 => 1_200_000, 2 => 400_000];

    public const LAST_PRIZE_SHARE = 0.15;

    /** Squad size limits. */
    public const SQUAD_MIN = 14;

    public const SQUAD_MAX = 20;

    /** Listing prices must stay within these multiples of market value (stops gifting players). */
    public const MIN_PRICE_FACTOR = 0.5;

    public const MAX_PRICE_FACTOR = 4.0;

    public const AUCTION_HOURS = 24;

    /** A new bid must beat the highest bid by this share. */
    public const MIN_RAISE = 0.05;

    /**
     * What a player is worth. Grows fast with rating (×3 for every 10 points),
     * young players cost extra, older players less.
     */
    public static function marketValue(int $rating, int $age): int
    {
        $base = 100_000 * 3 ** (($rating - 50) / 10);
        $ageFactor = match (true) {
            $age <= 21 => 1.4,
            $age <= 24 => 1.2,
            $age <= 28 => 1.0,
            $age <= 31 => 0.75,
            default => 0.45,
        };

        return self::round(max(10_000, $base * $ageFactor), 5_000);
    }

    /** Wage per match day for a player of this value. A season has 26 match days. */
    public static function wage(int $marketValue): int
    {
        return self::round(max(500, $marketValue / 140), 100);
    }

    /**
     * Ticket income for one home match.
     *
     * @param  float  $form  0 (bottom of the table) to 1 (top): good teams draw bigger crowds
     */
    public static function ticketIncome(int $tier, int $capacity, float $form): int
    {
        $fill = min(1.0, 0.55 + 0.35 * $form);

        return self::round($capacity * $fill * self::TICKET_PRICE[$tier], 100);
    }

    /** Sponsor money paid each match day. */
    public static function sponsorPerRound(int $tier, int $rounds): int
    {
        return self::round(self::SPONSOR[$tier] / max(1, $rounds), 100);
    }

    /** Prize money for a final league position (1 = winner). */
    public static function prizeMoney(int $tier, int $position, int $clubs): int
    {
        $top = self::PRIZE_TOP[$tier];
        $share = $clubs <= 1 ? 1 : 1 - (1 - self::LAST_PRIZE_SHARE) * ($position - 1) / ($clubs - 1);

        return self::round($top * $share, 1_000);
    }

    /** Lowest and highest allowed listing price for a player. */
    public static function priceRange(int $marketValue): array
    {
        return [
            self::round($marketValue * self::MIN_PRICE_FACTOR, 1_000),
            self::round($marketValue * self::MAX_PRICE_FACTOR, 1_000),
        ];
    }

    /** Smallest valid next bid. */
    public static function minimumBid(int $startPrice, ?int $highestBid): int
    {
        if ($highestBid === null) {
            return $startPrice;
        }

        return self::round($highestBid * (1 + self::MIN_RAISE), 1_000);
    }

    /** The game's currency: invented, so no real money is implied. */
    public const CURRENCY = 'gilden';

    /** "1.250.000 gilden", with dots between thousands. */
    public static function format(int $amount): string
    {
        return ($amount < 0 ? '−' : '').number_format(abs($amount), 0, ',', '.').' '.self::CURRENCY;
    }

    private static function round(float $v, int $step): int
    {
        return (int) (round($v / $step) * $step);
    }
}
