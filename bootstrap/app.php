<?php

use App\Http\Middleware\EnsureUserHasClub;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'club' => EnsureUserHasClub::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('office'));
        // The theme cookie is set by the browser (theme switch), so it can't be encrypted.
        $middleware->encryptCookies(except: ['theme']);
    })
    ->withCommands([
        \App\Console\Commands\NewWorld::class,
        \App\Console\Commands\PlayDueMatches::class,
        \App\Console\Commands\FastForward::class,
        \App\Console\Commands\Calibrate::class,
        \App\Console\Commands\RunMarket::class,
        \App\Console\Commands\SetupEconomy::class,
        \App\Console\Commands\RenameWorld::class,
    ])
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
