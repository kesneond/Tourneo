<script setup>
import { ref, onMounted, computed } from 'vue';
import { useRoute } from 'vue-router';
import { useTournamentStore } from '@/stores/tournament';
import api from '@/axios';
import StandingsTable from '@/components/StandingsTable.vue';

const route = useRoute();
const store = useTournamentStore();
const tournament = computed(() => store.currentTournament);
const standings = ref([]);
const newPlayerName = ref('');

// --- MODAL PRO VÝBĚR ZÁPASU ---
const showAssignModal = ref(false);
const selectedVenue = ref(null);

// --- STAV PRO EDITACI SKÓRE ---
const editingMatchId = ref(null);
const tempScore1 = ref(0);
const tempScore2 = ref(0);
const saving = ref(false);

// --- DRAG SCROLL (Fronta) ---
const queueContainer = ref(null);
const isDragging = ref(false);
let startX, scrollLeft, velX = 0, momentumID;

const startDrag = (e) => { isDragging.value = true; cancelAnimationFrame(momentumID); startX = e.pageX - queueContainer.value.offsetLeft; scrollLeft = queueContainer.value.scrollLeft; };
const stopDrag = () => { isDragging.value = false; beginMomentum(); };
const doDrag = (e) => { if (!isDragging.value) return; e.preventDefault(); const x = e.pageX - queueContainer.value.offsetLeft; const walk = (x - startX) * 1.5; const prev = queueContainer.value.scrollLeft; queueContainer.value.scrollLeft = scrollLeft - walk; velX = queueContainer.value.scrollLeft - prev; };
const beginMomentum = () => { cancelAnimationFrame(momentumID); const loop = () => { if (Math.abs(velX) > 0.5) { queueContainer.value.scrollLeft += velX; velX *= 0.95; momentumID = requestAnimationFrame(loop); } }; loop(); };

// --- LOGIKA DAT ---
const allGames = computed(() => tournament.value?.games || []);

const finishedGames = computed(() => 
    allGames.value.filter(g => g.status === 'finished').sort((a,b) => new Date(b.updated_at) - new Date(a.updated_at))
);

const groupedFinishedGames = computed(() => {
    if (tournament.value?.format !== 'groups' || !tournament.value.groups) {
        return [];
    }
    return tournament.value.groups.map(group => {
        const gamesInGroup = allGames.value.filter(game => game.group_id === group.id && game.status === 'finished');
        return {
            ...group,
            games: gamesInGroup.sort((a,b) => new Date(b.updated_at) - new Date(a.updated_at)),
        };
    });
});

const playoffGames = computed(() =>
    allGames.value.filter(g => g.group_id === null)
);

const playoffFinishedGames = computed(() =>
    playoffGames.value
        .filter(g => g.status === 'finished')
        .sort((a,b) => new Date(b.updated_at) - new Date(a.updated_at))
);

const queuedGames = computed(() => 
    allGames.value.filter(g => g.status === 'scheduled')
);

const groupedQueuedGames = computed(() => {
    if (tournament.value?.format !== 'groups') {
        return [];
    }
    const groups = tournament.value.groups || [];
    return groups.map(group => {
        return {
            ...group,
            games: allGames.value.filter(game => game.group_id === group.id && game.status === 'scheduled')
        }
    });
});

const playoffQueuedGames = computed(() =>
    playoffGames.value.filter(g => g.status === 'scheduled')
);

const getRoundLabel = (gamesCount) => {
    switch (gamesCount) {
        case 1:
            return 'Finále';
        case 2:
            return 'Semifinále';
        case 4:
            return 'Čtvrtfinále';
        case 8:
            return 'Osmifinále';
        case 16:
            return 'Šestnáctifinále';
        default:
            return `Kolo (${gamesCount} zápasů)`;
    }
};

const playoffRoundMeta = computed(() => {
    const rounds = {};

    playoffGames.value.forEach(game => {
        const round = game.round || 1;
        if (!rounds[round]) {
            rounds[round] = [];
        }
        rounds[round].push(game);
    });

    const orderedRounds = Object.entries(rounds)
        .map(([round, roundGames]) => ({
            round: Number(round),
            games: [...roundGames].sort((a, b) => new Date(a.created_at) - new Date(b.created_at) || a.id - b.id)
        }))
        .sort((a, b) => a.round - b.round);

    const metaByRound = {};

    orderedRounds.forEach((roundData, index) => {
        const previousRound = index > 0 ? orderedRounds[index - 1] : null;
        const totalGames = roundData.games.length;
        const isMedalRound = totalGames === 2 && (previousRound?.games.length ?? 0) === 2;
        const matchLabels = {};

        if (isMedalRound) {
            if (roundData.games[0]) {
                matchLabels[roundData.games[0].id] = 'Finále';
            }
            if (roundData.games[1]) {
                matchLabels[roundData.games[1].id] = 'O 3. místo';
            }
        }

        metaByRound[roundData.round] = {
            totalGames,
            isMedalRound,
            label: isMedalRound ? 'Finále + o 3. místo' : getRoundLabel(totalGames),
            matchLabels
        };
    });

    return metaByRound;
});

const groupPlayoffGamesByRound = (games) => {
    const rounds = {};
    games.forEach(game => {
        const round = game.round || 1;
        if (!rounds[round]) {
            rounds[round] = [];
        }
        rounds[round].push(game);
    });

    const orderedRounds = Object.entries(rounds)
        .map(([round, roundGames]) => ({
            round: Number(round),
            games: [...roundGames].sort((a, b) => new Date(a.created_at) - new Date(b.created_at) || a.id - b.id)
        }))
        .sort((a, b) => a.round - b.round);

    return orderedRounds.map((roundData) => {
        const meta = playoffRoundMeta.value[roundData.round] || {};
        return {
            ...roundData,
            label: meta.label || getRoundLabel(roundData.games.length),
            isMedalRound: Boolean(meta.isMedalRound),
            matchLabels: meta.matchLabels || {}
        };
    });
};

const playoffQueuedByRound = computed(() => groupPlayoffGamesByRound(playoffQueuedGames.value));
const playoffFinishedByRound = computed(() =>
    [...groupPlayoffGamesByRound(playoffFinishedGames.value)].sort((a, b) => b.round - a.round)
);
const playoffQueuedCount = computed(() => playoffQueuedGames.value.length);
const playoffFinishedCount = computed(() => playoffFinishedGames.value.length);
const medalSections = ['Finále', 'O 3. místo'];
const getMedalMatch = (round, sectionLabel) =>
    round.games.find(match => round.matchLabels?.[match.id] === sectionLabel) || null;

const finalStandings = computed(() => {
    if (!tournament.value || tournament.value.status !== 'finished') {
        return [];
    }

    let baseStandings = [];

    if (tournament.value.format === 'groups') {
        baseStandings = (standings.value || [])
            .flatMap(group => group.standings || []);
    } else {
        baseStandings = standings.value || [];
    }

    if (!baseStandings.length) {
        return [];
    }

    const playRounds = new Map();
    const placementRank = new Map();

    const rounds = {};
    playoffFinishedGames.value.forEach(game => {
        const round = game.round || 1;
        if (!rounds[round]) {
            rounds[round] = [];
        }
        rounds[round].push(game);
    });

    const orderedRounds = Object.entries(rounds)
        .map(([round, games]) => ({
            round: Number(round),
            games: [...games].sort((a, b) => new Date(a.created_at) - new Date(b.created_at) || a.id - b.id)
        }))
        .sort((a, b) => a.round - b.round);

    const medalRound = orderedRounds.find((roundData, index) =>
        roundData.games.length === 2 && index > 0 && orderedRounds[index - 1].games.length === 2
    );

    let finalGameId = null;
    let thirdPlaceGameId = null;

    if (medalRound) {
        finalGameId = medalRound.games[0]?.id ?? null;
        thirdPlaceGameId = medalRound.games[1]?.id ?? null;

        const finalGame = medalRound.games[0];
        if (finalGame && finalGame.score1 !== finalGame.score2) {
            const finalWinnerId = finalGame.score1 > finalGame.score2 ? finalGame.player1_id : finalGame.player2_id;
            const finalLoserId = finalGame.score1 > finalGame.score2 ? finalGame.player2_id : finalGame.player1_id;
            placementRank.set(finalWinnerId, 4); // 1. místo
            placementRank.set(finalLoserId, 3); // 2. místo
        }

        const thirdPlaceGame = medalRound.games[1];
        if (thirdPlaceGame && thirdPlaceGame.score1 !== thirdPlaceGame.score2) {
            const thirdWinnerId = thirdPlaceGame.score1 > thirdPlaceGame.score2 ? thirdPlaceGame.player1_id : thirdPlaceGame.player2_id;
            const thirdLoserId = thirdPlaceGame.score1 > thirdPlaceGame.score2 ? thirdPlaceGame.player2_id : thirdPlaceGame.player1_id;
            placementRank.set(thirdWinnerId, 2); // 3. místo
            placementRank.set(thirdLoserId, 1); // 4. místo
        }
    }

    playoffFinishedGames.value.forEach(game => {
        if (!game.player1 || !game.player2) {
            return;
        }

        if (game.score1 === game.score2) {
            return;
        }

        // Zápas o 3. místo neovlivňuje "postup do kola", ten byl určen v semifinále.
        if (thirdPlaceGameId && game.id === thirdPlaceGameId) {
            return;
        }

        const round = game.round || 1;
        const winnerId = game.score1 > game.score2 ? game.player1_id : game.player2_id;
        const loserId = game.score1 > game.score2 ? game.player2_id : game.player1_id;

        const currentWinnerRound = playRounds.get(winnerId) || 0;
        playRounds.set(winnerId, Math.max(currentWinnerRound, round));

        const defaultLoserRound = finalGameId && game.id === finalGameId ? round - 1 : round - 1;
        if (!playRounds.has(loserId)) {
            playRounds.set(loserId, defaultLoserRound);
        }
    });

    return baseStandings
        .filter(player => player.name !== '__BYE__')
        .map(player => ({
            ...player,
            placement_rank: placementRank.get(player.id) || 0,
            playoff_round: playRounds.get(player.id) ?? -1
        }))
        .sort((a, b) => {
            if (a.placement_rank !== b.placement_rank) {
                return b.placement_rank - a.placement_rank;
            }
            if (a.playoff_round !== b.playoff_round) {
                return b.playoff_round - a.playoff_round;
            }
            if (a.points !== b.points) {
                return b.points - a.points;
            }
            if (a.score_diff !== b.score_diff) {
                return b.score_diff - a.score_diff;
            }
            return 0;
        });
});

// --- OPRAVA: TOTO ZDE CHYBĚLO ---
// Potřebujeme seznam běžících zápasů pro kontrolu kolizí hráčů
const activeGames = computed(() => 
    allGames.value.filter(g => g.status === 'in_progress')
);
// --------------------------------

// Získat zápas pro konkrétní stůl
const getMatchOnVenue = (venueNum) => {
    return activeGames.value.find(g => g.venue === venueNum);
};

// --- KONTROLA KOLIZÍ (Zaneprázdnění hráči) ---
const isPlayerBusy = (playerId) => {
    return activeGames.value.some(match => 
        match.player1_id === playerId || match.player2_id === playerId
    );
};

const getMatchConflictReason = (match) => {
    if (isPlayerBusy(match.player1_id)) return `Hraje ${match.player1.name}`;
    if (isPlayerBusy(match.player2_id)) return `Hraje ${match.player2.name}`;
    return null;
};
// ---------------------------------------------

const loadData = async () => {
    await store.fetchTournamentDetail(route.params.id);
    if (tournament.value && tournament.value.status !== 'draft') {
        const { data } = await api.get(`/tournaments/${route.params.id}/standings`);
        standings.value = data;
    }
};

const handleStartTournament = async () => {
    if (!confirm('Opravdu chcete začít turnaj?')) return;
    try {
        await store.generateMatches(route.params.id);
        await loadData();
    } catch (error) {
        alert('Chyba při generování rozlosování: ' + (error.response?.data?.message || error.message));
    }
};

const handleAddPlayer = async () => {
    const name = newPlayerName.value.trim();
    if (!name) return;

    // 1. KONTROLA DUPLICITY NA FRONTENDU (Rychlá odezva)
    const duplicate = tournament.value.players.find(p => p.name.toLowerCase() === name.toLowerCase());
    
    if (duplicate) {
        alert(`Hráč se jménem "${duplicate.name}" už v turnaji je.\nProsím, zvolte jiné jméno (např. "${name} 2").`);
        // Input zůstane vyplněný, uživatel ho může opravit
        return; 
    }

    // Pokud je vše OK, pošleme na server
    await store.addPlayer(route.params.id, name);
    newPlayerName.value = '';
};
const updateVenues = async (e) => { const count = parseInt(e.target.value); if (count > 0) await store.updateTournament(tournament.value.id, { venues_count: count }); };

const updateNumberOfGroups = async (e) => {
    const count = parseInt(e.target.value);
    if (Number.isNaN(count)) {
        return;
    }
    if (count < 2 || count % 2 !== 0) {
        alert('Počet skupin musí být sudý (minimálně 2).');
        e.target.value = tournament.value?.number_of_groups || 2;
        return;
    }
    await store.updateTournament(tournament.value.id, { number_of_groups: count });
};

// --- AKCE ---
const openAssignModal = (venueNum) => {
    selectedVenue.value = venueNum;
    showAssignModal.value = true;
};

const assignMatchToVenue = async (matchId) => {
    try {
        await api.post(`/games/${matchId}/assign`, { venue: selectedVenue.value });
        showAssignModal.value = false;
        await loadData();
    } catch (e) {
        alert('Chyba: ' + (e.response?.data?.error || e.message));
    }
};

const startEditing = (match) => { editingMatchId.value = match.id; tempScore1.value = 0; tempScore2.value = 0; };
const cancelEditing = () => { editingMatchId.value = null; };
const saveScore = async (matchId) => {
    saving.value = true;
    try {
        const match = allGames.value.find(g => g.id === matchId);
        if (match?.group_id === null && tempScore1.value === tempScore2.value) {
            alert('Zápas v pavouku nesmí skončit remízou.');
            return;
        }
        await api.put(`/games/${matchId}`, { score1: tempScore1.value, score2: tempScore2.value });
        editingMatchId.value = null;
        await loadData();
    } catch (e) { alert('Chyba: ' + e.message); } finally { saving.value = false; }
};

// Uvolnit stůl (vrátit zápas do fronty)
const unassignMatch = async (matchId) => {
    if (!confirm('Opravdu chcete tento zápas vrátit zpět do fronty?')) return;
    
    try {
        await api.post(`/games/${matchId}/unassign`);
        editingMatchId.value = null; // Zavřít editaci
        await loadData(); // Obnovit data
    } catch (e) {
        alert('Chyba: ' + e.message);
    }
};

// --- ÚPRAVA DOKONČENÝCH ZÁPASŮ ---
const editingFinishedMatch = ref(null); // Zápas, který zrovna upravujeme
const editScore1 = ref(0);
const editScore2 = ref(0);

const openEditFinishedMatch = (match) => {
    editingFinishedMatch.value = match;
    // Předvyplníme aktuální skóre
    editScore1.value = match.score1;
    editScore2.value = match.score2;
};

const saveFinishedMatch = async () => {
    if (!editingFinishedMatch.value) return;
    
    try {
        if (editingFinishedMatch.value.group_id === null && editScore1.value === editScore2.value) {
            alert('Zápas v pavouku nesmí skončit remízou.');
            return;
        }
        // Použijeme stejný endpoint jako pro normální ukládání
        await api.put(`/games/${editingFinishedMatch.value.id}`, {
            score1: editScore1.value,
            score2: editScore2.value
        });
        
        editingFinishedMatch.value = null; // Zavřít okno
        await loadData(); // Obnovit tabulku a seznam
    } catch (e) {
        alert('Chyba při úpravě: ' + e.message);
    }
};

// NOVÉ PROMĚNNÉ PRO EDITACI HRÁČE
const editingPlayerId = ref(null);
const editingPlayerName = ref('');

// NOVÉ FUNKCE PRO PŘEJMENOVÁNÍ
const startEditingPlayer = (player) => {
    editingPlayerId.value = player.id;
    editingPlayerName.value = player.name;
};

const cancelEditingPlayer = () => {
    editingPlayerId.value = null;
    editingPlayerName.value = '';
};

const savePlayerName = async (player) => {
    const newName = editingPlayerName.value.trim();
    
    if (!newName || newName === player.name) {
        cancelEditingPlayer();
        return;
    }

    // Kontrola duplicity při přejmenování
    const duplicate = tournament.value.players.find(p => p.name.toLowerCase() === newName.toLowerCase() && p.id !== player.id);
    if (duplicate) {
         alert(`Hráč se jménem "${duplicate.name}" už v turnaji je.`);
         return;
    }

    try {
        // Volání API (musíte mít api importované z axiosu)
        await api.put(`/players/${player.id}`, { name: newName });
        
        // Aktualizace dat lokálně (abychom nemuseli reloadovat celou stránku)
        player.name = newName;
        editingPlayerId.value = null;
    } catch (e) {
        alert('Chyba: ' + (e.response?.data?.error || e.message));
    }
};

// --- LOGIKA PRO DRAG-SCROLL V HISTORII ---
const historyContainer = ref(null);
const isDraggingHistory = ref(false);
let startXHistory = 0;
let scrollLeftHistory = 0;
let dragDistanceHistory = 0;

const startDragHistory = (e) => {
    isDraggingHistory.value = true;
    dragDistanceHistory = 0; // Vynulujeme vzdálenost při startu
    startXHistory = e.pageX - historyContainer.value.offsetLeft;
    scrollLeftHistory = historyContainer.value.scrollLeft;
};

const doDragHistory = (e) => {
    if (!isDraggingHistory.value) return;
    e.preventDefault();
    const x = e.pageX - historyContainer.value.offsetLeft;
    const walk = (x - startXHistory) * 2;
    
    // Počítáme, jak daleko jsme myší ujeli
    dragDistanceHistory = Math.abs(x - startXHistory);
    
    historyContainer.value.scrollLeft = scrollLeftHistory - walk;
};

const stopDragHistory = () => {
    isDraggingHistory.value = false;
};

const downloadExport = () => {
    // Vytvoříme odkaz přímo na API endpoint
    // Protože používáme Laravel Sail/lokální server, cesta je obvykle:
    // http://localhost/api/tournaments/{id}/export
    
    // Získáme base URL z API klienta nebo natvrdo (záleží jak máte nastavený axios)
    // Nejjednodušší pro Vue + Laravel stack:
    const exportUrl = `${import.meta.env.VITE_API_URL || 'http://localhost/api'}/tournaments/${route.params.id}/export`;
    
    // Otevření v novém okně spustí stahování
    window.location.href = exportUrl;
};

onMounted(loadData);
</script>

<template>
    <div v-if="tournament" class="max-w-[95%] mx-auto space-y-6 pb-12 relative">
        
        <div class="bg-white rounded-xl shadow p-6 border-l-4 border-indigo-600 flex flex-col md:flex-row justify-between items-center gap-4">
            <div>
                <h1 class="text-3xl font-bold text-gray-900 flex items-center gap-3">
                    {{ tournament.name }}
                    <span v-if="tournament.status === 'in_progress'" class="bg-green-100 text-green-800 text-sm px-2 py-1 rounded animate-pulse">Běží</span>
                    <span v-else-if="tournament.status === 'finished'" class="bg-gray-100 text-gray-600 text-sm px-2 py-1 rounded">Ukončeno</span>
                    <span v-else class="bg-yellow-100 text-yellow-800 text-sm px-2 py-1 rounded">Příprava</span>
                </h1>
            </div>
            <div class="flex items-center gap-3 bg-indigo-50 p-3 rounded-lg border border-indigo-100">
                <label class="text-sm font-bold text-indigo-900 uppercase tracking-wide">🎯 Počet míst:</label>
                <input type="number" min="1" :value="tournament.venues_count || 1" @change="updateVenues" class="w-20 text-center font-bold text-lg border-indigo-300 rounded py-1" />
            </div>
        </div>

        <div v-if="tournament.status === 'draft'" class="bg-white rounded-xl shadow p-6">
            <div class="flex justify-between items-center mb-4">
              <div class="flex items-center gap-3">
                <h2 class="text-xl font-bold">Registrace</h2>
                <span class="text-xs font-bold text-indigo-700 bg-indigo-100 px-2 py-1 rounded-full">
                    Hráči: {{ tournament.players.length }}
                </span>
              </div>
                <div v-if="tournament.format === 'groups'" class="flex items-center gap-2">
                    <label class="font-bold text-gray-700">Počet skupin:</label>
                    <input type="number" min="2" step="2" :value="tournament.number_of_groups || 2" @change="updateNumberOfGroups" class="w-20 text-center font-bold text-lg border-2 border-indigo-200 bg-indigo-50 rounded py-1 focus:border-indigo-500 focus:ring-indigo-500" />
                </div>
            </div>
            <div class="flex gap-4 mb-4"><input v-model="newPlayerName" @keyup.enter="handleAddPlayer" class="border p-2 rounded flex-1" placeholder="Jméno" /><button @click="handleAddPlayer" class="bg-indigo-600 text-white px-4 rounded">Přidat</button></div>
            <div class="flex flex-wrap gap-2 mb-4">
                <div v-for="p in tournament.players" :key="p.id" 
                    class="bg-gray-100 px-3 py-1.5 rounded-lg flex items-center gap-2 border border-gray-200 shadow-sm transition-all hover:bg-white hover:border-indigo-300 group">
                    
                    <template v-if="editingPlayerId !== p.id">
                        <span class="font-medium text-gray-800 cursor-pointer" @dblclick="startEditingPlayer(p)" title="Dvojklikem upravíte">
                            {{ p.name }}
                        </span>
                        
                        <button @click="startEditingPlayer(p)" class="text-gray-400 hover:text-indigo-600 opacity-0 group-hover:opacity-100 transition-opacity">
                            ✏️
                        </button>

                        <button @click="store.removePlayer(p.id)" class="text-gray-400 hover:text-red-500 font-bold ml-1">
                            &times;
                        </button>
                    </template>

                    <template v-else>
                        <input 
                            v-model="editingPlayerName" 
                            @keyup.enter="savePlayerName(p)"
                            @keyup.esc="cancelEditingPlayer"
                            ref="playerInput"
                            class="w-32 px-1 py-0 text-sm border-b-2 border-indigo-500 bg-transparent focus:outline-none"
                            autoFocus
                        />
                        <button @click="savePlayerName(p)" class="text-green-600 hover:text-green-800 font-bold text-xs">OK</button>
                        <button @click="cancelEditingPlayer" class="text-gray-400 hover:text-gray-600 text-xs">&times;</button>
                    </template>

                </div>
                
                <div v-if="tournament.players.length === 0" class="text-gray-400 italic text-sm py-2">
                    Zatím žádní hráči.
                </div>
            </div>
            <button @click="handleStartTournament" :disabled="tournament.players.length < 2" class="bg-green-600 text-white font-bold py-3 px-8 rounded">Start</button>
        </div>

        <div v-else class="grid grid-cols-1 xl:grid-cols-3 gap-6">
            
            <div class="lg:col-span-1 space-y-6">
                <div v-if="tournament.status === 'finished' && finalStandings.length" class="bg-white rounded-xl shadow overflow-hidden">
                    <div class="px-4 py-3 border-b border-gray-100 bg-emerald-50 text-emerald-800 font-bold">Konečné pořadí</div>
                    <StandingsTable :standings="finalStandings" />
                </div>
                <div v-if="tournament.format === 'round_robin'" class="bg-white rounded-xl shadow overflow-hidden">
                    <StandingsTable :standings="standings" />
                </div>
                <div v-else class="space-y-6">
                    <div v-for="group in standings" :key="group.name" class="bg-white rounded-xl shadow overflow-hidden">
                        <StandingsTable :standings="group.standings" :title="group.name" />
                    </div>
                </div>
            </div>

            <div class="xl:col-span-2 space-y-8">
    
                <div>
                    <h2 class="text-2xl font-bold text-indigo-900 mb-4">Právě se hraje</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <div v-for="n in (tournament.venues_count || 1)" :key="n" class="relative">
                            
                            <div v-if="getMatchOnVenue(n)" 
                                    @click="startEditing(getMatchOnVenue(n))"
                                    class="bg-white border border-gray-200 shadow-lg rounded-xl overflow-hidden flex flex-col cursor-pointer hover:shadow-xl transition-all h-48 group relative">
                                
                                <div class="bg-gray-800 text-white px-4 py-2 flex justify-between items-center group-hover:bg-indigo-900 transition-colors z-0">
                                    <span class="font-bold uppercase text-sm">📍 Místo {{ n }}</span>
                                    <span class="text-xs text-gray-400">#{{ getMatchOnVenue(n).id }}</span>
                                </div>

                                <div class="p-4 flex-1 flex flex-col justify-center items-center relative z-0">
                                    <div class="text-center w-full">
                                        <div class="flex justify-between w-full font-bold text-lg items-center">
                                            <span class="truncate w-1/2 text-right pr-3">{{ getMatchOnVenue(n).player1.name }}</span>
                                            <span class="text-gray-300 text-sm font-normal">vs</span>
                                            <span class="truncate w-1/2 text-left pl-3">{{ getMatchOnVenue(n).player2.name }}</span>
                                        </div>
                                        <div class="text-xs text-indigo-400 mt-2 font-medium opacity-0 group-hover:opacity-100 transition-opacity">Klikni pro výsledek</div>
                                    </div>
                                </div>

                                <div class="h-1 w-full bg-gradient-to-r from-green-400 to-emerald-500 z-0"></div>

                                <div v-if="editingMatchId === getMatchOnVenue(n).id" @click.stop 
                                        class="absolute inset-0 bg-white z-20 flex flex-col p-3 animate-fade-in border-2 border-indigo-500 rounded-xl">
                                    
                                    <div class="text-xs text-center text-gray-400 font-bold uppercase tracking-widest mb-1">Zadání výsledku</div>

                                    <div class="flex-1 flex items-center justify-center gap-2 w-full">
                                        <div class="text-center">
                                            <div class="text-xs text-gray-500 mb-1 truncate w-20">{{ getMatchOnVenue(n).player1.name }}</div>
                                            <input v-model="tempScore1" type="number" min="0" class="w-16 text-center border-2 border-indigo-100 rounded-lg text-2xl font-bold py-1 focus:border-indigo-500 focus:ring-0" autoFocus />
                                        </div>
                                        <span class="font-bold text-gray-300 text-xl mt-4">:</span>
                                        <div class="text-center">
                                            <div class="text-xs text-gray-500 mb-1 truncate w-20">{{ getMatchOnVenue(n).player2.name }}</div>
                                            <input v-model="tempScore2" type="number" min="0" class="w-16 text-center border-2 border-indigo-100 rounded-lg text-2xl font-bold py-1 focus:border-indigo-500 focus:ring-0" />
                                        </div>
                                    </div>
                                    
                                    <div class="space-y-2 mt-2">
                                        <button @click="saveScore(getMatchOnVenue(n).id)" class="bg-green-500 hover:bg-green-600 text-white w-full py-2 rounded-lg font-bold shadow-sm transition-colors text-sm flex items-center justify-center gap-2">
                                            <span>✓</span> Uložit a ukončit
                                        </button>
                                        
                                        <div class="flex gap-2">
                                            <button @click="unassignMatch(getMatchOnVenue(n).id)" class="flex-1 bg-red-50 hover:bg-red-100 text-red-600 py-1.5 rounded-lg font-bold text-xs border border-red-100 transition-colors" title="Vrátit do fronty">
                                                ↩ Uvolnit stůl
                                            </button>
                                            <button @click="cancelEditing" class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-600 py-1.5 rounded-lg font-bold text-xs transition-colors">
                                                ✕ Zavřít
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div v-else 
                                    @click="openAssignModal(n)"
                                    class="h-48 border-2 border-dashed border-gray-300 rounded-xl flex flex-col items-center justify-center text-gray-400 hover:border-indigo-400 hover:text-indigo-600 hover:bg-indigo-50 cursor-pointer transition-all group bg-gray-50/50">
                                <span class="text-4xl mb-2 group-hover:scale-110 transition-transform font-light">+</span>
                                <span class="font-bold">Obsadit místo {{ n }}</span>
                            </div>

                        </div>
                    </div>
                </div>

                <div v-if="queuedGames.length > 0">
                    <div v-if="tournament.format === 'groups'">
                        <div v-if="playoffQueuedByRound.length > 0" class="mb-6">
                            <h2 class="text-xl font-bold text-gray-700 mb-3 flex items-center gap-2">
                                <span>🏆</span> Pavouk
                                <span class="text-sm font-normal text-gray-400 bg-gray-100 px-2 rounded-full">{{ playoffQueuedCount }}</span>
                            </h2>
                            <div v-for="round in playoffQueuedByRound" :key="round.round" class="rounded-2xl border border-indigo-100 bg-gradient-to-br from-indigo-50 via-white to-white p-4 shadow-sm mb-4">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="text-xs font-semibold text-indigo-700 uppercase tracking-[0.2em]">{{ round.label }}</div>
                                    <div class="text-xs font-bold text-indigo-500 bg-indigo-100 px-2 py-1 rounded-full">Čeká</div>
                                </div>
                                <div v-if="!round.isMedalRound" class="relative">
                                    <div class="absolute left-4 top-0 bottom-0 w-px bg-indigo-200/80"></div>
                                    <div class="space-y-4 pl-8">
                                        <div v-for="(match, index) in round.games" :key="match.id" class="relative">
                                            <div class="absolute -left-6 top-1/2 -translate-y-1/2 w-3.5 h-3.5 rounded-full bg-indigo-500 shadow ring-4 ring-indigo-100"></div>
                                            <div class="rounded-xl border border-indigo-100 bg-white/90 p-4 shadow-sm">
                                                <div class="flex justify-between text-[11px] text-indigo-400 font-bold mb-2 uppercase">
                                                    <span>{{ round.matchLabels[match.id] || `Duel ${index + 1}` }}</span>
                                                    <span>#{{ match.id }}</span>
                                                </div>
                                                <div class="flex items-center justify-between text-sm font-bold text-gray-800">
                                                    <div class="truncate w-[42%] text-right" :title="match.player1.name">{{ match.player1.name }}</div>
                                                    <span class="text-xs text-indigo-400 font-semibold">vs</span>
                                                    <div class="truncate w-[42%] text-left" :title="match.player2.name">{{ match.player2.name }}</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div v-else class="grid md:grid-cols-2 gap-4">
                                    <div v-for="sectionLabel in medalSections" :key="`${round.round}-${sectionLabel}`" class="rounded-xl border border-indigo-100 bg-white/90 p-4 shadow-sm">
                                        <div class="text-xs font-semibold text-indigo-600 uppercase tracking-[0.18em] mb-3">{{ sectionLabel }}</div>
                                        <div v-if="getMedalMatch(round, sectionLabel)" class="space-y-1">
                                            <div class="flex justify-between text-[11px] text-indigo-400 font-bold uppercase">
                                                <span>#{{ getMedalMatch(round, sectionLabel).id }}</span>
                                                <span>Čeká</span>
                                            </div>
                                            <div class="flex items-center justify-between text-sm font-bold text-gray-800">
                                                <div class="truncate w-[42%] text-right" :title="getMedalMatch(round, sectionLabel).player1.name">{{ getMedalMatch(round, sectionLabel).player1.name }}</div>
                                                <span class="text-xs text-indigo-400 font-semibold">vs</span>
                                                <div class="truncate w-[42%] text-left" :title="getMedalMatch(round, sectionLabel).player2.name">{{ getMedalMatch(round, sectionLabel).player2.name }}</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div v-for="group in groupedQueuedGames" :key="group.id">
                            <h2 class="text-xl font-bold text-gray-700 mb-3 flex items-center gap-2">
                                <span>⏳</span> Fronta: {{ group.name }}
                                <span class="text-sm font-normal text-gray-400 bg-gray-100 px-2 rounded-full">{{ group.games.length }}</span>
                            </h2>
                            <div class="flex overflow-x-auto pb-6 gap-4 scrollbar-thin scrollbar-thumb-gray-300 scrollbar-track-transparent cursor-grab active:cursor-grabbing select-none p-2 -ml-2 transition-all">
                                <div v-for="match in group.games" :key="match.id" 
                                        class="flex-shrink-0 w-64 bg-white border border-gray-200 rounded-lg p-4 shadow-sm opacity-90 hover:opacity-100 transition-opacity pointer-events-none md:pointer-events-auto">
                                    <div class="flex justify-between text-xs text-gray-400 font-bold mb-2">
                                        <span>#{{ match.id }}</span>
                                        <span>ČEKÁ</span>
                                    </div>
                                    <div class="flex items-center justify-center w-full gap-1 font-bold text-gray-800">
                                        <div class="truncate max-w-[45%] text-right" :title="match.player1.name">
                                            {{ match.player1.name }}
                                        </div>
                                        <span class="text-gray-400 text-xs font-normal shrink-0">vs</span>
                                        <div class="truncate max-w-[45%] text-left" :title="match.player2.name">
                                            {{ match.player2.name }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-else>
                        <h2 class="text-xl font-bold text-gray-700 mb-3 flex items-center gap-2">
                            <span>⏳</span> Ve frontě 
                            <span class="text-sm font-normal text-gray-400 bg-gray-100 px-2 rounded-full">{{ queuedGames.length }}</span>
                            <span class="text-xs text-gray-400 font-normal ml-2 hidden md:inline opacity-75">(Tažením myši posunete)</span>
                        </h2>
                        
                        <div ref="queueContainer" @mousedown="startDrag" @mouseleave="stopDrag" @mouseup="stopDrag" @mousemove="doDrag"
                                class="flex overflow-x-auto pb-6 gap-4 scrollbar-thin scrollbar-thumb-gray-300 scrollbar-track-transparent cursor-grab active:cursor-grabbing select-none p-2 -ml-2 transition-all"
                                :class="{ 'bg-gray-100 scale-[0.99] rounded-xl': isDragging }">
                            
                            <div v-for="match in queuedGames" :key="match.id" 
                                    class="flex-shrink-0 w-64 bg-white border border-gray-200 rounded-lg p-4 shadow-sm opacity-90 hover:opacity-100 transition-opacity pointer-events-none md:pointer-events-auto"
                                    :class="isDragging ? 'opacity-80' : ''">
                                <div class="flex justify-between text-xs text-gray-400 font-bold mb-2">
                                    <span>#{{ match.id }}</span>
                                    <span>ČEKÁ</span>
                                </div>
                                <div class="flex items-center justify-center w-full gap-1 font-bold text-gray-800">
                                    <div class="truncate max-w-[45%] text-right" :title="match.player1.name">
                                        {{ match.player1.name }}
                                    </div>
                                    <span class="text-gray-400 text-xs font-normal shrink-0">vs</span>
                                    <div class="truncate max-w-[45%] text-left" :title="match.player2.name">
                                        {{ match.player2.name }}
                                    </div>
                                </div>
                            </div>
                            
                            <div v-if="queuedGames.length === 0" class="text-gray-400 italic pl-2 py-4">
                                Fronta je prázdná.
                            </div>
                        </div>
                    </div>
                </div>

                <div>
                    <div v-if="tournament.format === 'groups'">
                        <div v-if="playoffFinishedByRound.length > 0" class="mb-8">
                            <h2 class="text-xl font-bold text-gray-700 mb-3 flex items-center gap-2">
                                <span>🏆</span> Pavouk odehráno
                                <span class="text-sm font-normal text-gray-400 bg-gray-100 px-2 rounded-full">{{ playoffFinishedCount }}</span>
                            </h2>
                            <div v-for="round in playoffFinishedByRound" :key="round.round" class="rounded-2xl border border-emerald-100 bg-gradient-to-br from-emerald-50 via-white to-white p-4 shadow-sm mb-4"
                                @mousedown="startDragHistory" @mouseleave="stopDragHistory" @mouseup="stopDragHistory" @mousemove="doDragHistory">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="text-xs font-semibold text-emerald-700 uppercase tracking-[0.2em]">{{ round.label }}</div>
                                    <div class="text-xs font-bold text-emerald-600 bg-emerald-100 px-2 py-1 rounded-full">Hotovo</div>
                                </div>
                                <div v-if="!round.isMedalRound" class="relative">
                                    <div class="absolute left-4 top-0 bottom-0 w-px bg-emerald-200/80"></div>
                                    <div class="space-y-4 pl-8">
                                        <div v-for="(match, index) in round.games" :key="match.id" class="relative"
                                            @click="dragDistanceHistory < 5 && openEditFinishedMatch(match)">
                                            <div class="absolute -left-6 top-1/2 -translate-y-1/2 w-3.5 h-3.5 rounded-full bg-emerald-500 shadow ring-4 ring-emerald-100"></div>
                                            <div class="rounded-xl border border-emerald-100 bg-white/90 p-4 shadow-sm cursor-pointer hover:shadow-md transition">
                                                <div class="flex justify-between text-[11px] text-emerald-400 font-bold mb-2 uppercase">
                                                    <span>{{ round.matchLabels[match.id] || `Duel ${index + 1}` }}</span>
                                                    <span>#{{ match.id }}</span>
                                                </div>
                                                <div class="flex items-center justify-between w-full text-sm font-bold text-gray-800">
                                                    <div class="truncate w-[35%] text-right" :class="{ 'text-emerald-700': match.score1 > match.score2, 'opacity-60': match.score1 < match.score2 }" :title="match.player1.name">{{ match.player1.name }}</div>
                                                    <div class="bg-emerald-50 px-2 py-1 rounded text-emerald-800 mx-1 shrink-0">{{ match.score1 }}:{{ match.score2 }}</div>
                                                    <div class="truncate w-[35%] text-left" :class="{ 'text-emerald-700': match.score2 > match.score1, 'opacity-60': match.score2 < match.score1 }" :title="match.player2.name">{{ match.player2.name }}</div>
                                                </div>
                                                <div class="text-[10px] text-emerald-500 font-bold mt-2 uppercase tracking-widest">Klikni pro úpravu</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div v-else class="grid md:grid-cols-2 gap-4">
                                    <div
                                        v-for="sectionLabel in medalSections"
                                        :key="`${round.round}-finished-${sectionLabel}`"
                                        class="rounded-xl border border-emerald-100 bg-white/90 p-4 shadow-sm cursor-pointer hover:shadow-md transition"
                                        @click="dragDistanceHistory < 5 && getMedalMatch(round, sectionLabel) && openEditFinishedMatch(getMedalMatch(round, sectionLabel))"
                                    >
                                        <div class="text-xs font-semibold text-emerald-600 uppercase tracking-[0.18em] mb-3">{{ sectionLabel }}</div>
                                        <div v-if="getMedalMatch(round, sectionLabel)" class="space-y-1">
                                            <div class="flex justify-between text-[11px] text-emerald-400 font-bold uppercase">
                                                <span>#{{ getMedalMatch(round, sectionLabel).id }}</span>
                                                <span>Hotovo</span>
                                            </div>
                                            <div class="flex items-center justify-between w-full text-sm font-bold text-gray-800">
                                                <div
                                                    class="truncate w-[35%] text-right"
                                                    :class="{ 'text-emerald-700': getMedalMatch(round, sectionLabel).score1 > getMedalMatch(round, sectionLabel).score2, 'opacity-60': getMedalMatch(round, sectionLabel).score1 < getMedalMatch(round, sectionLabel).score2 }"
                                                    :title="getMedalMatch(round, sectionLabel).player1.name"
                                                >
                                                    {{ getMedalMatch(round, sectionLabel).player1.name }}
                                                </div>
                                                <div class="bg-emerald-50 px-2 py-1 rounded text-emerald-800 mx-1 shrink-0">{{ getMedalMatch(round, sectionLabel).score1 }}:{{ getMedalMatch(round, sectionLabel).score2 }}</div>
                                                <div
                                                    class="truncate w-[35%] text-left"
                                                    :class="{ 'text-emerald-700': getMedalMatch(round, sectionLabel).score2 > getMedalMatch(round, sectionLabel).score1, 'opacity-60': getMedalMatch(round, sectionLabel).score2 < getMedalMatch(round, sectionLabel).score1 }"
                                                    :title="getMedalMatch(round, sectionLabel).player2.name"
                                                >
                                                    {{ getMedalMatch(round, sectionLabel).player2.name }}
                                                </div>
                                            </div>
                                            <div class="text-[10px] text-emerald-500 font-bold mt-2 uppercase tracking-widest">Klikni pro úpravu</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div v-for="group in groupedFinishedGames" :key="group.id" class="mb-8">
                            <h2 class="text-xl font-bold text-gray-700 mb-3 flex items-center gap-2">
                                <span>✅</span> Odehráno: {{ group.name }}
                                <span class="text-sm font-normal text-gray-400 bg-gray-100 px-2 rounded-full">{{ group.games.length }}</span>
                            </h2>
                            <div class="flex overflow-x-auto pb-6 gap-4 scrollbar-thin scrollbar-thumb-gray-300 scrollbar-track-transparent p-2 -ml-2 cursor-grab active:cursor-grabbing select-none"
                                @mousedown="startDragHistory" @mouseleave="stopDragHistory" @mouseup="stopDragHistory" @mousemove="doDragHistory">
                                <div v-for="match in group.games" :key="match.id"
                                    @click="dragDistanceHistory < 5 && openEditFinishedMatch(match)"
                                    class="flex-shrink-0 w-64 hover:h-28 bg-white border border-gray-200 rounded-lg p-4 shadow-sm group hover:border-indigo-400 hover:shadow-md transition-all duration-300 ease-in-out relative overflow-hidden hover:scale-105 origin-center flex flex-col justify-center cursor-pointer"
                                    title="Klikni pro opravu výsledku">
                                    
                                    <div class="flex justify-between text-xs text-gray-400 font-bold mb-3">
                                        <span>#{{ match.id }}</span>
                                        <span class="text-green-600 bg-green-50 px-1.5 py-0.5 rounded">HOTOVO</span>
                                    </div>
                                    <div class="flex items-center justify-between w-full font-bold text-gray-800 text-sm">
                                        <div class="truncate w-[35%] text-right" :class="{ 'text-green-700': match.score1 > match.score2, 'opacity-60': match.score1 < match.score2 }" :title="match.player1.name">{{ match.player1.name }}</div>
                                        <div class="bg-gray-100 px-2 py-1 rounded text-gray-900 mx-1 shrink-0">{{ match.score1 }}:{{ match.score2 }}</div>
                                        <div class="truncate w-[35%] text-left" :class="{ 'text-green-700': match.score2 > match.score1, 'opacity-60': match.score2 < match.score1 }" :title="match.player2.name">{{ match.player2.name }}</div>
                                    </div>
                                    <div class="absolute inset-x-0 bottom-0 bg-indigo-50 text-indigo-600 text-[10px] text-center py-1 opacity-0 group-hover:opacity-100 transition-opacity font-bold">Upravit výsledek ✏️</div>
                                </div>
                                <div v-if="group.games.length === 0" class="text-gray-400 italic pl-2 py-4 pointer-events-none">V této skupině nebyly odehrány žádné zápasy.</div>
                            </div>
                        </div>
                    </div>
                    <div v-else>
                        <h2 class="text-xl font-bold text-gray-700 mb-3 flex items-center gap-2">
                            <span>✅</span> Odehráno
                            <span class="text-sm font-normal text-gray-400 bg-gray-100 px-2 rounded-full">{{ finishedGames.length }}</span>
                            <span class="text-xs text-gray-400 font-normal ml-2 hidden md:inline opacity-75">(Tažením posunete)</span>
                        </h2>
                        
                        <div ref="historyContainer" 
                            @mousedown="startDragHistory" 
                            @mouseleave="stopDragHistory" 
                            @mouseup="stopDragHistory" 
                            @mousemove="doDragHistory"
                            class="flex overflow-x-auto pb-6 gap-4 scrollbar-thin scrollbar-thumb-gray-300 scrollbar-track-transparent p-2 -ml-2 cursor-grab active:cursor-grabbing select-none transition-all"
                            :class="{ 'scale-[0.99]': isDraggingHistory }">
                            
                            <div v-for="match in finishedGames" :key="match.id" 
                                @click="dragDistanceHistory < 5 && openEditFinishedMatch(match)"
                                class="flex-shrink-0 w-64 hover:h-28 bg-white border border-gray-200 rounded-lg p-4 shadow-sm group hover:border-indigo-400 hover:shadow-md transition-all duration-300 ease-in-out relative overflow-hidden hover:scale-105 origin-center flex flex-col justify-center cursor-pointer"
                                :class="{ 'select-none': isDraggingHistory }"
                                title="Klikni pro opravu výsledku">
                                
                                <div class="flex justify-between text-xs text-gray-400 font-bold mb-3">
                                    <span>#{{ match.id }}</span>
                                    <span class="text-green-600 bg-green-50 px-1.5 py-0.5 rounded">HOTOVO</span>
                                </div>

                                <div class="flex items-center justify-between w-full font-bold text-gray-800 text-sm">
                                    <div class="truncate w-[35%] text-right" :class="{ 'text-green-700': match.score1 > match.score2, 'opacity-60': match.score1 < match.score2 }" :title="match.player1.name">{{ match.player1.name }}</div>
                                    <div class="bg-gray-100 px-2 py-1 rounded text-gray-900 mx-1 shrink-0">{{ match.score1 }}:{{ match.score2 }}</div>
                                    <div class="truncate w-[35%] text-left" :class="{ 'text-green-700': match.score2 > match.score1, 'opacity-60': match.score2 < match.score1 }" :title="match.player2.name">{{ match.player2.name }}</div>
                                </div>

                                <div class="absolute inset-x-0 bottom-0 bg-indigo-50 text-indigo-600 text-[10px] text-center py-1 opacity-0 group-hover:opacity-100 transition-opacity font-bold">Upravit výsledek ✏️</div>
                            </div>
                            
                            <div v-if="finishedGames.length === 0" class="text-gray-400 italic pl-2 py-4 pointer-events-none">
                                Zatím žádné odehrané zápasy.
                            </div>

                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="showAssignModal" class="fixed inset-0 bg-black/60 z-50 flex items-center justify-center backdrop-blur-sm p-4 animate-fade-in" @click.self="showAssignModal = false">
            <div class="bg-white rounded-xl shadow-2xl max-w-lg w-full overflow-hidden transform transition-all scale-100">
                <div class="bg-indigo-600 px-6 py-4 flex justify-between items-center">
                    <h3 class="text-white font-bold text-lg">Vybrat zápas pro Místo {{ selectedVenue }}</h3>
                    <button @click="showAssignModal = false" class="text-indigo-200 hover:text-white text-2xl font-bold">&times;</button>
                </div>
                
                <div class="p-2 max-h-[60vh] overflow-y-auto bg-gray-50">
                    <div v-if="queuedGames.length === 0" class="text-center py-10 text-gray-500">
                        Žádné zápasy ve frontě.
                    </div>
                    <div v-else>
                        <div v-if="tournament.format === 'groups'">
                             <div v-if="playoffQueuedGames.length > 0" class="p-2">
                                <h4 class="font-bold text-indigo-800 bg-indigo-100 px-3 py-2 rounded-lg text-sm sticky top-0">
                                    Pavouk
                                </h4>
                                <div class="grid gap-2 pt-3">
                                    <button v-for="match in playoffQueuedGames" :key="match.id" 
                                        @click="!getMatchConflictReason(match) && assignMatchToVenue(match.id)"
                                        :disabled="!!getMatchConflictReason(match)"
                                        class="flex items-center justify-between p-4 rounded-lg border transition-all group text-left relative overflow-hidden shadow-sm"
                                        :class="getMatchConflictReason(match) 
                                            ? 'bg-gray-100 border-gray-200 opacity-60 cursor-not-allowed grayscale' 
                                            : 'bg-white border-gray-200 hover:border-indigo-500 hover:bg-indigo-50 hover:shadow-md cursor-pointer'">
                                        <div class="flex-1">
                                            <div class="font-bold text-lg" :class="getMatchConflictReason(match) ? 'text-gray-500' : 'text-gray-800 group-hover:text-indigo-700'">
                                                {{ match.player1.name }} <span class="text-gray-400 font-normal text-sm">vs</span> {{ match.player2.name }}
                                            </div>
                                            <div class="text-xs text-gray-400 mt-1">ID zápasu: #{{ match.id }}</div>
                                            <div v-if="getMatchConflictReason(match)" class="text-xs text-red-500 font-bold mt-2 flex items-center gap-1 bg-red-50 p-1 rounded w-fit px-2">
                                                <span>⛔</span> {{ getMatchConflictReason(match) }} je zaneprázdněn
                                            </div>
                                        </div>
                                        <div v-if="!getMatchConflictReason(match)" class="bg-indigo-100 text-indigo-600 w-8 h-8 flex items-center justify-center rounded-full opacity-0 group-hover:opacity-100 transition-all transform group-hover:translate-x-0 translate-x-2">➤</div>
                                    </button>
                                </div>
                                <div v-if="playoffQueuedGames.length === 0" class="text-center py-4 text-sm text-gray-400 italic">
                                    V pavouku nejsou žádné zápasy ve frontě.
                                </div>
                            </div>
                             <div v-for="group in groupedQueuedGames" :key="group.id" class="p-2">
                                <h4 class="font-bold text-indigo-800 bg-indigo-100 px-3 py-2 rounded-lg text-sm sticky top-0">
                                    {{ group.name }}
                                </h4>
                                <div class="grid gap-2 pt-3">
                                    <button v-for="match in group.games" :key="match.id" 
                                        @click="!getMatchConflictReason(match) && assignMatchToVenue(match.id)"
                                        :disabled="!!getMatchConflictReason(match)"
                                        class="flex items-center justify-between p-4 rounded-lg border transition-all group text-left relative overflow-hidden shadow-sm"
                                        :class="getMatchConflictReason(match) 
                                            ? 'bg-gray-100 border-gray-200 opacity-60 cursor-not-allowed grayscale' 
                                            : 'bg-white border-gray-200 hover:border-indigo-500 hover:bg-indigo-50 hover:shadow-md cursor-pointer'">
                                        <!-- ... obsah tlačítka ... -->
                                        <div class="flex-1">
                                            <div class="font-bold text-lg" :class="getMatchConflictReason(match) ? 'text-gray-500' : 'text-gray-800 group-hover:text-indigo-700'">
                                                {{ match.player1.name }} <span class="text-gray-400 font-normal text-sm">vs</span> {{ match.player2.name }}
                                            </div>
                                            <div class="text-xs text-gray-400 mt-1">ID zápasu: #{{ match.id }}</div>
                                            <div v-if="getMatchConflictReason(match)" class="text-xs text-red-500 font-bold mt-2 flex items-center gap-1 bg-red-50 p-1 rounded w-fit px-2">
                                                <span>⛔</span> {{ getMatchConflictReason(match) }} je zaneprázdněn
                                            </div>
                                        </div>
                                        <div v-if="!getMatchConflictReason(match)" class="bg-indigo-100 text-indigo-600 w-8 h-8 flex items-center justify-center rounded-full opacity-0 group-hover:opacity-100 transition-all transform group-hover:translate-x-0 translate-x-2">➤</div>
                                    </button>
                                </div>
                                <div v-if="group.games.length === 0" class="text-center py-4 text-sm text-gray-400 italic">
                                    V této skupině nejsou žádné zápasy ve frontě.
                                </div>
                            </div>
                        </div>
                        <div v-else class="grid gap-2 p-2">
                            <button v-for="match in queuedGames" :key="match.id" 
                                @click="!getMatchConflictReason(match) && assignMatchToVenue(match.id)"
                                :disabled="!!getMatchConflictReason(match)"
                                class="flex items-center justify-between p-4 rounded-lg border transition-all group text-left relative overflow-hidden shadow-sm"
                                :class="getMatchConflictReason(match) 
                                    ? 'bg-gray-100 border-gray-200 opacity-60 cursor-not-allowed grayscale' 
                                    : 'bg-white border-gray-200 hover:border-indigo-500 hover:bg-indigo-50 hover:shadow-md cursor-pointer'"
                            >
                                <div class="flex-1">
                                    <div class="font-bold text-lg" :class="getMatchConflictReason(match) ? 'text-gray-500' : 'text-gray-800 group-hover:text-indigo-700'">
                                        {{ match.player1.name }} <span class="text-gray-400 font-normal text-sm">vs</span> {{ match.player2.name }}
                                    </div>
                                    <div class="text-xs text-gray-400 mt-1">ID zápasu: #{{ match.id }}</div>
                                    
                                    <div v-if="getMatchConflictReason(match)" class="text-xs text-red-500 font-bold mt-2 flex items-center gap-1 bg-red-50 p-1 rounded w-fit px-2">
                                        <span>⛔</span> {{ getMatchConflictReason(match) }} je zaneprázdněn
                                    </div>
                                </div>

                                <div v-if="!getMatchConflictReason(match)" class="bg-indigo-100 text-indigo-600 w-8 h-8 flex items-center justify-center rounded-full opacity-0 group-hover:opacity-100 transition-all transform group-hover:translate-x-0 translate-x-2">
                                    ➤
                                </div>
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white px-6 py-3 text-right border-t border-gray-100">
                    <button @click="showAssignModal = false" class="text-gray-500 hover:text-gray-800 font-medium text-sm px-4 py-2 hover:bg-gray-100 rounded transition-colors">Zrušit</button>
                </div>
            </div>
        </div>

    </div>

    <div v-if="editingFinishedMatch" class="fixed inset-0 bg-black/60 z-50 flex items-center justify-center backdrop-blur-sm p-4 animate-fade-in" @click.self="editingFinishedMatch = null">
        <div class="bg-white rounded-xl shadow-2xl max-w-sm w-full overflow-hidden transform transition-all scale-100 p-6">
            
            <h3 class="text-lg font-bold text-gray-800 text-center mb-1">Oprava výsledku</h3>
            <p class="text-xs text-gray-400 text-center mb-6 uppercase tracking-widest">Zápas #{{ editingFinishedMatch.id }}</p>

            <div class="flex justify-between items-center mb-2 px-2">
                <span class="font-bold text-gray-700 w-1/2 text-right truncate pr-2">{{ editingFinishedMatch.player1.name }}</span>
                <span class="text-gray-300">vs</span>
                <span class="font-bold text-gray-700 w-1/2 text-left truncate pl-2">{{ editingFinishedMatch.player2.name }}</span>
            </div>

            <div class="flex items-center justify-center gap-4 mb-6">
                <input v-model="editScore1" type="number" min="0" class="w-20 text-center border-2 border-blue-100 rounded-lg text-3xl font-bold py-2 focus:border-blue-500 focus:ring-0 text-gray-800" />
                <span class="font-bold text-gray-300 text-2xl">:</span>
                <input v-model="editScore2" type="number" min="0" class="w-20 text-center border-2 border-blue-100 rounded-lg text-3xl font-bold py-2 focus:border-blue-500 focus:ring-0 text-gray-800" />
            </div>

            <div class="space-y-3">
                <button @click="saveFinishedMatch" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded-lg shadow transition-colors">
                    Uložit změnu
                </button>
                <button @click="editingFinishedMatch = null" class="w-full bg-gray-100 hover:bg-gray-200 text-gray-600 font-bold py-2 rounded-lg transition-colors">
                    Zrušit
                </button>
            </div>

        </div>
    </div>
    <button 
        @click="downloadExport" 
        class="flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-bold shadow-sm transition-colors text-sm">
        <span>📊</span> Exportovat data
    </button>
</template>

<style scoped>
@keyframes fade-in { from { opacity: 0; } to { opacity: 1; } }
.animate-fade-in { animation: fade-in 0.2s ease-out forwards; }
</style>