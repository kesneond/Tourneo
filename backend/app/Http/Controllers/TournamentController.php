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

    public function update(Request $request, Tournament $tournament)
    {
        $validated = $request->validate([
            'venues_count' => 'integer|min:1',
            // Zde můžete přidat validaci i pro name, description atd., pokud byste je chtěl editovat
        ]);

        $tournament->update($validated);

        return response()->json($tournament);
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
    public function generateGames($tournamentId)
    {
        $tournament = Tournament::findOrFail($tournamentId);
        
        // Smažeme staré zápasy (pokud nějaké byly a generujeme znovu)
        $tournament->games()->delete();

        $players = $tournament->players;
        $playerIds = $players->pluck('id')->toArray();
        $numPlayers = count($playerIds);

        // Pokud je lichý počet, přidáme "fiktivního" hráče (null)
        // Kdo hraje s "null", má v tom kole volno.
        if ($numPlayers % 2 !== 0) {
            array_push($playerIds, null);
            $numPlayers++;
        }

        $gamesData = [];
        $totalRounds = $numPlayers - 1;
        $matchesPerRound = $numPlayers / 2;

        // --- GENERUJEME KOLA ---
        for ($round = 0; $round < $totalRounds; $round++) {
            
            // --- GENERUJEME ZÁPASY V KOLE ---
            for ($match = 0; $match < $matchesPerRound; $match++) {
                $p1 = $playerIds[$match];
                
                // Soupeř je ten "naproti" v kruhu
                $p2 = $playerIds[$numPlayers - 1 - $match];

                // Pokud ani jeden není "fiktivní", vytvoříme zápas
                if ($p1 !== null && $p2 !== null) {
                    $gamesData[] = [
                        'tournament_id' => $tournament->id,
                        'player1_id' => $p1,
                        'player2_id' => $p2,
                        'status' => 'scheduled',
                        'venue' => null, // Stůl se přiřadí až při hře
                        'score1' => 0,
                        'score2' => 0,
                        // Přidáváme malý posun v čase, aby se zachovalo pořadí kol při řazení v DB
                        'created_at' => now()->addSeconds($round * 60 + $match),
                        'updated_at' => now(),
                    ];
                }
            }

            // --- ROTACE HRÁČŮ (Kouzlo kruhu) ---
            // Hráč na indexu 0 (kotva) zůstává, zbytek se točí
            $movingPlayers = array_splice($playerIds, 1); // Vezmeme všechny kromě prvního
            $lastPlayer = array_pop($movingPlayers);      // Vezmeme posledního
            array_unshift($movingPlayers, $lastPlayer);   // Dáme ho na začátek (za kotvu)
            $playerIds = array_merge([$playerIds[0]], $movingPlayers); // Spojíme zpět
        }

        // Uložíme všechny zápasy najednou
        \App\Models\Game::insert($gamesData);
        
        // Změníme status turnaje
        $tournament->update(['status' => 'in_progress']);

        return response()->json(['message' => 'Rozlosováno spravedlivě!']);
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

                // Toto jste chtěl zachovat (rozdíl skóre)
                $scoreDiff += ($myScore - $opponentScore);

                // --- ZMĚNA ZDE: Použití nastavení z DB ---
                if ($myScore > $opponentScore) {
                    // Výhra
                    $points += $tournament->points_win; 
                } elseif ($myScore === $opponentScore) {
                    // Remíza
                    $points += $tournament->points_draw;
                } else {
                    // Prohra (přidáme, kdybyste si nastavil třeba -1 bod za prohru)
                    $points += $tournament->points_loss; 
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
        $sortedStats = collect($standings)->values()->sort(function ($a, $b) {
            if ($a['points'] !== $b['points']) {
                return $b['points'] <=> $a['points'];
            }
            
            $diffA = $a['score_diff'];
            $diffB = $b['score_diff'];
            
            if ($diffA !== $diffB) {
                return $diffB <=> $diffA;
            }
            
            return $b['score_diff'] <=> $a['score_diff'];

        })->values();

        return response()->json($sortedStats);
    }
}