<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\Player;
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

        if ($game->group_id === null && $validated['score1'] === $validated['score2']) {
            return response()->json([
                'message' => 'Zápas v pavouku nesmí skončit remízou.'
            ], 422);
        }

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

        if ($game->tournament->format === 'groups') {
            $hasUnfinishedGroupGames = $game->tournament->games()
                ->whereNotNull('group_id')
                ->where('status', '!=', 'finished')
                ->exists();

            $hasPlayoffGames = $game->tournament->games()
                ->whereNull('group_id')
                ->exists();

            if (!$hasUnfinishedGroupGames && !$hasPlayoffGames) {
                $this->generatePlayoffGames($game->tournament);
            }

            if ($game->group_id === null) {
                $game->tournament->games()
                    ->whereNull('group_id')
                    ->where('round', '>', $game->round)
                    ->delete();

                $this->rebuildPlayoffFromRound($game->tournament, (int) $game->round);
            }
        }

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

    private function generatePlayoffGames($tournament)
    {
        $tournament->loadMissing(['groups.players', 'games']);

        $groups = $tournament->groups->values();
        if ($groups->count() < 2) {
            return;
        }

        $groupStandings = [];
        foreach ($groups as $group) {
            $groupStandings[$group->id] = $this->calculateStandings($tournament, $group->players, $group->id);
        }

        $pairs = [];
        $totalMatches = 0;

        for ($groupIndex = 0; $groupIndex < $groups->count() - 1; $groupIndex += 2) {
            $groupA = $groups[$groupIndex];
            $groupB = $groups[$groupIndex + 1];
            $standA = $groupStandings[$groupA->id];
            $standB = $groupStandings[$groupB->id];
            $maxMatches = min($standA->count(), $standB->count());

            $pairs[] = [
                'groupA' => $groupA,
                'groupB' => $groupB,
                'standA' => $standA,
                'standB' => $standB,
                'max_matches' => $maxMatches,
                'matches' => $maxMatches
            ];
            $totalMatches += $maxMatches;
        }

        if ($totalMatches === 0) {
            return;
        }

        $targetMatches = $this->nearestPowerOfTwo($totalMatches);
        $diff = $targetMatches - $totalMatches;

        if ($diff < 0) {
            for ($step = 0; $step < abs($diff); $step++) {
                $candidateIndex = null;
                $candidateScore = null;

                foreach ($pairs as $index => $pair) {
                    if ($pair['matches'] <= 0) {
                        continue;
                    }

                    $lastIndex = $pair['matches'] - 1;
                    $playerA = $pair['standA'][$lastIndex] ?? null;
                    $playerB = $pair['standB'][$lastIndex] ?? null;

                    $pointsA = $playerA['points'] ?? 0;
                    $pointsB = $playerB['points'] ?? 0;
                    $scoreDiffA = $playerA['score_diff'] ?? 0;
                    $scoreDiffB = $playerB['score_diff'] ?? 0;

                    $pairScore = min($pointsA, $pointsB) * 10000 + min($scoreDiffA, $scoreDiffB);

                    if ($candidateIndex === null || $pairScore < $candidateScore) {
                        $candidateIndex = $index;
                        $candidateScore = $pairScore;
                    }
                }

                if ($candidateIndex === null) {
                    break;
                }

                $pairs[$candidateIndex]['matches'] = max(0, $pairs[$candidateIndex]['matches'] - 1);
            }
        } elseif ($diff > 0) {
            for ($step = 0; $step < $diff; $step++) {
                $candidateIndex = null;
                $candidateScore = null;

                foreach ($pairs as $index => $pair) {
                    $standACount = $pair['standA']->count();
                    $standBCount = $pair['standB']->count();
                    $maxPossible = max($standACount, $standBCount);

                    if ($pair['matches'] >= $maxPossible) {
                        continue;
                    }

                    $nextIndex = $pair['matches'];
                    $playerA = $pair['standA'][$nextIndex] ?? null;
                    $playerB = $pair['standB'][$nextIndex] ?? null;

                    if (!$playerA && !$playerB) {
                        continue;
                    }

                    $pointsA = $playerA['points'] ?? -1;
                    $pointsB = $playerB['points'] ?? -1;
                    $scoreDiffA = $playerA['score_diff'] ?? -100000;
                    $scoreDiffB = $playerB['score_diff'] ?? -100000;

                    $pairScore = max($pointsA, $pointsB) * 10000 + max($scoreDiffA, $scoreDiffB);

                    if ($candidateIndex === null || $pairScore > $candidateScore) {
                        $candidateIndex = $index;
                        $candidateScore = $pairScore;
                    }
                }

                if ($candidateIndex === null) {
                    break;
                }

                $pairs[$candidateIndex]['matches']++;
            }
        }

        $dummyPlayerId = null;
        $seededGroups = [];
        $requiredPerGroup = [];

        foreach ($pairs as $pair) {
            $requiredPerGroup[$pair['groupA']->id] = $pair['matches'];
            $requiredPerGroup[$pair['groupB']->id] = $pair['matches'];
        }

        foreach ($groups as $group) {
            $required = $requiredPerGroup[$group->id] ?? 0;
            $standings = $groupStandings[$group->id]
                ->slice(0, min($required, $groupStandings[$group->id]->count()))
                ->values();

            $seedList = $standings->map(function ($player) {
                return array_merge($player, ['is_dummy' => false]);
            })->values();

            if ($required > $seedList->count()) {
                if ($dummyPlayerId === null) {
                    $dummyPlayerId = $this->getDummyPlayerId($tournament);
                }

                $dummyCount = $required - $seedList->count();
                for ($i = 0; $i < $dummyCount; $i++) {
                    $seedList->push([
                        'id' => $dummyPlayerId,
                        'is_dummy' => true
                    ]);
                }
            }

            $seededGroups[$group->id] = $seedList;
        }

        $gamesData = [];
        $pairIndex = 0;

        for ($groupIndex = 0; $groupIndex < $groups->count() - 1; $groupIndex += 2) {
            $groupA = $groups[$groupIndex];
            $groupB = $groups[$groupIndex + 1];

            $groupASeeds = $seededGroups[$groupA->id] ?? collect();
            $groupBSeeds = $seededGroups[$groupB->id] ?? collect();
            $pairLimit = min($groupASeeds->count(), $groupBSeeds->count());

            for ($i = 0; $i < $pairLimit; $i++) {
                $playerA = $groupASeeds[$i];
                $playerB = $groupBSeeds[$pairLimit - 1 - $i];

                $isDummyA = $playerA['is_dummy'] ?? false;
                $isDummyB = $playerB['is_dummy'] ?? false;

                if ($isDummyA && $isDummyB) {
                    continue;
                }

                $playerAId = $playerA['id'];
                $playerBId = $playerB['id'];
                $isFinished = $isDummyA || $isDummyB;
                $score1 = 0;
                $score2 = 0;

                if ($isFinished) {
                    if ($isDummyB) {
                        $score1 = 1;
                    } else {
                        $score2 = 1;
                    }
                }

                $gamesData[] = [
                    'tournament_id' => $tournament->id,
                    'player1_id' => $playerAId,
                    'player2_id' => $playerBId,
                    'status' => $isFinished ? 'finished' : 'scheduled',
                    'venue' => null,
                    'score1' => $score1,
                    'score2' => $score2,
                    'group_id' => null,
                    'round' => 1,
                    'created_at' => now()->addSeconds($pairIndex * 60),
                    'updated_at' => now(),
                ];
                $pairIndex++;
            }
        }

        if (!empty($gamesData)) {
            Game::insert($gamesData);

            $allFinished = collect($gamesData)->every(function ($game) {
                return $game['status'] === 'finished';
            });

            if ($allFinished) {
                $this->generateNextPlayoffRoundIfReady($tournament, 1);
            }
        }
    }

    private function selectBracketSize(int $totalPlayers): int
    {
        if ($totalPlayers > 8) {
            return 16;
        }

        if ($totalPlayers > 4) {
            return 8;
        }

        if ($totalPlayers > 2) {
            return 4;
        }

        return 2;
    }

    private function nearestPowerOfTwo(int $value): int
    {
        if ($value <= 1) {
            return 1;
        }

        $lower = 1;
        while ($lower * 2 <= $value) {
            $lower *= 2;
        }

        $upper = $lower * 2;
        Log::debug('nearestPowerOfTwo bounds', [
            'value' => $value,
            'lower' => $lower,
            'upper' => $upper
        ]);
        return ($value - $lower) < ($upper - $value) ? $lower : $upper;
    }

    private function getDummyPlayerId($tournament): int
    {
        $dummy = Player::firstOrCreate([
            'tournament_id' => $tournament->id,
            'name' => '__BYE__'
        ]);

        return $dummy->id;
    }

    private function generateNextPlayoffRoundIfReady($tournament, $currentRound)
    {
        if (!$currentRound) {
            return;
        }

        $tournament->load('games');

        $roundGames = $tournament->games
            ->where('group_id', null)
            ->where('round', $currentRound);

        if ($roundGames->isEmpty()) {
            return;
        }

        $unfinishedRoundGames = $roundGames->where('status', '!=', 'finished');
        if ($unfinishedRoundGames->isNotEmpty()) {
            return;
        }

        $roundGamesCount = $roundGames->count();
        if ($roundGamesCount <= 1) {
            return;
        }

        $previousRoundGamesCount = $tournament->games
            ->where('group_id', null)
            ->where('round', $currentRound - 1)
            ->count();

        // Pokud má aktuální kolo 2 zápasy a i předchozí mělo 2,
        // jedná se o finále + zápas o 3. místo. Dál už negenerujeme.
        if ($roundGamesCount === 2 && $previousRoundGamesCount === 2) {
            return;
        }

        $winners = $roundGames->sortBy('created_at')->map(function ($game) {
            return $game->score1 >= $game->score2 ? $game->player1_id : $game->player2_id;
        })->values();

        $losers = $roundGames->sortBy('created_at')->map(function ($game) {
            return $game->score1 >= $game->score2 ? $game->player2_id : $game->player1_id;
        })->values();

        $nextRound = $currentRound + 1;
        $existingNextRound = $tournament->games
            ->where('group_id', null)
            ->where('round', $nextRound)
            ->isNotEmpty();

        if ($existingNextRound) {
            return;
        }

        $gamesData = [];
        $pairIndex = 0;

        if ($roundGamesCount === 2) {
            // Semifinále -> finále + o 3. místo
            $gamesData[] = [
                'tournament_id' => $tournament->id,
                'player1_id' => $winners[0],
                'player2_id' => $winners[1],
                'status' => 'scheduled',
                'venue' => null,
                'score1' => 0,
                'score2' => 0,
                'group_id' => null,
                'round' => $nextRound,
                'created_at' => now()->addSeconds($pairIndex * 60),
                'updated_at' => now(),
            ];
            $pairIndex++;

            $gamesData[] = [
                'tournament_id' => $tournament->id,
                'player1_id' => $losers[0],
                'player2_id' => $losers[1],
                'status' => 'scheduled',
                'venue' => null,
                'score1' => 0,
                'score2' => 0,
                'group_id' => null,
                'round' => $nextRound,
                'created_at' => now()->addSeconds($pairIndex * 60),
                'updated_at' => now(),
            ];
        } else {
            for ($i = 0; $i < $winners->count() - 1; $i += 2) {
                $gamesData[] = [
                    'tournament_id' => $tournament->id,
                    'player1_id' => $winners[$i],
                    'player2_id' => $winners[$i + 1],
                    'status' => 'scheduled',
                    'venue' => null,
                    'score1' => 0,
                    'score2' => 0,
                    'group_id' => null,
                    'round' => $nextRound,
                    'created_at' => now()->addSeconds($pairIndex * 60),
                    'updated_at' => now(),
                ];
                $pairIndex++;
            }
        }

        if (!empty($gamesData)) {
            Game::insert($gamesData);
        }
    }

    private function rebuildPlayoffFromRound($tournament, int $startRound): void
    {
        $round = $startRound;

        while ($round > 0) {
            $beforeCount = $tournament->games()
                ->whereNull('group_id')
                ->where('round', '>', $round)
                ->count();

            $this->generateNextPlayoffRoundIfReady($tournament, $round);

            $afterCount = $tournament->games()
                ->whereNull('group_id')
                ->where('round', '>', $round)
                ->count();

            if ($afterCount === $beforeCount) {
                break;
            }

            $round++;
        }
    }

    private function calculateStandings($tournament, $players, $groupId = null)
    {
        $tournament->loadMissing('games');

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

            return 0;
        })->values();
    }
}