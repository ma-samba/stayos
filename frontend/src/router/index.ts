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
  {
    path: '/rooms/:id',
    name: 'room-detail',
    component: () => import('@/modules/rooms/views/RoomDetailView.vue'),
    meta: { requiresAuth: true, title: 'Détail chambre' },
  },
  {
    path: '/reservations',
    name: 'reservations',
    component: () => import('@/modules/reservations/views/ReservationsView.vue'),
    meta: { requiresAuth: true, title: 'Réservations' },
  },
  {
    path: '/reservations/:id',
    name: 'reservation-detail',
    component: () => import('@/modules/reservations/views/ReservationDetailView.vue'),
    meta: { requiresAuth: true, title: 'Détail réservation' },
  },
  {
    path: '/guests',
    name: 'guests',
    component: () => import('@/modules/guests/views/GuestsView.vue'),
    meta: { requiresAuth: true, title: 'Clients' },
  },
  {
    path: '/guests/:id',
    name: 'guest-profile',
    component: () => import('@/modules/guests/views/GuestProfileView.vue'),
    meta: { requiresAuth: true, title: 'Profil client' },
  },
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
