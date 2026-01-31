<script setup>
import { ref, onMounted, computed } from 'vue';
import { useRoute } from 'vue-router';
import { useTournamentStore } from '@/stores/tournament';
import api from '@/axios';
import StandingsTable from '@/components/StandingsTable.vue';
import MatchList from '@/components/MatchList.vue';

const route = useRoute();
const store = useTournamentStore();
const tournament = computed(() => store.currentTournament);
const standings = ref([]);
const newPlayerName = ref('');

const loadData = async () => {
    await store.fetchTournamentDetail(route.params.id);
    if (tournament.value && tournament.value.status !== 'draft') {
        const { data } = await api.get(`/tournaments/${route.params.id}/standings`);
        standings.value = data;
    }
};

const handleAddPlayer = async () => {
    if (!newPlayerName.value.trim()) return;
    await store.addPlayer(route.params.id, newPlayerName.value);
    newPlayerName.value = '';
};

const handleStartTournament = async () => {
    if (!confirm('Opravdu chcete začít turnaj? Hráče už nepůjde přidávat.')) return;
    await store.generateMatches(route.params.id);
    await loadData();
};

onMounted(loadData);
</script>

<template>
    <div v-if="tournament" class="max-w-6xl mx-auto space-y-8">
        <div class="bg-white rounded-xl shadow p-6 border-l-4 border-indigo-600 flex justify-between items-start">
            <div>
                <div class="flex items-center gap-3 mb-2">
                    <h1 class="text-3xl font-bold text-gray-900">{{ tournament.name }}</h1>
                    <span v-if="tournament.status === 'draft'" class="bg-yellow-100 text-yellow-800 text-xs font-bold px-2 py-1 rounded-full uppercase tracking-wide">Příprava</span>
                    <span v-else-if="tournament.status === 'in_progress'" class="bg-green-100 text-green-800 text-xs font-bold px-2 py-1 rounded-full uppercase tracking-wide">Probíhá</span>
                    <span v-else class="bg-gray-100 text-gray-800 text-xs font-bold px-2 py-1 rounded-full uppercase tracking-wide">Ukončeno</span>
                </div>
                <p class="text-gray-600">{{ tournament.description || 'Bez popisu' }}</p>
            </div>
            <div class="text-right text-sm text-gray-500">
                <div>Formát: {{ tournament.format }}</div>
                <div>Datum: {{ tournament.start_date }}</div>
            </div>
        </div>

        <div v-if="tournament.status === 'draft'" class="bg-white rounded-xl shadow-lg border border-gray-100 overflow-hidden">
            <div class="p-6 bg-gray-50 border-b border-gray-200">
                <h2 class="text-xl font-bold text-gray-800">Registrace hráčů</h2>
                <p class="text-sm text-gray-500">Přidejte hráče do turnaje. Pro zahájení jsou potřeba alespoň 2.</p>
            </div>
            
            <div class="p-6">
                <div v-if="tournament.players.length > 0" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3 mb-6">
                    <div v-for="player in tournament.players" :key="player.id" class="flex items-center p-3 bg-indigo-50 text-indigo-900 rounded-lg border border-indigo-100">
                        <div class="h-8 w-8 rounded-full bg-indigo-200 flex items-center justify-center font-bold mr-3 text-indigo-700">
                            {{ player.name.charAt(0) }}
                        </div>
                        <span class="font-medium">{{ player.name }}</span>
                    </div>
                </div>
                <div v-else class="text-center py-8 text-gray-400 italic mb-6">
                    Zatím žádní přihlášení hráči.
                </div>

                <div class="flex gap-4 max-w-md mb-8">
                    <input v-model="newPlayerName" @keyup.enter="handleAddPlayer" 
                        placeholder="Jméno hráče" 
                        class="flex-1 rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 border p-2" />
                    <button @click="handleAddPlayer" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg font-medium transition">
                        Přidat
                    </button>
                </div>

                <div class="border-t pt-6">
                    <button @click="handleStartTournament" :disabled="tournament.players.length < 2"
                        class="w-full sm:w-auto bg-green-600 hover:bg-green-700 disabled:bg-gray-300 disabled:cursor-not-allowed text-white font-bold py-3 px-8 rounded-lg shadow transition transform hover:scale-105">
                        🎲 Rozlosovat a začít turnaj
                    </button>
                </div>
            </div>
        </div>

        <div v-else class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div class="lg:col-span-1">
                <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <span>📊</span> Žebříček
                </h2>
                <StandingsTable :standings="standings" />
            </div>

            <div class="lg:col-span-2">
                <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <span>⚔️</span> Zápasy
                </h2>
                <MatchList :matches="tournament.games" @match-updated="loadData" />
            </div>
        </div>
    </div>
</template>