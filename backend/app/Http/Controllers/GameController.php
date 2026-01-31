<?php

namespace App\Http\Controllers;

use App\Models\Game;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GameController extends Controller
{
    public function update(Request $request, Game $game)
    {
        $validated = $request->validate([
            'score1' => 'required|integer|min:0',
            'score2' => 'required|integer|min:0',
        ]);

        // --- POJISTKA PRO MAGII ---
        // Toto zajistí, že i když Laravel model "vytáhl" z cache nebo ho načetl líně,
        // teď se natvrdo podívá do DB pro turnaj.
        $game->load('tournament');
        // -------------------------

        // Teď už $game->tournament musí existovat
        if (!$game->tournament) {
            return response()->json(['error' => 'Kritická chyba: Zápas nemá přiřazený turnaj.'], 500);
        }

        $game->update([
            'score1' => $validated['score1'],
            'score2' => $validated['score2'],
            'status' => 'finished',
        ]);

        // Kontrola konce turnaje
        $hasUnfinishedGames = $game->tournament->games()->where('status', '!=', 'finished')->exists();

        if (!$hasUnfinishedGames) {
            $game->tournament->update(['status' => 'finished']);
        }

        return response()->json($game);
    }

    public function assignVenue(Request $request, Game $game)
    {
        $validated = $request->validate([
            'venue' => 'required|integer|min:1'
        ]);

        // 1. Kontrola: Je volný stůl?
        $isVenueOccupied = Game::where('tournament_id', $game->tournament_id)
            ->where('venue', $validated['venue'])
            ->where('status', 'in_progress')
            ->exists();

        if ($isVenueOccupied) {
            return response()->json(['error' => 'Tento stůl je již obsazen!'], 409);
        }

        // 2. NOVÁ KONTROLA: Hraje už některý z hráčů jinde?
        // Hledáme zápas, který běží (in_progress) A zárověn v něm hraje jeden z našich hráčů
        $isPlayerBusy = Game::where('tournament_id', $game->tournament_id)
            ->where('status', 'in_progress')
            ->where(function ($query) use ($game) {
                $query->where('player1_id', $game->player1_id)
                    ->orWhere('player2_id', $game->player1_id)
                    ->orWhere('player1_id', $game->player2_id)
                    ->orWhere('player2_id', $game->player2_id);
            })
            ->exists();

        if ($isPlayerBusy) {
            return response()->json(['error' => 'Jeden z hráčů právě hraje jiný zápas!'], 409);
        }

        // Vše OK, přiřadíme
        $game->update([
            'status' => 'in_progress',
            'venue' => $validated['venue']
        ]);

        return response()->json($game);
    }

    public function unassignVenue(Game $game)
    {
        // Zápas vrátíme do stavu 'scheduled' (čeká) a sebereme mu stůl
        $game->update([
            'status' => 'scheduled',
            'venue' => null
        ]);

        return response()->json($game);
    }
}