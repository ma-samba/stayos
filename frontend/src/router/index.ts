import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'

const routes: RouteRecordRaw[] = [
  {
    path: '/',
    redirect: '/rooms',
  },
  {
    path: '/rooms',
    name: 'rooms',
    component: () => import('@/modules/rooms/views/RoomsView.vue'),
    meta: { requiresAuth: true, title: 'Chambres' },
  },
  // Autres modules à ajouter au fur et à mesure des sprints
]

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
})

// ── Guard d'authentification ──────────────────────────────────

router.beforeEach((to) => {
  if (to.meta.requiresAuth) {
    const token = localStorage.getItem('token')
    if (!token) {
      return { path: '/login', query: { redirect: to.fullPath } }
    }
  }
})

export default router
