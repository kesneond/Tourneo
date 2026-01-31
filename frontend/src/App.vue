<script setup>
import { RouterLink, RouterView } from 'vue-router'
</script>

<template>
  <div class="min-h-screen bg-gray-100 font-sans text-gray-900">
    <header class="bg-blue-600 text-white shadow-md">
      <div class="container mx-auto px-4 py-4 flex justify-between items-center">
        <RouterLink to="/" class="text-2xl font-bold hover:text-blue-100 transition">
          🏆 Tourneo
        </RouterLink>

        <nav class="space-x-4">
          <RouterLink to="/" class="hover:text-blue-200 font-medium">
            Seznam turnajů
          </RouterLink>
          <RouterLink to="/create" class="bg-white text-blue-600 px-4 py-2 rounded-md font-bold hover:bg-blue-50 transition">
            + Nový turnaj
          </RouterLink>
        </nav>
      </div>
    </header>

    <main class="container mx-auto px-4 py-6">
      <RouterView />
    </main>
  </div>
</template>