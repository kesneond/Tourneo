<script setup>
defineProps({
    standings: Array
});
</script>

<template>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-10">
                        #
                    </th>
                    
                    <th scope="col" class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Jméno
                    </th>
                    
                    <th scope="col" class="px-2 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider w-16">
                        Zápasy
                    </th>
                    
                    <th scope="col" class="px-2 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider w-16 hidden sm:table-cell">
                        Skóre
                    </th>

                    <th scope="col" class="px-3 py-3 text-center text-xs font-bold text-indigo-700 uppercase tracking-wider w-16">
                        Body
                    </th>
                </tr>
            </thead>
            
            <tbody class="bg-white divide-y divide-gray-200">
                <tr v-for="(player, index) in standings" :key="player.id" :class="index < 3 ? 'bg-yellow-50/30' : ''">
                    
                    <td class="px-3 py-3 whitespace-nowrap text-sm text-gray-500">
                        <span v-if="index === 0">🥇</span>
                        <span v-else-if="index === 1">🥈</span>
                        <span v-else-if="index === 2">🥉</span>
                        <span v-else>{{ index + 1 }}.</span>
                    </td>
                    
                    <td class="px-3 py-3 text-sm font-medium text-gray-900 max-w-[100px] sm:max-w-[150px]" :title="player.name">
                        <div class="truncate">
                            {{ player.name }}
                        </div>
                    </td>
                    
                    <td class="px-2 py-3 whitespace-nowrap text-sm text-gray-500 text-center">
                        {{ player.matches_played }}
                    </td>
                    
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-500">
                        <span :class="player.score_diff > 0 ? 'text-green-600' : (player.score_diff < 0 ? 'text-red-600' : 'text-gray-500')">
                            {{ player.score_diff > 0 ? '+' : ''}}{{ player.score_diff }}
                        </span>
                    </td>
                    
                    <td class="px-3 py-3 whitespace-nowrap text-sm font-bold text-indigo-600 text-center">
                        {{ player.points }}
                    </td>
                </tr>
            </tbody>
        </table>
        
        <div v-if="standings.length === 0" class="text-center py-6 text-gray-400 text-sm">
            Zatím žádné výsledky.
        </div>
    </div>
</template>