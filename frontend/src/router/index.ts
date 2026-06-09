import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '@/stores/auth.store'
import { useSuperAdminStore } from '@/stores/superadmin.store'

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
    path: '/dashboard',
    name: 'dashboard',
    component: () => import('@/modules/dashboard/views/DashboardView.vue'),
    meta: { requiresAuth: true, title: 'Tableau de bord', module: 'dashboard' },
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
  {
    path: '/subscription',
    name: 'subscription',
    component: () => import('@/modules/subscription/views/SubscriptionView.vue'),
    meta: { requiresAuth: true, title: 'Abonnement', module: 'subscription' },
  },
  {
    path: '/subscription/pricing',
    name: 'pricing',
    component: () => import('@/modules/subscription/views/PricingView.vue'),
    meta: { requiresAuth: true, title: 'Choisir un plan', module: 'subscription' },
  },
  {
    path: '/subscription/invoices',
    name: 'subscription-invoices',
    component: () => import('@/modules/subscription/views/BillingHistoryView.vue'),
    meta: { requiresAuth: true, title: 'Factures SaaS', module: 'subscription' },
  },
  {
    path: '/subscription/payment-return',
    name: 'subscription-payment-return',
    component: () => import('@/modules/subscription/views/PaymentReturnView.vue'),
    meta: { requiresAuth: true, title: 'Paiement', module: 'subscription' },
  },
  {
    path: '/subscription/payment-cancel',
    name: 'subscription-payment-cancel',
    component: () => import('@/modules/subscription/views/PaymentCancelView.vue'),
    meta: { requiresAuth: true, title: 'Paiement annulé', module: 'subscription' },
  },
  {
    path: '/account-suspended',
    name: 'account-suspended',
    component: () => import('@/modules/subscription/views/AccountSuspendedView.vue'),
    meta: { requiresAuth: true, title: 'Compte suspendu', hideSidebar: true },
  },

  // ── Configuration de l'hôtel (manager only) ── Sprint 13ter
  {
    path: '/configuration',
    name: 'configuration',
    component: () => import('@/modules/property/views/HotelConfigurationView.vue'),
    meta: { requiresAuth: true, title: 'Configuration', module: 'configuration' },
  },

  // ── Night Audit (réceptionniste + manager) ── Sprint 13quater
  {
    path: '/night-audit',
    name: 'night-audit',
    component: () => import('@/modules/night-audit/views/NightAuditView.vue'),
    meta: { requiresAuth: true, title: 'Clôture journalière', module: 'night_audit' },
  },
  {
    path: '/night-audit/:id',
    name: 'night-audit-detail',
    component: () => import('@/modules/night-audit/views/NightAuditDetailView.vue'),
    meta: { requiresAuth: true, title: 'Détail clôture', module: 'night_audit' },
  },

  // ── Personnel (manager only) ──
  {
    path: '/staff',
    name: 'staff',
    component: () => import('@/modules/staff/views/StaffListView.vue'),
    meta: { requiresAuth: true, title: 'Personnel', module: 'staff' },
  },
  {
    path: '/staff/:id',
    name: 'staff-detail',
    component: () => import('@/modules/staff/views/StaffDetailView.vue'),
    meta: { requiresAuth: true, title: 'Détail employé', module: 'staff' },
  },

  // ── Acceptation d'invitation employé (public, plein écran) ──
  {
    path: '/invitation/:token',
    name: 'accept-invitation',
    component: () => import('@/modules/staff/views/AcceptInvitationView.vue'),
    meta: { requiresAuth: false, title: 'Activer mon compte', hideSidebar: true },
  },

  // ── SuperAdmin (back-office plateforme) ──
  {
    path: '/superadmin',
    redirect: '/superadmin/metrics',
  },
  {
    path: '/superadmin/login',
    name: 'superadmin-login',
    component: () => import('@/modules/superadmin/views/SuperAdminLoginView.vue'),
    meta: { layout: 'superadmin', requiresAuth: false, title: 'SuperAdmin — Connexion' },
  },
  {
    path: '/superadmin/tenants',
    name: 'superadmin-tenants',
    component: () => import('@/modules/superadmin/views/TenantsListView.vue'),
    meta: { layout: 'superadmin', requiresSuperAdmin: true, title: 'Tenants' },
  },
  {
    path: '/superadmin/tenants/new',
    name: 'superadmin-tenant-new',
    component: () => import('@/modules/superadmin/views/CreateTenantView.vue'),
    meta: { layout: 'superadmin', requiresSuperAdmin: true, title: 'Nouveau tenant' },
  },
  {
    path: '/superadmin/tenants/:slug',
    name: 'superadmin-tenant-detail',
    component: () => import('@/modules/superadmin/views/TenantDetailView.vue'),
    meta: { layout: 'superadmin', requiresSuperAdmin: true, title: 'Détail tenant' },
  },
  {
    path: '/superadmin/metrics',
    name: 'superadmin-metrics',
    component: () => import('@/modules/superadmin/views/PlatformMetricsView.vue'),
    meta: { layout: 'superadmin', requiresSuperAdmin: true, title: 'Métriques plateforme' },
  },
  {
    path: '/superadmin/audit',
    name: 'superadmin-audit',
    component: () => import('@/modules/superadmin/views/SuperAdminAuditView.vue'),
    meta: { layout: 'superadmin', requiresSuperAdmin: true, title: 'Audit SuperAdmin' },
  },
]

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
})

// ── Guard d'authentification + RBAC module ───────────────────

router.beforeEach((to) => {
  // ── SuperAdmin : guard isolé du staff ──
  if (to.meta.requiresSuperAdmin) {
    const superadmin = useSuperAdminStore()
    if (!superadmin.isAuthenticated) {
      return { path: '/superadmin/login', query: { redirect: to.fullPath } }
    }
    return
  }

  // Déjà authentifié SuperAdmin et on essaie d'aller sur la page de login → métriques
  if (to.path === '/superadmin/login') {
    const superadmin = useSuperAdminStore()
    if (superadmin.isAuthenticated) {
      return { path: '/superadmin/metrics' }
    }
    return
  }

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
