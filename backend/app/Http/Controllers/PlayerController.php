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

    public function update(Request $request, Player $player)
    {
        $request->validate([
            'name' => 'required|string|max:255'
        ]);

        // Kontrola duplicity v rámci téhož turnaje (aby se nejmenoval jako někdo jiný)
        $exists = Player::where('tournament_id', $player->tournament_id)
            ->where('name', $request->name)
            ->where('id', '!=', $player->id) // Ignorovat sám sebe
            ->exists();

        if ($exists) {
            return response()->json(['error' => 'Hráč s tímto jménem v turnaji již existuje.'], 422);
        }

        $player->update(['name' => $request->name]);

        return response()->json($player);
    }
}