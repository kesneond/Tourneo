<script setup>
import { onMounted } from 'vue';
import { useTournamentStore } from '@/stores/tournament';
import { RouterLink } from 'vue-router';

const store = useTournamentStore();

onMounted(() => {
    store.fetchTournaments();
});

// Pomocná funkce pro barvu odznaku
const getStatusColor = (status) => {
    switch(status) {
        case 'draft': return 'bg-yellow-100 text-yellow-800 border-yellow-200';
        case 'in_progress': return 'bg-blue-100 text-blue-800 border-blue-200';
        case 'finished': return 'bg-green-100 text-green-800 border-green-200';
        default: return 'bg-gray-100 text-gray-800';
    }
};

// Překlad stavů
const translateStatus = (status) => {
    const map = { draft: 'Příprava', in_progress: 'Běží', finished: 'Ukončeno' };
    return map[status] || status;
};
</script>

<template>
    <div>
        <div class="flex flex-col sm:flex-row justify-between items-center mb-8 gap-4">
            <div>
                <h1 class="text-3xl font-extrabold text-gray-900 tracking-tight">Přehled turnajů</h1>
                <p class="text-gray-500 mt-1">Spravujte své sportovní události na jednom místě.</p>
            </div>
            <RouterLink to="/create" class="inline-flex items-center px-5 py-2.5 border border-transparent text-sm font-medium rounded-lg shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors">
                <span class="mr-2 text-lg">+</span> Nový turnaj
            </RouterLink>
        </div>

        <div v-if="store.loading" class="text-center py-12">
            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-indigo-600 mx-auto mb-4"></div>
            <p class="text-gray-500">Načítám data...</p>
        </div>

        <div v-else-if="store.tournaments.length === 0" class="text-center py-16 bg-white rounded-2xl border border-dashed border-gray-300">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
            </svg>
            <h3 class="mt-2 text-sm font-medium text-gray-900">Žádné turnaje</h3>
            <p class="mt-1 text-sm text-gray-500">Začněte vytvořením nového turnaje.</p>
        </div>

        <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <div v-for="tournament in store.tournaments" :key="tournament.id" 
                class="group bg-white rounded-xl shadow-sm hover:shadow-md border border-gray-200 transition-all duration-200 flex flex-col overflow-hidden">
                
                <div class="p-6 flex-1">
                    <div class="flex justify-between items-start mb-4">
                        <span :class="getStatusColor(tournament.status)" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border">
                            {{ translateStatus(tournament.status) }}
                        </span>
                        <span class="text-xs text-gray-400 font-mono">{{ tournament.start_date }}</span>
                    </div>
                    
                    <h3 class="text-xl font-bold text-gray-900 mb-2 group-hover:text-indigo-600 transition-colors">
                        {{ tournament.name }}
                    </h3>
                    <p class="text-gray-600 text-sm line-clamp-2 mb-4">
                        {{ tournament.description || 'Bez popisu' }}
                    </p>
                    
                    <div class="flex items-center text-sm text-gray-500">
                        <svg class="mr-1.5 h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
                        </svg>
                        Formát: {{ tournament.format === 'round_robin' ? 'Každý s každým' : tournament.format }}
                    </div>
                </div>

                <div class="bg-gray-50 px-6 py-4 border-t border-gray-100">
                    <RouterLink :to="{ name: 'tournament-detail', params: { id: tournament.id }}" 
                        class="block w-full text-center bg-white border border-gray-300 text-gray-700 font-medium py-2 px-4 rounded-lg hover:bg-gray-50 hover:text-indigo-600 transition-colors shadow-sm">
                        Otevřít turnaj
                    </RouterLink>
                </div>
            </div>
        </div>
    </div>
</template>