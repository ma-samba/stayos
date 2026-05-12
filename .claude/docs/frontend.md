# Frontend Vue.js 3 — Référence

## Structure src/

```
frontend/src/
├── main.ts                    # Bootstrap Vue + plugins
├── App.vue
├── router/
│   └── index.ts               # Vue Router (guards JWT + feature flags)
├── stores/                    # Pinia
│   ├── auth.store.ts          # StaffUser + JWT
│   ├── tenant.store.ts        # Plan, features, limites
│   ├── rooms.store.ts
│   ├── reservations.store.ts
│   └── housekeeping.store.ts
├── modules/                   # Découpage par domaine
│   ├── platform/              # Pages SaaS (onboarding, settings, superadmin)
│   ├── dashboard/
│   ├── rooms/
│   ├── reservations/
│   ├── guests/
│   ├── billing/
│   ├── housekeeping/
│   └── reports/
├── shared/
│   ├── components/
│   │   ├── ui/                # BaseButton, BaseModal, BaseTable...
│   │   └── layout/            # AppSidebar, AppHeader, AppLayout
│   ├── composables/
│   │   ├── useApi.ts
│   │   ├── useFeatureFlag.ts  # Vérifie le plan actif
│   │   ├── useTenant.ts
│   │   ├── usePagination.ts
│   │   ├── useNotification.ts
│   │   └── useOffline.ts      # PWA offline
│   ├── directives/
│   │   └── v-feature.ts       # <div v-feature="'channel_manager'">
│   └── utils/
│       ├── currency.ts        # Formatage XOF
│       └── date.ts
├── services/                  # Axios — appels API
│   ├── api.service.ts         # Instance Axios + interceptors
│   ├── reservation.service.ts
│   ├── room.service.ts
│   ├── guest.service.ts
│   ├── invoice.service.ts
│   ├── housekeeping.service.ts
│   └── websocket.service.ts   # Mercure SSE
└── types/
    ├── api.ts                 # ApiResponse, PaginatedResponse
    └── entities.ts            # Miroir des entités Symfony
```

## Instance Axios (services/api.service.ts)

```typescript
const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '/api',
  headers: { 'Content-Type': 'application/json' },
})

// Injecte le JWT automatiquement
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

// Redirige vers login si 401
api.interceptors.response.use(
  (res) => res,
  (err) => {
    if (err.response?.status === 401) {
      useAuthStore().logout()
      router.push('/login')
    }
    return Promise.reject(err)
  }
)
```

## Auth Store (Pinia)

```typescript
export const useAuthStore = defineStore('auth', {
  state: () => ({
    token: localStorage.getItem('token') || null,
    user: null as StaffUser | null,
  }),
  getters: {
    isAuthenticated: (state) => !!state.token,
    isManager: (state) => ['MANAGER'].includes(state.user?.role ?? ''),
    isReceptionist: (state) => ['MANAGER', 'RECEPTIONIST'].includes(state.user?.role ?? ''),
  },
  actions: {
    async login(email: string, password: string) { ... },
    logout() {
      this.token = null
      this.user = null
      localStorage.removeItem('token')
      router.push('/login')
    },
    async fetchMe() { ... },
  },
})
```

## Tenant Store (Pinia)

```typescript
export const useTenantStore = defineStore('tenant', {
  state: () => ({
    plan: null as string | null,
    features: [] as string[],
    hotel: null as HotelProfile | null,
  }),
  getters: {
    hasFeature: (state) => (feature: string) => state.features.includes(feature),
    isStarter:    (state) => state.plan === 'STARTER',
    isPro:        (state) => state.plan === 'PRO',
    isEnterprise: (state) => state.plan === 'ENTERPRISE',
  },
})
```

## Directive v-feature

```typescript
// Masque le contenu et affiche un CTA upgrade si la feature n'est pas dans le plan
app.directive('feature', {
  mounted(el, binding) {
    const tenant = useTenantStore()
    if (!tenant.hasFeature(binding.value)) {
      const cta = document.createElement('div')
      cta.innerHTML = `
        <div style="...">
          <span>Disponible en Pro</span>
          <button onclick="router.push('/settings/subscription')">Upgrader</button>
        </div>`
      el.replaceWith(cta)
    }
  }
})

// Usage :
// <div v-feature="'channel_manager'">...</div>
// <button v-feature="'advanced_reports'">Exporter</button>
```

## Service exemple (reservation.service.ts)

```typescript
export const reservationService = {
  getAll: (params?: ReservationFilters) =>
    api.get<ApiResponse<Reservation[]>>('/reservations', { params }),

  getOne: (id: number) =>
    api.get<ApiResponse<Reservation>>(`/reservations/${id}`),

  create: (data: CreateReservationDTO) =>
    api.post<ApiResponse<Reservation>>('/reservations', data),

  update: (id: number, data: Partial<CreateReservationDTO>) =>
    api.put<ApiResponse<Reservation>>(`/reservations/${id}`, data),

  checkIn: (id: number) =>
    api.post<ApiResponse<Reservation>>(`/reservations/${id}/checkin`),

  checkOut: (id: number) =>
    api.post<ApiResponse<Reservation>>(`/reservations/${id}/checkout`),

  cancel: (id: number, reason: string) =>
    api.post(`/reservations/${id}/cancel`, { reason }),

  getAvailableRooms: (params: AvailabilityParams) =>
    api.get<ApiResponse<Room[]>>('/rooms/available', { params }),
}
```

## Types TypeScript (src/types/entities.ts)

```typescript
// Miroir des entités Symfony
interface Reservation {
  id: number
  confirmationNumber: string   // RES-2026-04821
  status: 'confirmed' | 'pending' | 'checked_in' | 'checked_out' | 'cancelled' | 'no_show'
  checkIn: string              // ISO 8601 date
  checkOut: string
  adults: number
  children: number
  totalXof: string
  balanceXof: string
  source: 'direct' | 'booking_com' | 'airbnb' | 'expedia' | 'walk_in'
  nights: number
  guest?: Guest
  room?: Room
  invoice?: Invoice
}

interface Room {
  id: number
  number: string
  status: 'available' | 'occupied' | 'cleaning' | 'maintenance' | 'out_of_order'
  type: RoomType
  floor?: Floor
}

interface Guest {
  id: number
  firstName: string
  lastName: string
  fullName: string             // computed
  email?: string
  phone?: string
  nationality?: string
  documentType?: string
  documentNumber?: string
  totalStays: number
}

// Types API
interface ApiResponse<T> {
  data: T
  status: number
  message: string
}

interface PaginatedResponse<T> {
  data: T[]
  meta: { total: number; page: number; limit: number; pages: number }
}
```

## Design System — Tokens CSS

Référence : `src/shared/styles/tokens.css`

```css
:root {
  --pms-sand: #F5F0E8;
  --pms-ink: #1A1714;
  --pms-gold: #C4922A;
  --pms-teal: #1D6E6E;
  --pms-green: #2E7D4F;
  --pms-red: #B83232;
  --pms-blue: #2B5BA8;
  --font: 'DM Sans', sans-serif;
  --mono: 'DM Mono', monospace;
}
```

Voir le design system complet dans `pms-design-system.html`.

## Conventions Vue.js

- **Composants** : PascalCase (`ReservationCard.vue`, `GuestSearch.vue`)
- **Vues** : PascalCase + suffixe View (`ReservationDetailView.vue`)
- **Composables** : camelCase + préfixe use (`useReservation.ts`)
- **Stores** : camelCase + suffixe Store (`useReservationStore`)
- **Props** : camelCase, toujours typées TypeScript
- `<script setup lang="ts">` — toujours
- Pas de Options API, uniquement Composition API

## Router guards

```typescript
router.beforeEach((to, from, next) => {
  const auth = useAuthStore()
  const tenant = useTenantStore()

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    next('/login')
  } else if (to.meta.requiresManager && !auth.isManager) {
    next('/403')
  } else if (to.meta.requiresFeature && !tenant.hasFeature(to.meta.requiresFeature as string)) {
    next('/upgrade')
  } else {
    next()
  }
})
```

## Mercure SSE (websocket.service.ts)

```typescript
export function subscribeMercure(tenantId: string, onMessage: (data: any) => void) {
  const url = new URL(import.meta.env.VITE_MERCURE_URL)
  url.searchParams.append('topic', `/hotel/${tenantId}/room.status.changed`)
  url.searchParams.append('topic', `/hotel/${tenantId}/reservation.created`)
  url.searchParams.append('topic', `/hotel/${tenantId}/task.assigned`)

  const es = new EventSource(url.toString(), { withCredentials: true })
  es.onmessage = (e) => onMessage(JSON.parse(e.data))
  return es  // fermer avec es.close()
}
```

## Variables d'environnement frontend

```
VITE_API_URL=http://localhost:8080/api
VITE_MERCURE_URL=http://localhost:9090/.well-known/mercure
VITE_APP_DOMAIN=stayos.sn
```
