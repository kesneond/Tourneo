<script setup>
defineProps({
    standings: Array
});
</script>

<template>
    <div class="overflow-x-auto rounded-lg border border-gray-200 shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 bg-white">
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Pořadí</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Hráč</th>
                    <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Zápasy</th>
                    <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Skóre</th>
                    <th scope="col" class="px-6 py-3 text-center text-xs font-bold text-gray-900 uppercase tracking-wider">Body</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <tr v-for="(row, index) in standings" :key="row.id" :class="index < 3 ? 'bg-indigo-50/30' : ''">
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 font-mono">
                        {{ index + 1 }}.
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                        {{ row.name }}
                        <span v-if="index === 0" class="ml-2 text-yellow-500">👑</span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-500">
                        {{ row.matches_played }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-500">
                        <span :class="row.score_diff > 0 ? 'text-green-600' : (row.score_diff < 0 ? 'text-red-600' : 'text-gray-500')">
                            {{ row.score_diff > 0 ? '+' : ''}}{{ row.score_diff }}
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-center">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-bold bg-indigo-100 text-indigo-800">
                            {{ row.points }}
                        </span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>