<script setup lang="ts">
import { RouterView, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth.store'
import { useNotificationsStore } from '@/stores/notifications.store'
import { computed, ref, watch, onMounted } from 'vue'
import NotificationCenter from '@/components/NotificationCenter.vue'
import ToastContainer from '@/components/ToastContainer.vue'

const route  = useRoute()
const router = useRouter()
const auth   = useAuthStore()
const notifications = useNotificationsStore()

const isAuthPage = computed(() => route.path === '/login')
const isSidebarCollapsed = ref(false)

// ── Cycle de vie Mercure (notifications + toasts) ────────────
onMounted(() => {
  if (auth.isAuthenticated) notifications.connect()
})

watch(
  () => auth.isAuthenticated,
  (isAuth, was) => {
    if (isAuth && !was) notifications.connect()
    if (!isAuth && was) notifications.disconnect()
  },
)

const allNavItems = [
  { path: '/dashboard',     icon: 'ti-layout-dashboard', label: 'Tableau de bord', module: 'dashboard' },
  { path: '/rooms',         icon: 'ti-bed',              label: 'Chambres',      module: 'rooms' },
  { path: '/guests',        icon: 'ti-users',            label: 'Clients',       module: 'guests' },
  { path: '/reservations',  icon: 'ti-calendar',         label: 'Réservations',  module: 'reservations' },
  { path: '/invoices',      icon: 'ti-file-invoice',     label: 'Facturation',   module: 'billing' },
  { path: '/rates',         icon: 'ti-percentage',       label: 'Tarifs',        module: 'rates' },
  { path: '/housekeeping',  icon: 'ti-spray',            label: 'Ménage',        module: 'housekeeping' },
]

const navItems = computed(() =>
  allNavItems.filter(item => auth.canAccess(item.module))
)

function navigate(path: string): void {
  router.push(path)
}

function logout(): void {
  auth.logout()
  router.push('/login')
}
</script>

<template>
  <!-- Sans sidebar sur la page login -->
  <RouterView v-if="isAuthPage" />

  <!-- Layout avec sidebar -->
  <div v-else style="display:flex; min-height:100vh;">

    <!-- ── Sidebar ── -->
    <nav :class="['sidebar', { 'sidebar-collapsed': isSidebarCollapsed }]">

      <!-- Logo -->
      <div class="sidebar-logo">
        <i class="ti ti-building" style="font-size:20px; color:var(--pms-gold);"></i>
        <span>StayOS</span>
      </div>

      <!-- Nom hôtel -->
      <div v-if="auth.hotelName" class="hotel-info">
        <div class="hotel-info-label">Hôtel</div>
        <div class="hotel-info-name">{{ auth.hotelName }}</div>
      </div>

      <!-- Navigation -->
      <div style="flex:1;">
        <div style="padding:0 0.75rem;">
          <button
            v-for="item in navItems"
            :key="item.path"
            :class="['nav-item', route.path === item.path ? 'active' : '']"
            @click="navigate(item.path)"
          >
            <i :class="['ti', item.icon]" aria-hidden="true"></i>
            <span>{{ item.label }}</span>
          </button>
        </div>
      </div>

      <!-- Footer sidebar -->
      <div class="sidebar-footer">
        <div class="sidebar-bell-row">
          <NotificationCenter />
        </div>
        <button class="nav-item" @click="logout()">
          <i class="ti ti-logout" aria-hidden="true"></i>
          <span>Déconnexion</span>
        </button>
        <!-- Toggle -->
        <button class="sidebar-toggle" @click="isSidebarCollapsed = !isSidebarCollapsed">
          <i :class="isSidebarCollapsed ? 'ti ti-chevron-right' : 'ti ti-chevron-left'"></i>
          <span v-if="!isSidebarCollapsed" style="font-size:12px;">Réduire</span>
        </button>
      </div>
    </nav>

    <!-- ── Contenu principal ── -->
    <main
      class="main-content"
      :style="{ marginLeft: isSidebarCollapsed ? '64px' : '200px' }"
    >
      <RouterView />
    </main>

    <ToastContainer />
  </div>
</template>

<style scoped>
/* ── Sidebar ── */
.sidebar {
  position: fixed;
  top: 0; left: 0; bottom: 0;
  width: 200px;
  background: var(--pms-ink);
  color: #fff;
  display: flex;
  flex-direction: column;
  padding: 1.25rem 0;
  z-index: 50;
  transition: width 0.22s ease;
  overflow: hidden;
}

.sidebar.sidebar-collapsed {
  width: 64px;
}

/* ── Logo ── */
.sidebar-logo {
  padding: 0 1.25rem;
  margin-bottom: 2rem;
  display: flex;
  align-items: center;
  gap: 8px;
}
.sidebar-logo span {
  font-size: 16px;
  font-weight: 500;
  letter-spacing: -0.02em;
  white-space: nowrap;
  transition: opacity 0.15s ease;
}

/* ── Hotel info ── */
.hotel-info {
  padding: 0 1.25rem;
  margin-bottom: 1.5rem;
  transition: opacity 0.15s ease;
}
.hotel-info-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: rgba(255,255,255,0.4);
  margin-bottom: 4px;
  white-space: nowrap;
}
.hotel-info-name {
  font-size: 13px;
  color: rgba(255,255,255,0.8);
  white-space: nowrap;
}

/* ── Nav items ── */
.nav-item {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 9px 12px;
  border: none;
  background: transparent;
  color: rgba(255,255,255,0.6);
  font-family: var(--font);
  font-size: 13px;
  font-weight: 400;
  border-radius: var(--radius-md);
  cursor: pointer;
  transition: all 0.15s;
  white-space: nowrap;
}
.nav-item:hover {
  background: rgba(255,255,255,0.08);
  color: rgba(255,255,255,0.9);
}
.nav-item.active {
  background: rgba(255,255,255,0.12);
  color: #fff;
  font-weight: 500;
}
.nav-item i {
  font-size: 18px;
  flex-shrink: 0;
}
.nav-item span {
  transition: opacity 0.15s ease;
}

/* ── Footer ── */
.sidebar-footer {
  padding: 0 0.75rem;
  border-top: 0.5px solid rgba(255,255,255,0.1);
  padding-top: 1rem;
}

/* ── Bell row dans la sidebar ── */
.sidebar-bell-row {
  display: flex;
  align-items: center;
  justify-content: flex-start;
  padding: 0 4px 8px;
}

/* ── Toggle button ── */
.sidebar-toggle {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  width: 100%;
  padding: 10px 12px;
  margin-top: 8px;
  background: rgba(255,255,255,0.04);
  border: none;
  border-radius: var(--radius-md);
  color: rgba(255,255,255,0.5);
  cursor: pointer;
  transition: all 0.15s ease;
  font-family: var(--font);
  font-size: 13px;
}
.sidebar-toggle:hover {
  background: rgba(255,255,255,0.1);
  color: rgba(255,255,255,0.9);
}
.sidebar-toggle i { font-size: 18px; }

/* ── Collapsed state ── */
.sidebar-collapsed .sidebar-logo span,
.sidebar-collapsed .nav-item span,
.sidebar-collapsed .nav-badge {
  display: none;
}
.sidebar-collapsed .hotel-info {
  display: none;
}

.sidebar-collapsed .nav-item {
  justify-content: center;
  padding: 10px 0;
}

.sidebar-collapsed .sidebar-logo {
  justify-content: center;
  padding: 0;
}

.sidebar-collapsed .sidebar-footer {
  padding: 0;
}

.sidebar-collapsed .sidebar-footer .nav-item {
  justify-content: center;
}

.sidebar-collapsed .sidebar-bell-row {
  justify-content: center;
  padding: 0 0 8px;
}

.sidebar-collapsed .sidebar-toggle {
  justify-content: center;
  padding: 10px 0;
}
.sidebar-collapsed .sidebar-toggle span {
  display: none;
}

/* ── Main content ── */
.main-content {
  flex: 1;
  background: var(--pms-sand);
  overflow-y: auto;
  transition: margin-left 0.22s ease;
}
</style>
