<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\MarketController;
use App\Http\Controllers\TeamController;
use Illuminate\Support\Facades\Route;

Route::get('/', [GameController::class, 'home'])->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store']);
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/choose-club', [GameController::class, 'chooseClub'])->name('clubs.choose');
    Route::post('/clubs/{club}/take-over', [GameController::class, 'takeOver'])->name('clubs.take-over');

    Route::middleware('club')->group(function () {
        Route::get('/office', [GameController::class, 'office'])->name('office');
        Route::get('/squad', [GameController::class, 'squad'])->name('squad');
        Route::get('/tactics', [GameController::class, 'tactics'])->name('tactics.edit');
        Route::put('/tactics', [GameController::class, 'updateTactics'])->name('tactics.update');
        Route::get('/fixtures', [GameController::class, 'fixtures'])->name('fixtures');
        Route::get('/lineup', [TeamController::class, 'lineup'])->name('lineup');
        Route::put('/lineup', [TeamController::class, 'updateLineup'])->name('lineup.update');
        Route::post('/lineup/reset', [TeamController::class, 'resetLineup'])->name('lineup.reset');
        Route::get('/training', [TeamController::class, 'training'])->name('training');
        Route::put('/training', [TeamController::class, 'updateTraining'])->name('training.update');
        Route::post('/guide/dismiss', [GameController::class, 'dismissGuide'])->name('guide.dismiss');

        Route::get('/transfers', [MarketController::class, 'index'])->name('transfers');
        Route::post('/players/{player}/list', [MarketController::class, 'list'])->name('players.list');
        Route::post('/players/{player}/sign', [MarketController::class, 'sign'])->name('players.sign');
        Route::post('/players/{player}/extend', [MarketController::class, 'extend'])->name('players.extend');
        Route::post('/players/{player}/release', [MarketController::class, 'release'])->name('players.release');
        Route::post('/listings/{listing}/cancel', [MarketController::class, 'cancel'])->name('listings.cancel');
        Route::post('/listings/{listing}/buy', [MarketController::class, 'buy'])->name('listings.buy');
        Route::post('/listings/{listing}/bid', [MarketController::class, 'bid'])->name('listings.bid');
        Route::get('/finances', [MarketController::class, 'finances'])->name('finances');
    });
});

// Public pages
Route::get('/how-to-play', [GameController::class, 'help'])->name('help');
Route::get('/leagues/{league?}', [GameController::class, 'league'])->name('leagues.show');
Route::get('/clubs/{club}', [GameController::class, 'club'])->name('clubs.show');
Route::get('/matches/{fixture}', [GameController::class, 'match'])->name('matches.show');
Route::get('/players/{player}', [MarketController::class, 'player'])->name('players.show');
