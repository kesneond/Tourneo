<?php

namespace App\Http\Controllers;

use App\Models\Game;
use Illuminate\Http\Request;

class GameController extends Controller
{
    public function update(Request $request, $id)
    {
        $game = Game::findOrFail($id);

        $validated = $request->validate([
            'score1' => 'required|integer|min:0',
            'score2' => 'required|integer|min:0',
        ]);

        $game->update([
            'score1' => $validated['score1'],
            'score2' => $validated['score2'],
            'status' => 'finished'
        ]);

        return response()->json($game);
    }
}