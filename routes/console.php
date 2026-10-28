<?php

use Illuminate\Support\Facades\Schedule;

// Plays every fixture whose kick-off has passed. Run `php artisan schedule:work` locally,
// or add the standard `* * * * * php artisan schedule:run` cron entry on the server.
Schedule::command('game:play-due')->everyMinute()->withoutOverlapping();

// Transfer market: close finished auctions every minute; computer clubs trade once an hour.
Schedule::command('game:market')->everyMinute()->withoutOverlapping();
Schedule::command('game:market --ai')->hourly()->withoutOverlapping();
