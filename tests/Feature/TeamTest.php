<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Fixture;
use App\Models\News;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('game:new-world', ['--seed' => 12])->assertSuccessful();
        $this->user = User::create(['name' => 'Manager', 'email' => 'm@example.com', 'password' => 'secret-password']);
        $this->club = Club::whereHas('league', fn ($q) => $q->where('tier', 2))->first();
        $this->club->update(['user_id' => $this->user->id]);
        $this->actingAs($this->user);
    }

    private function playNextRoundOfMyClub(): Fixture
    {
        $fixture = Fixture::involving($this->club)->upcoming()->orderBy('scheduled_at')->first();
        Fixture::upcoming()->where('scheduled_at', '<=', $fixture->scheduled_at)->update(['scheduled_at' => now()->subSecond()]);
        $this->artisan('game:play-due')->assertSuccessful();

        return $fixture->fresh();
    }

    public function test_pages_render(): void
    {
        $this->get(route('lineup'))->assertOk()->assertSee('Starting seven')->assertSee('Automatic');
        $this->get(route('training'))->assertOk()->assertSee('Intensity for the whole squad');
        $this->get(route('help'))->assertOk()->assertSee('Line-up, training and fitness');
        $this->get(route('tactics.edit'))->assertOk()->assertSee('data-formation', false);
    }

    public function test_every_player_has_a_portrait_that_never_changes(): void
    {
        $p = $this->club->players()->first();
        $svg = \App\Support\Avatar::svg($p, 40);
        $this->assertStringStartsWith('<svg class="avatar"', $svg);
        $this->assertStringContainsString($this->club->color, $svg);
        $this->assertSame($svg, \App\Support\Avatar::draw($p->id, $p->number, $p->age, $this->club->color, 40, $p->name));
        $this->assertNotSame($svg, \App\Support\Avatar::draw($p->id + 1, $p->number, $p->age, $this->club->color, 40, $p->name));
        $this->get(route('squad'))->assertOk()->assertSee('class="avatar"', false);
        $this->get(route('lineup'))->assertOk()->assertSee('data-card="'.$p->id.'"', false);
    }

    public function test_a_chosen_line_up_is_used(): void
    {
        $bench = $this->club->players()->where('position', 'LW')->get()
            ->first(fn (Player $p) => ($this->club->startingLineup()[$p->id] ?? null) === null);
        $this->put(route('lineup.update'), ['slots' => ['LW' => $bench->id]])->assertRedirect(route('lineup'));

        $this->assertSame('LW', $this->club->fresh()->startingLineup()[$bench->id]);
    }

    public function test_the_same_player_cannot_play_two_positions(): void
    {
        $p = $this->club->players()->where('position', 'LB')->first();
        $this->put(route('lineup.update'), ['slots' => ['LB' => $p->id, 'RB' => $p->id]])->assertSessionHasErrors('slots');
    }

    public function test_an_injured_player_is_replaced_automatically(): void
    {
        $p = $this->club->players()->where('position', 'CB')->first();
        $this->put(route('lineup.update'), ['slots' => ['CB' => $p->id]]);
        $p->update(['injured_days' => 3, 'injury' => 'Ankle sprain']);

        $this->assertArrayNotHasKey($p->id, $this->club->fresh()->startingLineup());
    }

    public function test_training_plan_is_saved(): void
    {
        $p = $this->club->players()->where('position', '!=', 'GK')->first();
        $this->put(route('training.update'), ['intensity' => 'hard', 'focus' => [$p->id => 'shooting']])->assertRedirect(route('training'));
        $this->assertSame('hard', $this->club->fresh()->training_intensity);
        $this->assertSame('shooting', $p->fresh()->training_focus);
    }

    public function test_after_a_match_players_tire_train_and_injuries_heal(): void
    {
        $young = $this->club->players()->where('position', '!=', 'GK')->first();
        $young->update(['age' => 19, 'potential' => 95, 'training_xp' => 90, 'training_focus' => 'shooting']);
        $hurt = $this->club->players()->where('id', '!=', $young->id)->where('position', '!=', 'GK')->first();
        $hurt->update(['injured_days' => 1, 'injury' => 'Calf strain']);
        $before = $young->only(['shooting', 'jumping', 'composure']);

        $this->playNextRoundOfMyClub();

        $young->refresh();
        $after = $young->only(['shooting', 'jumping', 'composure']);
        $this->assertGreaterThan(array_sum($before), array_sum($after), 'A 19-year-old close to a skill point should improve after a match.');
        $this->assertSame(0, $hurt->fresh()->injured_days);
        $this->assertTrue(News::where('club_id', $this->club->id)->where('kind', 'recovered')->exists());

        $starters = array_keys($this->club->fresh()->startingLineup());
        $this->assertLessThan(100 * count($starters) + 1, Player::whereIn('id', $starters)->sum('fitness'));
        $this->get(route('office'))->assertOk()->assertSee('Club news');
    }
}
