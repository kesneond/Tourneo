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
    format: 'round_robin'
});

const isSubmitting = ref(false);

const submitForm = async () => {
    isSubmitting.value = true;
    try {
        await store.createTournament(form.value);
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

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Herní systém</label>
                        <select v-model="form.format" 
                            class="block w-full rounded-lg border-gray-300 shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm p-2.5 border bg-white">
                            <option value="round_robin">Každý s každým (Tabulka)</option>
                            <option value="bracket" disabled>Pavouk (Již brzy)</option>
                        </select>
                    </div>
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