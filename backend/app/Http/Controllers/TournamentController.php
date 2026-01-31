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

    public function export(Tournament $tournament)
    {
        $filename = 'turnaj_export_' . $tournament->id . '_' . date('Y-m-d_H-i') . '.xls';

        $headers = [
            "Content-type"        => "application/vnd.ms-excel",
            "Content-Disposition" => "attachment; filename=$filename",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];

        $callback = function() use ($tournament) {
            $players = $tournament->players()->orderBy('id')->get();
            $allGames = $tournament->games()->with(['player1', 'player2'])->latest()->get();

            $finishedGames = $allGames->where('status', 'finished');
            $scheduledGames = $allGames->where('status', '!=', 'finished')->sortBy('id');

            // --- 1. NAČTENÍ PRAVIDEL BODOVÁNÍ ---
            $pWin = $tournament->points_win;
            $pDraw = $tournament->points_draw;
            $pLoss = $tournament->points_loss;

            // --- 2. MAPA VÝSLEDKŮ (jen pro zobrazení toho, co už je v DB) ---
            $resultsMap = [];
            foreach ($finishedGames as $game) {
                $s1 = (int)$game->score1;
                $s2 = (int)$game->score2;

                if ($s1 === 0 && $s2 === 0) continue;

                $resultsMap["{$game->player1_id}-{$game->player2_id}"] = "{$s1}:{$s2}";
                $resultsMap["{$game->player2_id}-{$game->player1_id}"] = "{$s2}:{$s1}";
            }

            // --- 3. POMOCNÁ FUNKCE PRO PÍSMENA SLOUPCŮ EXCELU ---
            // (0 -> A, 1 -> B, ... 26 -> AA)
            $getColLetter = function($n) {
                $n++; // Excel sloupce jsou 1-based
                $letter = '';
                while ($n > 0) {
                    $m = ($n - 1) % 26;
                    $letter = chr(65 + $m) . $letter;
                    $n = floor(($n - 1) / 26);
                }
                return $letter;
            };

            // --- 4. GENEROVÁNÍ HTML HLAVIČKY ---
            // Důležité: xmlns namespace, aby Excel pochopil x:fmla (vzorce)
            echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
            echo '<head>';
            echo '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">';
            echo '<style>
                    table { border-collapse: collapse; width: 100%; margin-bottom: 30px; }
                    td, th { border: 1px solid #000; padding: 5px; text-align: center; }
                    th { background-color: #f0f0f0; font-weight: bold; }
                    .heading { font-size: 18px; font-weight: bold; border: none; text-align: left; padding: 10px 0; }
                    .text-cell { mso-number-format:"\@"; } 
                    .points-cell { background-color: #e6f3ff; font-weight: bold; color: #004085; }
                </style>';
            echo '</head><body>';

            // ==========================================
            // TABULKA S VZORCI
            // ==========================================
            echo '<table>';
            echo '<tr><td colspan="' . ($players->count() + 2) . '" class="heading">1. Interaktivní tabulka</td></tr>';
            
            // Hlavička
            echo '<tr><th>Hráč / Soupeř</th>';
            foreach ($players as $player) {
                echo "<th>{$player->name}</th>";
            }
            echo '<th style="background-color: #cce5ff; border: 2px solid #000;">BODY (Auto)</th>';
            echo '</tr>';

            // ŘÁDKY
            // Začínáme na řádku 3 (1. řádek je nadpis, 2. je hlavička tabulky)
            $rowIndex = 3; 

            foreach ($players as $rowKey => $rowPlayer) {
                echo '<tr>';
                echo "<td style='font-weight:bold'>{$rowPlayer->name}</td>";

                // Pole pro sběr částí vzorce (pro každý zápas jeden kus IF...)
                $formulaParts = [];

                foreach ($players as $colKey => $colPlayer) {
                    // Zjistíme, v jakém sloupci jsme (A=0, B=1, ...)
                    // První sloupec (A) je jméno, data začínají od B (index 1)
                    $excelCol = $getColLetter($colKey + 1); 
                    $cellRef = "{$excelCol}{$rowIndex}"; // Výsledek např. "B3", "C3"

                    if ($rowPlayer->id === $colPlayer->id) {
                        echo '<td style="background-color: #ddd;">X</td>';
                    } else {
                        $key = "{$rowPlayer->id}-{$colPlayer->id}";
                        $val = $resultsMap[$key] ?? '';
                        echo "<td class='text-cell'>{$val}</td>";

                        // --- GENERUJEME EXCEL VZOREC PRO TUTO BUŇKU ---
                        // Vzorec říká: Pokud je v buňce dvojtečka ":", rozeber ji na Levé a Pravé číslo.
                        // Pokud Levé > Pravé => Výhra ($pWin)
                        // Pokud Levé < Pravé => Prohra ($pLoss)
                        // Jinak Remíza ($pDraw)
                        
                        $part = "IF(ISNUMBER(FIND(\":\",{$cellRef})), " .
                                "IF(VALUE(LEFT({$cellRef},FIND(\":\",{$cellRef})-1)) > VALUE(MID({$cellRef},FIND(\":\",{$cellRef})+1,20)), {$pWin}, " . 
                                "IF(VALUE(LEFT({$cellRef},FIND(\":\",{$cellRef})-1)) < VALUE(MID({$cellRef},FIND(\":\",{$cellRef})+1,20)), {$pLoss}, {$pDraw})" .
                                "), 0)";
                        
                        $formulaParts[] = $part;
                    }
                }
                
                // Slepíme všechny IFy do jednoho součtu: =IF(...) + IF(...) + ...
                $fullFormula = "=" . implode(" + ", $formulaParts);

                // Vypíšeme buňku a pomocí x:fmla vložíme vzorec
                echo "<td class='points-cell' style='border: 2px solid #000;' x:fmla='{$fullFormula}'></td>";
                
                echo '</tr>';
                $rowIndex++; // Jdeme na další řádek v Excelu
            }
            echo '</table>';
            echo '<br><br>';

            // ==========================================
            // HISTORIE (Odehrané)
            // ==========================================
            echo '<table>';
            echo '<tr><td colspan="5" class="heading" style="color: green;">2. Již odehrané zápasy</td></tr>';
            echo '<tr><th>ID</th><th>Hráč 1</th><th>Výsledek</th><th>Hráč 2</th><th>Čas</th></tr>';

            if ($finishedGames->isEmpty()) {
                echo '<tr><td colspan="5" style="color:gray;">Zatím žádné odehrané zápasy.</td></tr>';
            } else {
                foreach ($finishedGames as $game) {
                    $s1 = (int)$game->score1;
                    $s2 = (int)$game->score2;
                    $p1Style = $s1 > $s2 ? 'font-weight:bold; color:green;' : '';
                    $p2Style = $s2 > $s1 ? 'font-weight:bold; color:green;' : '';

                    echo '<tr>';
                    echo "<td>#{$game->id}</td>";
                    echo "<td style='{$p1Style}'>{$game->player1->name}</td>";
                    echo "<td class='text-cell' style='font-weight:bold; background-color: #f9f9f9;'>{$s1}:{$s2}</td>";
                    echo "<td style='{$p2Style}'>{$game->player2->name}</td>";
                    echo "<td>" . ($game->updated_at ? $game->updated_at->format('H:i') : '-') . "</td>";
                    echo '</tr>';
                }
            }
            echo '</table>';
            echo '<br><br>';

            // ==========================================
            // FRONTA
            // ==========================================
            echo '<table>';
            echo '<tr><td colspan="4" class="heading" style="color: orange;">3. Naplánované zápasy</td></tr>';
            echo '<tr><th>ID</th><th>Hráč 1</th><th>vs</th><th>Hráč 2</th></tr>';

            if ($scheduledGames->isEmpty()) {
                echo '<tr><td colspan="4" style="color:gray;">Žádné další zápasy.</td></tr>';
            } else {
                foreach ($scheduledGames as $game) {
                    echo '<tr>';
                    echo "<td>#{$game->id}</td>";
                    echo "<td>{$game->player1->name}</td>";
                    echo "<td>vs</td>";
                    echo "<td>{$game->player2->name}</td>";
                    echo '</tr>';
                }
            }
            echo '</table>';
            echo '</body></html>';
        };

        return response()->stream($callback, 200, $headers);
    }
}