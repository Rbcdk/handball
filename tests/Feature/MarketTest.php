<?php

namespace Tests\Feature;

use App\Game\AiMarket;
use App\Game\Economy;
use App\Game\TransferException;
use App\Game\TransferMarket;
use App\Models\Club;
use App\Models\Fixture;
use App\Models\Player;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\TransferListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketTest extends TestCase
{
    use RefreshDatabase;

    private TransferMarket $market;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('game:new-world', ['--seed' => 7])->assertSuccessful();
        $this->market = app(TransferMarket::class);
    }

    private function manager(string $email = 'm@example.com', ?Club $club = null, string $ip = '10.0.0.1'): array
    {
        $user = User::create(['name' => 'Manager', 'email' => $email, 'password' => 'secret-password']);
        $user->forceFill(['last_ip' => $ip])->save();
        $club ??= Club::whereNull('user_id')->whereHas('league', fn ($q) => $q->where('tier', 2))->first();
        $club->update(['user_id' => $user->id]);

        return [$user, $club->fresh()];
    }

    private function benchPlayer(Club $club): Player
    {
        $starters = $club->startingLineup();

        return $club->players()->get()->first(fn (Player $p) => ! isset($starters[$p->id]) && ! $p->isGoalkeeper());
    }

    public function test_world_starts_with_money_wages_contracts_and_free_agents(): void
    {
        $club = Club::whereHas('league', fn ($q) => $q->where('tier', 2))->first();
        $this->assertSame(Economy::START_BALANCE[2], $club->balance);
        $this->assertSame(42, Transaction::where('type', 'start')->count());
        $this->assertSame(0, Player::whereNotNull('club_id')->where('wage', 0)->count());
        $this->assertSame(30, Player::whereNull('club_id')->count());
    }

    public function test_a_match_day_pays_tickets_sponsor_and_wages(): void
    {
        $this->artisan('game:fast-forward', ['rounds' => 1])->assertSuccessful();
        $fixture = Fixture::played()->first();
        $home = $fixture->home;

        $this->assertTrue(Transaction::where('club_id', $home->id)->where('type', 'tickets')->exists());
        $this->assertTrue(Transaction::where('club_id', $home->id)->where('type', 'sponsor')->exists());
        $this->assertTrue(Transaction::where('club_id', $home->id)->where('type', 'wages')->where('amount', '<', 0)->exists());
        // The log adds up to the balance.
        $this->assertSame($home->fresh()->balance, (int) Transaction::where('club_id', $home->id)->sum('amount'));
    }

    public function test_fixed_price_sale_moves_player_and_money(): void
    {
        [, $seller] = $this->manager();
        $buyer = Club::whereNull('user_id')->where('id', '!=', $seller->id)->first();
        $buyer->update(['balance' => 10_000_000]);
        $player = $this->benchPlayer($seller);
        $price = $player->marketValue();

        $listing = $this->market->list($seller, $player, 'fixed', $price);
        $this->market->buyNow($listing, $buyer);

        $this->assertSame($buyer->id, $player->fresh()->club_id);
        $this->assertSame(Economy::START_BALANCE[2] + $price, $seller->fresh()->balance);
        $this->assertSame(10_000_000 - $price, $buyer->fresh()->balance);
        $this->assertSame('sold', $listing->fresh()->status);
        $this->assertTrue(Transfer::where('player_id', $player->id)->where('fee', $price)->exists());
    }

    public function test_auction_goes_to_the_highest_bidder_when_it_ends(): void
    {
        [, $seller] = $this->manager();
        [$a, $b] = Club::whereNull('user_id')->where('id', '!=', $seller->id)->take(2)->get()->all();
        $a->update(['balance' => 5_000_000]);
        $b->update(['balance' => 5_000_000]);
        $player = $this->benchPlayer($seller);
        $start = $player->marketValue();

        $listing = $this->market->list($seller, $player, 'auction', $start);
        $this->market->bid($listing, $a, $start);
        $this->market->bid($listing, $b, Economy::minimumBid($start, $start));

        $this->expectBidTooLow(fn () => $this->market->bid($listing, $a, $start + 1));

        $this->travel(25)->hours();
        $this->assertSame(1, $this->market->settleDue());
        $this->assertSame($b->id, $player->fresh()->club_id);
    }

    private function expectBidTooLow(callable $fn): void
    {
        try {
            $fn();
            $this->fail('A too-low bid was accepted.');
        } catch (TransferException $e) {
            $this->assertStringContainsString('at least', $e->getMessage());
        }
    }

    public function test_prices_must_be_near_market_value(): void
    {
        [, $club] = $this->manager();
        $player = $this->benchPlayer($club);
        $this->expectException(TransferException::class);
        $this->market->list($club, $player, 'fixed', 1_000);
    }

    public function test_squad_cannot_drop_below_the_minimum(): void
    {
        [, $club] = $this->manager();
        $players = $club->players()->where('position', '!=', 'GK')->get();
        foreach ($players->take($club->players()->count() - Economy::SQUAD_MIN) as $p) {
            $p->update(['club_id' => null]);
        }
        $this->expectException(TransferException::class);
        $this->market->list($club->fresh(), $this->benchPlayer($club->fresh()), 'fixed', $this->benchPlayer($club->fresh())->marketValue());
    }

    public function test_a_club_keeps_two_goalkeepers(): void
    {
        [, $club] = $this->manager();
        $keeper = $club->players()->where('position', 'GK')->first();
        $this->expectException(TransferException::class);
        $this->market->list($club, $keeper, 'fixed', $keeper->marketValue());
    }

    public function test_managers_on_the_same_connection_cannot_trade(): void
    {
        [, $seller] = $this->manager('a@example.com', null, '10.0.0.9');
        $other = Club::whereNull('user_id')->where('id', '!=', $seller->id)->first();
        [, $buyer] = $this->manager('b@example.com', $other, '10.0.0.9');
        $buyer->update(['balance' => 10_000_000]);
        $player = $this->benchPlayer($seller);
        $listing = $this->market->list($seller, $player, 'fixed', $player->marketValue());

        $this->expectException(TransferException::class);
        $this->market->buyNow($listing, $buyer->fresh());
    }

    public function test_free_agent_signs_for_his_wage(): void
    {
        [, $club] = $this->manager();
        $club->players()->first()->update(['club_id' => null]);
        $agent = Player::whereNull('club_id')->orderBy('id')->first();
        $this->market->signFreeAgent($agent, $club->fresh(), 2);
        $this->assertSame($club->id, $agent->fresh()->club_id);
        $this->assertSame($agent->fresh()->fairWage(), $agent->fresh()->wage);
    }

    public function test_ai_clubs_list_and_buy_players(): void
    {
        Club::query()->update(['balance' => 5_000_000]);
        $ai = app(AiMarket::class);
        for ($i = 0; $i < 4; $i++) {
            $ai->tick(fn () => true);
        }
        $this->assertGreaterThan(0, TransferListing::count());
    }

    public function test_pages_render(): void
    {
        [$user, $club] = $this->manager();
        $this->actingAs($user);
        $own = $this->benchPlayer($club);
        $aiClub = Club::whereNull('user_id')->first();
        $forSale = $this->benchPlayer($aiClub);
        $this->market->list($aiClub, $forSale, 'fixed', $forSale->marketValue());

        foreach (['market', 'free', 'mine'] as $tab) {
            $this->get(route('transfers', ['tab' => $tab]))->assertOk();
        }
        $this->get(route('players.show', $own))->assertOk()->assertSee('Put up for sale');
        $this->get(route('players.show', Player::whereNull('club_id')->first()))->assertOk()->assertSee('Sign him');
        $this->get(route('players.show', TransferListing::open()->first()->player))->assertOk()->assertSee('Buy for');
        $this->get(route('finances'))->assertOk()->assertSee('Money in the bank');

        $this->post(route('players.list', $own), ['kind' => 'auction', 'price' => $own->marketValue()])->assertSessionHas('status');
        $this->post(route('players.extend', $club->players()->where('age', '<', 34)->first()), ['seasons' => 2])->assertSessionHas('status');
    }

    public function test_contracts_end_and_prizes_are_paid_at_season_end(): void
    {
        [, $club] = $this->manager();
        $leaving = $this->benchPlayer($club);
        $leaving->update(['contract_until' => 1]);
        $club->players()->where('id', '!=', $leaving->id)->update(['contract_until' => 3]);

        $this->artisan('game:fast-forward', ['rounds' => 26])->assertSuccessful();

        $this->assertNull($leaving->fresh()?->club_id);
        $this->assertTrue(Transaction::where('club_id', $club->id)->where('type', 'prize')->exists());
        $this->assertGreaterThanOrEqual(30, Player::whereNull('club_id')->count());
    }
}
