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
    allGames.value.filter(g => g.status === 'finished').sort((a,b) => b.updated_at.localeCompare(a.updated_at))
);

// Zápasy, které čekají ve frontě
const queuedGames = computed(() => 
    allGames.value.filter(g => g.status === 'scheduled')
);

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
    await store.generateMatches(route.params.id);
    await loadData();
};

const handleAddPlayer = async () => { if (!newPlayerName.value.trim()) return; await store.addPlayer(route.params.id, newPlayerName.value); newPlayerName.value = ''; };
const updateVenues = async (e) => { const count = parseInt(e.target.value); if (count > 0) await store.updateTournament(tournament.value.id, { venues_count: count }); };

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
            <h2 class="text-xl font-bold mb-4">Registrace</h2>
            <div class="flex gap-4 mb-4"><input v-model="newPlayerName" @keyup.enter="handleAddPlayer" class="border p-2 rounded flex-1" placeholder="Jméno" /><button @click="handleAddPlayer" class="bg-indigo-600 text-white px-4 rounded">Přidat</button></div>
            <div class="flex flex-wrap gap-2 mb-4"><span v-for="p in tournament.players" :key="p.id" class="bg-gray-100 px-2 py-1 rounded flex gap-2">{{ p.name }} <button @click="store.removePlayer(p.id)" class="text-red-500">x</button></span></div>
            <button @click="handleStartTournament" :disabled="tournament.players.length < 2" class="bg-green-600 text-white font-bold py-3 px-8 rounded">Start</button>
        </div>

        <div v-else class="grid grid-cols-1 xl:grid-cols-3 gap-6">
            
            <div class="lg:col-span-1 space-y-6">
                <div class="bg-white rounded-xl shadow overflow-hidden"><StandingsTable :standings="standings" /></div>
                <div class="bg-white rounded-xl shadow overflow-hidden p-4">
                    <h3 class="font-bold text-gray-500 mb-2">Odehráno</h3>
                    <div class="max-h-96 overflow-y-auto space-y-2">
                        
                        <div v-for="m in finishedGames" :key="m.id" 
                            @click="openEditFinishedMatch(m)"
                            class="text-sm border-b pb-2 flex justify-between items-center group hover:bg-indigo-50 hover:text-indigo-900 p-2 rounded transition-colors cursor-pointer"
                            title="Klikni pro úpravu výsledku">
                            
                            <span class="w-1/3 truncate text-right" :class="m.score1 > m.score2 ? 'font-bold text-green-700' : ''">
                                {{ m.player1.name }}
                            </span>
                            
                            <span class="font-bold px-2 py-0.5 bg-gray-100 group-hover:bg-white rounded text-gray-800 transition-colors">
                                {{ m.score1 }}:{{ m.score2 }}
                            </span>
                            
                            <span class="w-1/3 truncate text-left" :class="m.score2 > m.score1 ? 'font-bold text-green-700' : ''">
                                {{ m.player2.name }}
                            </span>

                        </div>
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

                <div>
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
                            <div class="text-center font-bold text-gray-800">
                                {{ match.player1.name }} 
                                <span class="text-gray-400 text-xs font-normal">vs</span> 
                                {{ match.player2.name }}
                            </div>
                        </div>
                        
                        <div v-if="queuedGames.length === 0" class="text-gray-400 italic pl-2 py-4">
                            Fronta je prázdná.
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
</template>

<style scoped>
@keyframes fade-in { from { opacity: 0; } to { opacity: 1; } }
.animate-fade-in { animation: fade-in 0.2s ease-out forwards; }
</style>