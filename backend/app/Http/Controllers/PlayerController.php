<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\Tournament;
use Illuminate\Http\Request;

class PlayerController extends Controller
{
    public function store(Request $request, $tournamentId)
    {
        $request->validate([
            'name' => 'required|string|max:255'
        ]);

        $tournament = Tournament::findOrFail($tournamentId);

        // Kontrola, zda už turnaj neběží
        if ($tournament->status !== 'draft') {
            return response()->json(['error' => 'Nelze přidat hráče do běžícího turnaje.'], 403);
        }

        $player = $tournament->players()->create([
            'name' => $request->name
        ]);

        return response()->json($player, 201);
    }
}