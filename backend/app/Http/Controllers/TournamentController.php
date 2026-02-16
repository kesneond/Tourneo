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
            'description' => 'nullable|string',
            'start_date' => 'required|date',
            'format' => 'required|in:round_robin,groups',
            'points_win' => 'required|integer|min:0',
            'points_draw' => 'required|integer|min:0',
            'points_loss' => 'required|integer|min:0',
        ]);

        $tournament = Tournament::create($validated);

        return response()->json($tournament, 201);
    }

    public function update(Request $request, Tournament $tournament)
    {
        $validated = $request->validate([
            'venues_count' => 'integer|min:1',
            'number_of_groups' => 'integer|min:2',
            // Zde můžete přidat validaci i pro name, description atd., pokud byste je chtěl editovat
        ]);

        $tournament->update($validated);

        return response()->json($tournament);
    }

    // Detail turnaje (včetně hráčů a zápasů)
    public function show($id)
    {
        $tournament = Tournament::with([
            'players', 
            'games.player1', 
            'games.player2', 
            'groups.players', 
            'groups.games.player1', 
            'groups.games.player2'
        ])->findOrFail($id);

        return response()->json($tournament);
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
        $tournament = Tournament::with('players')->findOrFail($tournamentId);

        // Smažeme staré zápasy a skupiny, pokud existují
        $tournament->games()->delete();
        $tournament->groups()->delete(); // Také smažeme skupiny

        if ($tournament->format === 'groups') {
            if (!$tournament->number_of_groups || $tournament->number_of_groups < 2) {
                return response()->json(['message' => 'Pro turnaj ve skupinách musí být nastaven počet skupin (minimálně 2).'], 422);
            }
            $this->generateGroups($tournament);
        } else {
            $this->generateRoundRobin($tournament, $tournament->players->pluck('id')->toArray());
        }

        $tournament->update(['status' => 'in_progress']);

        return response()->json(['message' => 'Rozlosování bylo úspěšně vygenerováno!']);
    }

    private function generateGroups(Tournament $tournament)
    {
        $players = $tournament->players->shuffle();
        $numberOfGroups = $tournament->number_of_groups;
        $groups = [];

        for ($i = 0; $i < $numberOfGroups; $i++) {
            $group = $tournament->groups()->create(['name' => 'Skupina ' . ($i + 1)]);
            $groups[] = $group;
        }

        // Rozdělení hráčů do skupin
        $playerIndex = 0;
        foreach ($players as $player) {
            $groups[$playerIndex % $numberOfGroups]->players()->attach($player->id);
            $playerIndex++;
        }

        // Generování zápasů pro každou skupinu
        foreach ($groups as $group) {
            $playerIds = $group->players->pluck('id')->toArray();
            $this->generateRoundRobin($tournament, $playerIds, $group->id);
        }
    }

    private function generateRoundRobin(Tournament $tournament, array $playerIds, $groupId = null)
    {
        $numPlayers = count($playerIds);

        if ($numPlayers < 2) {
            return; // Nelze generovat zápasy pro méně než 2 hráče
        }
        
        if ($numPlayers % 2 !== 0) {
            array_push($playerIds, null);
            $numPlayers++;
        }

        $gamesData = [];
        $totalRounds = $numPlayers - 1;
        $matchesPerRound = $numPlayers / 2;

        for ($round = 0; $round < $totalRounds; $round++) {
            for ($match = 0; $match < $matchesPerRound; $match++) {
                $p1 = $playerIds[$match];
                $p2 = $playerIds[$numPlayers - 1 - $match];

                if ($p1 !== null && $p2 !== null) {
                    $gamesData[] = [
                        'tournament_id' => $tournament->id,
                        'player1_id' => $p1,
                        'player2_id' => $p2,
                        'status' => 'scheduled',
                        'venue' => null,
                        'score1' => 0,
                        'score2' => 0,
                        'group_id' => $groupId, // Přiřazení skupiny
                        'created_at' => now()->addSeconds($round * 60 + $match),
                        'updated_at' => now(),
                    ];
                }
            }

            $movingPlayers = array_splice($playerIds, 1);
            $lastPlayer = array_pop($movingPlayers);
            array_unshift($movingPlayers, $lastPlayer);
            $playerIds = array_merge([$playerIds[0]], $movingPlayers);
        }

        \App\Models\Game::insert($gamesData);
    }

    // --- VÝPOČET TABULKY ---
    public function standings($id)
    {
        $tournament = Tournament::with(['players', 'games', 'groups.players'])->findOrFail($id);

        if ($tournament->format === 'groups') {
            $groupStandings = $tournament->groups->map(function ($group) use ($tournament) {
                $standings = $this->calculateStandings($tournament, $group->players, $group->id);
                return [
                    'name' => $group->name,
                    'standings' => $standings,
                ];
            });

            return response()->json($groupStandings);
        } else {
            $standings = $this->calculateStandings($tournament, $tournament->players);
            return response()->json($standings);
        }
    }

    private function calculateStandings(Tournament $tournament, $players, $groupId = null)
    {
        $standings = $players->map(function ($player) use ($tournament, $groupId) {
            $matches = $tournament->games->filter(function ($game) use ($player, $groupId) {
                $inGroup = $groupId ? $game->group_id === $groupId : true;
                return $inGroup && $game->status === 'finished' &&
                       ($game->player1_id === $player->id || $game->player2_id === $player->id);
            });

            $points = 0;
            $played = 0;
            $scoreDiff = 0;

            foreach ($matches as $game) {
                $played++;
                $isPlayer1 = $game->player1_id === $player->id;
                
                $myScore = $isPlayer1 ? $game->score1 : $game->score2;
                $opponentScore = $isPlayer1 ? $game->score2 : $game->score1;

                $scoreDiff += ($myScore - $opponentScore);

                if ($myScore > $opponentScore) {
                    $points += $tournament->points_win;
                } elseif ($myScore === $opponentScore) {
                    $points += $tournament->points_draw;
                } else {
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

        return collect($standings)->values()->sort(function ($a, $b) {
            if ($a['points'] !== $b['points']) {
                return $b['points'] <=> $a['points'];
            }
            
            if ($a['score_diff'] !== $b['score_diff']) {
                return $b['score_diff'] <=> $a['score_diff'];
            }
            
            return 0; // Udržet stabilní pořadí, pokud je vše stejné
        })->values();
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
            // --- 3. POMOCNÁ FUNKCE PRO PÍSMENA SLOUPCŮ EXCELU ---
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
            echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
            echo '<head>';
            echo '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">';
            echo '<style>
                    body { font-family: sans-serif; }
                    table { border-collapse: collapse; width: 100%; margin-bottom: 30px; page-break-inside: auto; }
                    td, th { border: 1px solid #000; padding: 5px; text-align: center; }
                    th { background-color: #f0f0f0; font-weight: bold; }
                    .heading { font-size: 20px; font-weight: bold; border: none; text-align: left; padding: 15px 0; }
                    .sub-heading { font-size: 16px; font-weight: bold; border: none; text-align: left; padding: 10px 0; }
                    .text-cell { mso-number-format:"\@"; }
                    .points-cell { background-color: #e6f3ff; font-weight: bold; color: #004085; }
                </style>';
            echo '</head><body>';
            echo '<div class="heading">Export turnaje: ' . htmlspecialchars($tournament->name) . '</div>';

            if ($tournament->format === 'groups') {
                $groups = $tournament->groups()->with(['players', 'games.player1', 'games.player2'])->get();
                foreach ($groups as $group) {
                    echo '<div class="sub-heading">Skupina: ' . htmlspecialchars($group->name) . '</div>';
                    $this->renderExcelTableForPlayers($group->players, $group->games, $tournament, $getColLetter);
                }
            } else {
                $this->renderExcelTableForPlayers($tournament->players, $tournament->games, $tournament, $getColLetter);
            }

            echo '</body></html>';
        };

        return response()->stream($callback, 200, $headers);
    }

    private function renderExcelTableForPlayers($players, $games, Tournament $tournament, callable $getColLetter)
    {
        $players = $players->sortBy('id');
        $allGames = $games->sortBy('created_at');

        $finishedGames = $allGames->where('status', 'finished');
        $scheduledGames = $allGames->where('status', '!=', 'finished')->sortBy('id');

        $pWin = $tournament->points_win;
        $pDraw = $tournament->points_draw;
        $pLoss = $tournament->points_loss;

        $resultsMap = [];
        foreach ($finishedGames as $game) {
            $s1 = (int)$game->score1;
            $s2 = (int)$game->score2;
            $resultsMap["{$game->player1_id}-{$game->player2_id}"] = "{$s1}:{$s2}";
            $resultsMap["{$game->player2_id}-{$game->player1_id}"] = "{$s2}:{$s1}";
        }

        // ==========================================
        // Interaktivní tabulka
        // ==========================================
        echo '<table>';
        echo '<tr><td colspan="' . ($players->count() + 2) . '" class="sub-heading">1. Interaktivní tabulka</td></tr>';

        // Hlavička
        echo '<tr><th>Hráč / Soupeř</th>';
        foreach ($players as $player) {
            echo "<th>" . htmlspecialchars($player->name) . "</th>";
        }
        echo '<th style="background-color: #cce5ff; border: 2px solid #000;">BODY (Auto)</th>';
        echo '</tr>';

        // ŘÁDKY
        $startRow = 3; // 1=nadpis, 2=hlavicka
        $rowIndex = $startRow;

        foreach ($players as $rowPlayer) {
            echo '<tr>';
            echo "<td style='font-weight:bold'>" . htmlspecialchars($rowPlayer->name) . "</td>";

            $formulaParts = [];

            foreach ($players as $colKey => $colPlayer) {
                $excelCol = $getColLetter($colKey + 1);
                $cellRef = "{$excelCol}{$rowIndex}";

                if ($rowPlayer->id === $colPlayer->id) {
                    echo '<td style="background-color: #ddd;">X</td>';
                } else {
                    $key = "{$rowPlayer->id}-{$colPlayer->id}";
                    $val = $resultsMap[$key] ?? '';
                    echo "<td class='text-cell'>{$val}</td>";

                    $part = "IF(ISNUMBER(FIND(\":\",{$cellRef})), " .
                            "IF(VALUE(LEFT({$cellRef},FIND(\":\",{$cellRef})-1)) > VALUE(MID({$cellRef},FIND(\":\",{$cellRef})+1,20)), {$pWin}, " .
                            "IF(VALUE(LEFT({$cellRef},FIND(\":\",{$cellRef})-1)) < VALUE(MID({$cellRef},FIND(\":\",{$cellRef})+1,20)), {$pLoss}, {$pDraw})" .
                            "), 0)";

                    $formulaParts[] = $part;
                }
            }
            
            $fullFormula = "=" . implode(" + ", $formulaParts);
            echo "<td class='points-cell' style='border: 2px solid #000;' x:fmla='{$fullFormula}'></td>";
            
            echo '</tr>';
            $rowIndex++;
        }
        echo '</table>';

        // ==========================================
        // Odehrané zápasy
        // ==========================================
        echo '<table>';
        echo '<tr><td colspan="5" class="sub-heading" style="color: green;">2. Již odehrané zápasy</td></tr>';
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
                echo "<td style='{$p1Style}'>" . htmlspecialchars($game->player1->name) . "</td>";
                echo "<td class='text-cell' style='font-weight:bold; background-color: #f9f9f9;'>{$s1}:{$s2}</td>";
                echo "<td style='{$p2Style}'>" . htmlspecialchars($game->player2->name) . "</td>";
                echo "<td>" . ($game->updated_at ? $game->updated_at->format('H:i') : '-') . "</td>";
                echo '</tr>';
            }
        }
        echo '</table>';

        // ==========================================
        // Naplánované zápasy
        // ==========================================
        echo '<table>';
        echo '<tr><td colspan="4" class="sub-heading" style="color: orange;">3. Naplánované zápasy</td></tr>';
        echo '<tr><th>ID</th><th>Hráč 1</th><th>vs</th><th>Hráč 2</th></tr>';

        if ($scheduledGames->isEmpty()) {
            echo '<tr><td colspan="4" style="color:gray;">Žádné další zápasy.</td></tr>';
        } else {
            foreach ($scheduledGames as $game) {
                echo '<tr>';
                echo "<td>#{$game->id}</td>";
                echo "<td>" . htmlspecialchars($game->player1->name) . "</td>";
                echo "<td>vs</td>";
                echo "<td>" . htmlspecialchars($game->player2->name) . "</td>";
                echo '</tr>';
            }
        }
        echo '</table>';
    }
}