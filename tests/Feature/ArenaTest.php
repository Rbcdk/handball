<?php

namespace Tests\Feature;

use App\Game\Arena;
use App\Game\Economy;
use App\Models\Club;
use App\Models\Fixture;
use App\Models\News;
use App\Models\Season;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArenaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('game:new-world', ['--seed' => 7])->assertSuccessful();
        $this->user = User::create(['name' => 'Manager', 'email' => 'a@example.com', 'password' => 'secret-password']);
        $this->club = Club::whereHas('league', fn ($q) => $q->where('tier', 2))->first();
        $this->club->update(['user_id' => $this->user->id, 'balance' => 2_000_000]);
        $this->actingAs($this->user);
    }

    private function playRound(): void
    {
        $next = Fixture::upcoming()->orderBy('scheduled_at')->first();
        Fixture::upcoming()->where('scheduled_at', '<=', $next->scheduled_at)->update(['scheduled_at' => now()->subSecond()]);
        $this->artisan('game:play-due')->assertSuccessful();
    }

    public function test_the_page_shows_seats_fans_and_options(): void
    {
        $this->get(route('arena'))->assertOk()->assertSee('Build more seats')->assertSee('+500 seats')->assertSee('Next home match');
    }

    public function test_building_costs_money_and_finishes_after_some_match_days(): void
    {
        $cost = Economy::expansionCost(1300, 250);
        $this->post(route('arena.expand'), ['seats' => 250])->assertRedirect(route('arena'));
        $club = $this->club->fresh();
        $this->assertSame(2_000_000 - $cost, $club->balance);
        $this->assertSame(250, $club->arena_expansion);
        $this->assertTrue(Transaction::where('club_id', $club->id)->where('type', 'arena_build')->exists());

        // Only one building project at a time.
        $this->post(route('arena.expand'), ['seats' => 500])->assertSessionHas('error');

        for ($i = 0; $i < Economy::EXPANSIONS[250]; $i++) {
            $this->playRound();
        }
        $club->refresh();
        $this->assertSame(1550, $club->arena_capacity);
        $this->assertNull($club->arena_expansion);
        $this->assertTrue(News::where('club_id', $club->id)->where('kind', 'arena')->exists());
    }

    public function test_cannot_build_without_the_money(): void
    {
        $this->club->update(['balance' => 1000]);
        $this->post(route('arena.expand'), ['seats' => 1000])->assertSessionHas('error');
        $this->assertSame(1300, $this->club->fresh()->arena_capacity);
    }

    public function test_matches_record_crowds_move_fans_and_charge_upkeep(): void
    {
        $this->playRound();
        $f = Fixture::whereNotNull('played_at')->first();
        $this->assertGreaterThan(0, $f->attendance);
        $this->assertLessThanOrEqual($f->home->arena_capacity, $f->attendance);
        $expected = Economy::fansAfter(Economy::START_CAPACITY[$f->league->tier], $f->resultFor($f->home));
        $this->assertSame($expected, $f->home->fresh()->fans);
        $this->assertTrue(Transaction::where('club_id', $f->home_club_id)->where('type', 'upkeep')->exists());
    }

    public function test_ai_clubs_expand_when_they_keep_selling_out(): void
    {
        $ai = Club::whereNull('user_id')->first();
        $ai->update(['balance' => 10_000_000, 'fans' => 5000]);
        $season = Season::current();
        Fixture::where('season_id', $season->id)->where('home_club_id', $ai->id)->update(['attendance' => $ai->arena_capacity]);

        app(Arena::class)->endOfSeason($season, [$ai->id], []);

        $ai->refresh();
        $this->assertSame(1000, $ai->arena_expansion);
        $this->assertSame(5750, $ai->fans);
    }
}
