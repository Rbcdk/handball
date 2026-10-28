<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A fresh database before game:new-world has run. */
class EmptyWorldTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_explain_how_to_create_the_world(): void
    {
        $this->get('/')->assertOk();
        $this->get(route('leagues.show'))->assertOk()->assertSee('php artisan game:new-world');

        $this->actingAs(User::create(['name' => 'M', 'email' => 'm@example.com', 'password' => 'secret-password']));
        $this->get(route('clubs.choose'))->assertOk()->assertSee('php artisan game:new-world');
    }

    public function test_rename_refuses_without_a_world(): void
    {
        $this->artisan('game:rename-world')->assertFailed();
    }
}
