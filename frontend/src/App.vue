<script setup>
import { onMounted, ref } from 'vue';
import api from '@/axios'; // Importujeme náš nakonfigurovaný axios
import { RouterLink, RouterView } from 'vue-router'

const status = ref('Zkouším se připojit...');

onMounted(async () => {
  try {
    const response = await api.get('/test-connection');
    status.value = '✅ ' + response.data.message;
    console.log(response.data);
  } catch (error) {
    status.value = '❌ Chyba připojení: ' + error.message;
    console.error(error);
  }
});
</script>

<template>
  <h1>You did it!</h1>
  <p>
    <div class="bg-gray-200 p-2 text-center text-sm font-mono">
      Status API: {{ status }}
    </div>
  </p>
</template>

<style scoped></style>
