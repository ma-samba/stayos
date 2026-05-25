import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '@/stores/auth.store'

const routes: RouteRecordRaw[] = [
  {
    path: '/',
    name: 'root',
    redirect: () => {
      const auth = useAuthStore()
      return auth.firstAccessiblePath()
    },
  },
  {
    path: '/login',
    name: 'login',
    component: () => import('@/modules/auth/views/LoginView.vue'),
    meta: { requiresAuth: false, title: 'Connexion' },
  },
  {
    path: '/rooms',
    name: 'rooms',
    component: () => import('@/modules/rooms/views/RoomsView.vue'),
    meta: { requiresAuth: true, title: 'Chambres', module: 'rooms' },
  },
  {
    path: '/rooms/:id',
    name: 'room-detail',
    component: () => import('@/modules/rooms/views/RoomDetailView.vue'),
    meta: { requiresAuth: true, title: 'Détail chambre', module: 'rooms' },
  },
  {
    path: '/reservations',
    name: 'reservations',
    component: () => import('@/modules/reservations/views/ReservationsView.vue'),
    meta: { requiresAuth: true, title: 'Réservations', module: 'reservations' },
  },
  {
    path: '/reservations/:id',
    name: 'reservation-detail',
    component: () => import('@/modules/reservations/views/ReservationDetailView.vue'),
    meta: { requiresAuth: true, title: 'Détail réservation', module: 'reservations' },
  },
  {
    path: '/invoices',
    name: 'invoices',
    component: () => import('@/modules/billing/views/InvoicesView.vue'),
    meta: { requiresAuth: true, title: 'Factures', module: 'billing' },
  },
  {
    path: '/invoices/:id',
    name: 'invoice-detail',
    component: () => import('@/modules/billing/views/InvoiceDetailView.vue'),
    meta: { requiresAuth: true, title: 'Détail facture', module: 'billing' },
  },
  {
    path: '/housekeeping',
    name: 'housekeeping',
    component: () => import('@/modules/housekeeping/views/HousekeepingView.vue'),
    meta: { requiresAuth: true, title: 'Ménage', module: 'housekeeping' },
  },
  {
    path: '/rates',
    name: 'rates',
    component: () => import('@/modules/rates/views/RatesView.vue'),
    meta: { requiresAuth: true, title: 'Tarifs', module: 'rates' },
  },
  {
    path: '/guests',
    name: 'guests',
    component: () => import('@/modules/guests/views/GuestsView.vue'),
    meta: { requiresAuth: true, title: 'Clients', module: 'guests' },
  },
  {
    path: '/guests/:id',
    name: 'guest-profile',
    component: () => import('@/modules/guests/views/GuestProfileView.vue'),
    meta: { requiresAuth: true, title: 'Profil client', module: 'guests' },
  },
]

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
})

// ── Guard d'authentification + RBAC module ───────────────────

router.beforeEach((to) => {
  if (to.meta.requiresAuth) {
    const token = localStorage.getItem('token')
    if (!token) {
      return { path: '/login', query: { redirect: to.fullPath } }
    }

    const module = to.meta.module as string | undefined
    if (module) {
      const auth = useAuthStore()
      if (!auth.canAccess(module)) {
        return { path: auth.firstAccessiblePath() }
      }
    }
  }
})

export default router
