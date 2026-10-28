<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Fixture;
use App\Models\League;
use App\Models\MatchLog;
use App\Models\Player;
use App\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('game:new-world', ['--seed' => 42])->assertSuccessful();
    }

    public function test_world_is_created(): void
    {
        $this->assertSame(3, League::count());
        $this->assertSame(42, Club::count());
        $this->assertSame(42 * 16, Player::whereNotNull('club_id')->count());
        $this->assertSame(3 * 7 * 26, Fixture::count());
        $this->assertSame(42, Club::pluck('short')->unique()->count());
    }

    public function test_renaming_the_world_keeps_results_and_updates_replays(): void
    {
        $this->artisan('game:fast-forward', ['rounds' => 1])->assertSuccessful();
        $fixture = Fixture::played()->first();
        $before = $fixture->home->name;

        $this->artisan('game:rename-world', ['--seed' => 99])->assertSuccessful();

        $fixture = $fixture->fresh(['home', 'matchLog']);
        $this->assertNotSame($before, $fixture->home->name);
        $this->assertSame($fixture->home->name, $fixture->matchLog->data()['home']['name']);
        $this->assertNotNull($fixture->home_score);
        $this->assertSame(42, Club::pluck('short')->unique()->count());
    }

    public function test_guest_pages_render(): void
    {
        $this->get('/')->assertOk()->assertSee('One match every evening.');
        $this->get(route('leagues.show'))->assertOk();
        $this->get(route('clubs.show', Club::first()))->assertOk();
        $this->get(route('matches.show', Fixture::first()))->assertOk()->assertSee('revealed when the match starts');
        $this->get(route('office'))->assertRedirect(route('login'));
    }

    public function test_dark_mode_is_the_default_and_the_switch_is_remembered(): void
    {
        $this->get('/')->assertOk()->assertSee('data-theme="dark"', false)->assertSee('Light mode');
        $this->withUnencryptedCookie('theme', 'light')->get('/')->assertOk()->assertSee('data-theme="light"', false)->assertSee('Dark mode');
    }

    public function test_a_manager_registers_takes_over_a_club_and_sets_tactics(): void
    {
        $this->post(route('register'), [
            'name' => 'Rasmus', 'email' => 'rasmus@example.com',
            'password' => 'secret-password', 'password_confirmation' => 'secret-password',
        ])->assertRedirect(route('clubs.choose'));

        $this->get(route('office'))->assertRedirect(route('clubs.choose'));
        $this->get(route('clubs.choose'))->assertOk();

        $topClub = Club::whereHas('league', fn ($q) => $q->where('tier', 1))->first();
        $this->post(route('clubs.take-over', $topClub))->assertRedirect(route('clubs.choose'));

        $club = Club::whereHas('league', fn ($q) => $q->where('tier', 2))->first();
        $this->post(route('clubs.take-over', $club))->assertRedirect(route('office'));
        $this->assertSame(User::first()->id, $club->fresh()->user_id);

        $this->get(route('office'))->assertOk()->assertSee($club->name);
        $this->get(route('squad'))->assertOk();
        $this->get(route('fixtures'))->assertOk();
        $this->get(route('tactics.edit'))->assertOk();

        $tactics = ['defense' => '3-2-1', 'tempo' => 'fast', 'focus' => 'wings', 'sevenVsSix' => 'never', 'rotation' => 'high'];
        $this->put(route('tactics.update'), $tactics)->assertRedirect(route('tactics.edit'));
        $this->assertSame($tactics, $club->fresh()->tactics);

        $this->put(route('tactics.update'), [...$tactics, 'defense' => '4-2'])->assertSessionHasErrors('defense');
    }

    public function test_the_getting_started_guide_ticks_off_and_can_be_hidden(): void
    {
        $user = User::create(['name' => 'Rasmus', 'email' => 'r@example.com', 'password' => 'secret-password']);
        $club = Club::whereHas('league', fn ($q) => $q->where('tier', 2))->first();
        $club->update(['user_id' => $user->id]);
        $this->actingAs($user);

        $this->get(route('office'))->assertOk()->assertSee('Getting started')->assertSee('0 of 4 done');
        $this->get(route('squad'))->assertOk()->assertSee('Starts')->assertSee('Bench');
        $this->get(route('leagues.show'))->assertOk();
        $this->get(route('office'))->assertSee('2 of 4 done');

        $this->post(route('guide.dismiss'))->assertRedirect(route('office'));
        $this->get(route('office'))->assertOk()->assertDontSee('Getting started');
    }

    public function test_how_to_play_page_renders(): void
    {
        $this->get(route('help'))->assertOk()->assertSee('A match day')->assertSee('Reading a replay');
    }

    public function test_the_squad_page_marks_seven_starters(): void
    {
        $club = Club::first();
        $starters = $club->startingLineup();
        $this->assertCount(7, $starters);
        $this->assertContains('GK', $starters);
    }

    public function test_due_matches_are_played_and_can_be_replayed(): void
    {
        $this->travelTo(Fixture::min('scheduled_at'));
        $this->travel(1)->minutes();
        $this->artisan('game:play-due')->assertSuccessful();

        $played = Fixture::played()->get();
        $this->assertCount(21, $played);
        $this->assertSame(21, MatchLog::count());

        $fixture = $played->first();
        $log = $fixture->matchLog->data();
        $this->assertSame([$fixture->home_score, $fixture->away_score], $log['score']);

        $this->get(route('matches.show', $fixture))->assertOk()->assertSee('match-data', false);
        $this->get('/')->assertOk()->assertSee('Watch with commentary');
        $this->assertDatabaseHas('player_season_stats', ['season_id' => Season::current()->id]);
    }

    public function test_a_full_season_ends_with_promotion_and_a_new_season(): void
    {
        $this->artisan('game:fast-forward', ['rounds' => 26])->assertSuccessful();

        $this->assertSame('finished', Season::where('number', 1)->value('status'));
        $this->assertNotNull(Season::where('number', 2)->where('status', 'active')->first());
        $this->assertSame(3 * 7 * 26, Fixture::where('season_id', Season::current()->id)->count());
        foreach (League::all() as $league) {
            $this->assertSame(14, $league->clubs()->count());
        }
        foreach (Club::withCount('players')->get() as $club) {
            $this->assertGreaterThanOrEqual(\App\Game\Economy::SQUAD_MIN, $club->players_count);
            $this->assertLessThanOrEqual(\App\Game\Economy::SQUAD_MAX, $club->players_count);
        }
    }
}
