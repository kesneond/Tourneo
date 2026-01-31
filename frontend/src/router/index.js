import { createRouter, createWebHistory } from 'vue-router'
import HomeView from '../views/HomeView.vue'
import CreateTournamentView from '../views/CreateTournamentView.vue'
import TournamentDetailView from '../views/TournamentDetailView.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'home',
      component: HomeView
    },
    {
      path: '/create',
      name: 'create',
      component: CreateTournamentView
    },
    {
      // Dynamická cesta s ID (např. /tournament/5)
      path: '/tournament/:id',
      name: 'tournament-detail',
      component: TournamentDetailView
    }
  ]
})

export default router