<?php

namespace App\Providers;

use App\Game\Standings;
use App\Models\Fixture;
use App\Models\Season;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // The court band shows the signed-in manager's club, table position and next match.
        View::composer('layouts.app', function ($view) {
            $club = auth()->user()?->club;
            if (! $club) {
                $view->with(['myClub' => null, 'myPosition' => null, 'myNext' => null]);

                return;
            }
            $club->loadMissing('league');
            $season = Season::current();
            $row = $season
                ? app(Standings::class)->table($season, $club->league)->first(fn ($r) => $r->club->id === $club->id)
                : null;
            $next = Fixture::involving($club)->upcoming()->with(['home', 'away'])->orderBy('scheduled_at')->first();
            $view->with(['myClub' => $club, 'myPosition' => $row?->position, 'myNext' => $next]);
        });
    }
}
