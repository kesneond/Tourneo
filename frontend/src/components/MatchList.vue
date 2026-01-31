<script setup>
import { ref } from 'vue';
import api from '@/axios';

const props = defineProps({ matches: Array });
const emit = defineEmits(['match-updated']);

const editingMatchId = ref(null);
const score1 = ref(0);
const score2 = ref(0);
const saving = ref(false);

const startEdit = (match) => {
    editingMatchId.value = match.id;
    score1.value = match.score1 ?? 0;
    score2.value = match.score2 ?? 0;
};

const cancelEdit = () => {
    editingMatchId.value = null;
};

const saveScore = async (matchId) => {
    saving.value = true;
    try {
        await api.put(`/games/${matchId}`, {
            score1: score1.value,
            score2: score2.value
        });
        emit('match-updated'); // Řekneme rodiči, ať obnoví data
        editingMatchId.value = null;
    } catch (e) {
        alert('Chyba při ukládání');
    } finally {
        saving.value = false;
    }
};
</script>

<template>
    <div class="grid grid-cols-1 gap-4">
        <div v-for="match in matches" :key="match.id" 
            class="bg-white border rounded-lg p-4 flex flex-col sm:flex-row items-center justify-between shadow-sm hover:shadow transition-shadow">
            
            <div class="flex items-center justify-between w-full sm:w-auto sm:flex-1 gap-4 text-center sm:text-left">
    
                <span class="w-1/3 sm:text-right transition-colors"
                    :class="{
                        'font-bold text-green-700': match.status === 'finished' && match.score1 > match.score2,
                        'text-gray-500': match.status === 'finished' && match.score1 < match.score2,
                        'font-medium text-gray-900': match.status !== 'finished' || match.score1 === match.score2
                    }">
                    {{ match.player1.name }}
                </span>
                
                <div class="font-bold text-lg w-20 text-center rounded px-2 py-1"
                    :class="match.status === 'finished' ? 'bg-indigo-100 text-indigo-800' : 'bg-gray-100 text-gray-500'">
                    <span v-if="match.status === 'finished'">{{ match.score1 }} : {{ match.score2 }}</span>
                    <span v-else class="text-xs uppercase tracking-wider">VS</span>
                </div>
                
                <span class="w-1/3 transition-colors"
                    :class="{
                        'font-bold text-green-700': match.status === 'finished' && match.score2 > match.score1,
                        'text-gray-500': match.status === 'finished' && match.score2 < match.score1,
                        'font-medium text-gray-900': match.status !== 'finished' || match.score1 === match.score2
                    }">
                    {{ match.player2.name }}
                </span>
            </div>

            <div class="mt-4 sm:mt-0 sm:ml-6 flex items-center">
                
                <div v-if="editingMatchId === match.id" class="flex items-center gap-2 bg-indigo-50 p-2 rounded">
                    <input v-model="score1" type="number" min="0" class="w-12 border rounded text-center p-1" />
                    <span>:</span>
                    <input v-model="score2" type="number" min="0" class="w-12 border rounded text-center p-1" />
                    
                    <button @click="saveScore(match.id)" :disabled="saving" class="text-green-600 hover:text-green-800 font-bold px-2">
                        ✓
                    </button>
                    <button @click="cancelEdit" class="text-red-500 hover:text-red-700 font-bold px-2">
                        ✕
                    </button>
                </div>

                <button v-else @click="startEdit(match)" 
                    class="text-sm text-indigo-600 hover:text-indigo-800 font-medium px-3 py-1 border border-indigo-200 rounded hover:bg-indigo-50 transition">
                    {{ match.status === 'finished' ? 'Upravit' : 'Zadat výsledek' }}
                </button>
            </div>
        </div>
    </div>
</template>