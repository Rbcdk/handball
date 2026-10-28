<?php

namespace Tests\Feature;

use App\Game\Academy;
use App\Models\Club;
use App\Models\Fixture;
use App\Models\News;
use App\Models\Player;
use App\Models\Prospect;
use App\Models\Season;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademyTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('game:new-world', ['--seed' => 9])->assertSuccessful();
        $user = User::create(['name' => 'Manager', 'email' => 'y@example.com', 'password' => 'secret-password']);
        $this->club = Club::whereHas('league', fn ($q) => $q->where('tier', 2))->first();
        $this->club->update(['user_id' => $user->id, 'balance' => 3_000_000]);
        $this->actingAs($user);
    }

    public function test_the_first_visit_brings_the_first_prospects(): void
    {
        $this->get(route('academy'))->assertOk()->assertSee('Youth academy')->assertSee('Promote to first team');
        $this->assertSame(2, $this->club->prospects()->count());
        $this->assertTrue(News::where('club_id', $this->club->id)->where('kind', 'academy')->exists());
        // No second intake in the same season.
        $this->get(route('academy'));
        $this->assertSame(2, $this->club->prospects()->count());
        foreach ($this->club->prospects as $p) {
            $this->assertContains($p->age, [16, 17]);
        }
    }

    public function test_a_promoted_prospect_joins_the_squad_and_keeps_his_face(): void
    {
        $this->get(route('academy'));
        $p = $this->club->prospects()->first();
        $before = $this->club->players()->count();

        $this->post(route('academy.promote', $p))->assertRedirect(route('academy'));

        $this->assertSame($before + 1, $this->club->players()->count());
        $player = Player::where('first_name', $p->first_name)->where('last_name', $p->last_name)->first();
        $this->assertSame($p->potential, $player->potential);
        $this->assertGreaterThan(0, $player->wage);
        $this->assertSame(1 + 3, $player->contract_until);
        $this->assertSame($p->face, $player->face);
        $this->assertNull(Prospect::find($p->id));
    }

    public function test_cannot_promote_into_a_full_squad_or_from_another_club(): void
    {
        $this->get(route('academy'));
        $p = $this->club->prospects()->first();
        $other = Club::where('id', '!=', $this->club->id)->first();
        $this->post(route('academy.promote', Prospect::create([...$p->toArray(), 'id' => null, 'club_id' => $other->id, 'potential' => 60])))->assertSessionHas('error');

        $free = Player::whereNull('club_id')->take(20 - $this->club->players()->count())->pluck('id');
        Player::whereIn('id', $free)->update(['club_id' => $this->club->id]);
        $this->post(route('academy.promote', $p))->assertSessionHas('error');
    }

    public function test_upgrades_cost_money_one_level_at_a_time(): void
    {
        $this->post(route('academy.level'), ['level' => 3])->assertSessionHas('error');
        $this->post(route('academy.level'), ['level' => 2])->assertRedirect(route('academy'));
        $this->assertSame(2, $this->club->fresh()->academy_level);
        $this->assertSame(3_000_000 - Academy::LEVELS[2]['cost'], $this->club->fresh()->balance);
        $this->post(route('academy.level'), ['level' => 1]);
        $this->assertSame(1, $this->club->fresh()->academy_level);
        $this->assertSame(3_000_000 - Academy::LEVELS[2]['cost'], $this->club->fresh()->balance);
    }

    public function test_prospects_train_on_match_days_and_leave_at_twenty(): void
    {
        $this->get(route('academy'));
        $p = $this->club->prospects()->first();
        $sum = fn (Prospect $x) => array_sum($x->only(Prospect::SKILLS)) * 100 + $x->training_xp;
        $before = $sum($p);

        $fixture = Fixture::involving($this->club)->upcoming()->orderBy('scheduled_at')->first();
        Fixture::upcoming()->where('scheduled_at', '<=', $fixture->scheduled_at)->update(['scheduled_at' => now()->subSecond()]);
        $this->artisan('game:play-due')->assertSuccessful();

        $this->assertGreaterThan($before, $sum($p->fresh()));
        $this->assertTrue(Transaction::where('club_id', $this->club->id)->where('type', 'academy')->exists());

        $p->update(['age' => 19]);
        app(Academy::class)->endOfSeason();
        $this->assertNull(Prospect::find($p->id));
        $this->assertSame(1, $this->club->prospects()->count());

        $next = Season::create(['number' => 2, 'status' => 'active']);
        $this->assertSame(2, app(Academy::class)->intake($this->club->fresh(), $next));
    }
}
