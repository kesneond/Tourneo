<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TournamentController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\GameController;

// 1. Turnaje (CRUD)
Route::get('/tournaments', [TournamentController::class, 'index']);           // Seznam
Route::post('/tournaments', [TournamentController::class, 'store']);          // Vytvořit
Route::get('/tournaments/{id}', [TournamentController::class, 'show']);       // Detail
Route::delete('/tournaments/{id}', [TournamentController::class, 'destroy']); // Smazat
Route::put('/tournaments/{tournament}', [TournamentController::class, 'update']);

// 2. Hráči (Přidání do turnaje)
Route::post('/tournaments/{id}/players', [PlayerController::class, 'store']);

// 3. Logika hry (Generování a výsledky)
Route::post('/tournaments/{id}/generate', [TournamentController::class, 'generateGames']);
Route::put('/games/{game}', [GameController::class, 'update']); // Zadání výsledku
Route::post('/games/{game}/assign', [GameController::class, 'assignVenue']);
Route::post('/games/{game}/unassign', [GameController::class, 'unassignVenue']);

// 4. Tabulka / Žebříček
Route::get('/tournaments/{id}/standings', [TournamentController::class, 'standings']);

Route::get('/test-connection', function () {
    return response()->json(['message' => 'Spojení funguje! Laravel zdraví Vue.']);
});