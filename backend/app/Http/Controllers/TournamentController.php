<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Models\Game;
use Illuminate\Http\Request;

class TournamentController extends Controller
{
    // Seznam turnajů
    public function index()
    {
        return Tournament::orderBy('created_at', 'desc')->get();
    }

    // Vytvoření turnaje
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'format' => 'required|in:round_robin', // Zatím jen tento formát
        ]);

        $tournament = Tournament::create($validated);

        return response()->json($tournament, 201);
    }

    // Detail turnaje (včetně hráčů a zápasů)
    public function show($id)
    {
        return Tournament::with(['players', 'games.player1', 'games.player2'])
            ->findOrFail($id);
    }

    // Smazání turnaje
    public function destroy($id)
    {
        Tournament::findOrFail($id)->delete();
        return response()->json(null, 204);
    }

    // --- LOGIKA GENEROVÁNÍ ZÁPASŮ (Round Robin) ---
    public function generateGames($id)
    {
        $tournament = Tournament::with('players')->findOrFail($id);

        if ($tournament->players->count() < 2) {
            return response()->json(['error' => 'Potřebujete alespoň 2 hráče.'], 400);
        }

        // Smazat staré zápasy, pokud existují (restart)
        $tournament->games()->delete();

        $players = $tournament->players;
        $count = $players->count();
        $gamesCreated = 0;

        // Dvojitý cyklus: Každý s každým
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                Game::create([
                    'tournament_id' => $tournament->id,
                    'player1_id' => $players[$i]->id,
                    'player2_id' => $players[$j]->id,
                    'status' => 'scheduled'
                ]);
                $gamesCreated++;
            }
        }

        $tournament->update(['status' => 'in_progress']);

        return response()->json(['message' => "Bylo vytvořeno $gamesCreated zápasů."]);
    }

    // --- VÝPOČET TABULKY ---
    public function standings($id)
    {
        $tournament = Tournament::with(['players', 'games'])->findOrFail($id);
        
        // Projdeme všechny hráče a spočítáme jim statistiky
        $standings = $tournament->players->map(function ($player) use ($tournament) {
            
            $matches = $tournament->games->filter(function ($game) use ($player) {
                return $game->status === 'finished' && 
                       ($game->player1_id === $player->id || $game->player2_id === $player->id);
            });

            $points = 0;
            $played = 0;
            $scoreDiff = 0; // Rozdíl skóre

            foreach ($matches as $game) {
                $played++;
                $isPlayer1 = $game->player1_id === $player->id;
                
                $myScore = $isPlayer1 ? $game->score1 : $game->score2;
                $opponentScore = $isPlayer1 ? $game->score2 : $game->score1;

                $scoreDiff += ($myScore - $opponentScore);

                if ($myScore > $opponentScore) {
                    $points += 3; // Výhra
                } elseif ($myScore === $opponentScore) {
                    $points += 1; // Remíza
                }
            }

            return [
                'id' => $player->id,
                'name' => $player->name,
                'matches_played' => $played,
                'points' => $points,
                'score_diff' => $scoreDiff
            ];
        });

        // Seřadit podle bodů (sestupně)
        return response()->json($standings->sortByDesc('points')->values());
    }
}