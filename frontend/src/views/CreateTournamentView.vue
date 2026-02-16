<script setup>
import { ref } from 'vue';
import { useTournamentStore } from '@/stores/tournament';
import { useRouter } from 'vue-router';

const store = useTournamentStore();
const router = useRouter();

const form = ref({
    name: '',
    description: '',
    start_date: new Date().toISOString().split('T')[0],
    format: 'round_robin',
    // --- NOVÉ POLOŽKY PRO BODOVÁNÍ ---
    points_win: 3,
    points_draw: 1,
    points_loss: 0
});

const isSubmitting = ref(false);

const submitForm = async () => {
    isSubmitting.value = true;
    try {
        const data = { ...form.value };
        await store.createTournament(data);
        router.push('/');
    } catch (error) {
        alert('Chyba: ' + error.message);
    } finally {
        isSubmitting.value = false;
    }
};
</script>

<template>
    <div class="max-w-2xl mx-auto">
        <div class="bg-white rounded-xl shadow-lg border border-gray-100 overflow-hidden">
            <div class="bg-indigo-600 px-6 py-4">
                <h1 class="text-xl font-bold text-white">Vytvořit nový turnaj</h1>
                <p class="text-indigo-100 text-sm">Vyplňte základní údaje o sportovní události.</p>
            </div>

            <form @submit.prevent="submitForm" class="p-8 space-y-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Název turnaje</label>
                    <input v-model="form.name" required type="text" 
                        class="block w-full rounded-lg border-gray-300 shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm p-2.5 border" 
                        placeholder="Např. Firemní Ping-Pong Cup 2024" />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Popis (volitelné)</label>
                    <textarea v-model="form.description" rows="3" 
                        class="block w-full rounded-lg border-gray-300 shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm p-2.5 border"
                        placeholder="O co se hraje, kde se hraje..."></textarea>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Datum zahájení</label>
                        <input v-model="form.start_date" type="date" required
                            class="block w-full rounded-lg border-gray-300 shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm p-2.5 border" />
                    </div>

                    <div class="flex items-end gap-2">
                        <div class="flex-1">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Herní systém</label>
                            <select v-model="form.format" 
                                class="block w-full rounded-lg border-gray-300 shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm p-2.5 border bg-white">
                                <option value="round_robin">Každý s každým (Tabulka)</option>
                                <option value="groups">Skupiny</option>
                                <option value="bracket" disabled>Pavouk (Již brzy)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="border-t border-gray-100 pt-6">
                    <label class="block text-sm font-medium text-gray-700 mb-3">Nastavení bodování</label>
                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-green-700 uppercase mb-1">Výhra</label>
                            <div class="relative">
                                <input v-model="form.points_win" type="number" min="0" required
                                    class="block w-full rounded-lg border-green-200 bg-green-50 text-green-800 font-bold text-center shadow-sm focus:ring-green-500 focus:border-green-500 sm:text-sm p-2.5 border" />
                                <span class="absolute right-8 top-3 text-xs text-green-400 font-bold">b.</span>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-orange-600 uppercase mb-1">Remíza</label>
                            <div class="relative">
                                <input v-model="form.points_draw" type="number" min="0" required
                                    class="block w-full rounded-lg border-orange-200 bg-orange-50 text-orange-800 font-bold text-center shadow-sm focus:ring-orange-500 focus:border-orange-500 sm:text-sm p-2.5 border" />
                                <span class="absolute right-8 top-3 text-xs text-orange-400 font-bold">b.</span>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-red-600 uppercase mb-1">Prohra</label>
                            <div class="relative">
                                <input v-model="form.points_loss" type="number" min="0" required
                                    class="block w-full rounded-lg border-red-200 bg-red-50 text-red-800 font-bold text-center shadow-sm focus:ring-red-500 focus:border-red-500 sm:text-sm p-2.5 border" />
                                <span class="absolute right-8 top-3 text-xs text-red-400 font-bold">b.</span>
                            </div>
                        </div>
                    </div>
                    <p class="text-xs text-gray-400 mt-2">Zadejte počet bodů, které hráč získá za daný výsledek.</p>
                </div>

                <div class="pt-6 border-t border-gray-100 flex items-center justify-end gap-3">
                    <RouterLink to="/" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors">
                        Zrušit
                    </RouterLink>
                    <button type="submit" :disabled="isSubmitting"
                        class="inline-flex justify-center px-6 py-2 text-sm font-medium text-white bg-indigo-600 border border-transparent rounded-lg hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-70 disabled:cursor-not-allowed transition-all shadow-sm">
                        
                        <svg v-if="isSubmitting" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        {{ isSubmitting ? 'Vytvářím...' : 'Vytvořit turnaj' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>