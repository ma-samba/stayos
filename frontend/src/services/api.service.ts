import axios, { type AxiosInstance, type AxiosResponse } from 'axios'
import { useAuthStore } from '@/stores/auth.store'

// ──────────────────────────────────────────────────────────────
//  Instance Axios configurée
// ──────────────────────────────────────────────────────────────

const api: AxiosInstance = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? '/api',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
  timeout: 15_000,
})

// ──────────────────────────────────────────────────────────────
//  Intercepteur requête — injecte le JWT
// ──────────────────────────────────────────────────────────────

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`

    // Envoyer le slug tenant pour le TenantMiddleware (dev local sans subdomain)
    const tenantSlug = localStorage.getItem('tenant_slug')
    if (tenantSlug) {
      config.headers['X-Tenant-Slug'] = tenantSlug
    }
  }
  return config
})

// ──────────────────────────────────────────────────────────────
//  Intercepteur réponse — gestion 401 + refresh silencieux
// ──────────────────────────────────────────────────────────────

let isRefreshing = false
let failedQueue: Array<{
  resolve: (value: AxiosResponse) => void
  reject: (reason?: unknown) => void
  config: typeof api.defaults
}> = []

function processQueue(error: Error | null, token: string | null): void {
  failedQueue.forEach(({ resolve, reject, config }) => {
    if (error) {
      reject(error)
    } else {
      // @ts-expect-error — we're patching Authorization on stored config
      config.headers!.Authorization = `Bearer ${token}`
      resolve(api(config as never))
    }
  })
  failedQueue = []
}

api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const originalRequest = error.config

    // 402 → tenant suspendu. Rediriger vers la page dédiée et laisser
    // l'erreur se propager pour que le code appelant ne traite pas la
    // réponse comme valide.
    if (error.response?.status === 402) {
      try {
        const router = (await import('@/router')).default
        if (router.currentRoute.value.path !== '/account-suspended') {
          await router.push('/account-suspended')
        }
      } catch { /* noop */ }
      return Promise.reject(error)
    }

    if (error.response?.status === 401 && !originalRequest._retry) {
      if (isRefreshing) {
        return new Promise((resolve, reject) => {
          failedQueue.push({ resolve, reject, config: originalRequest })
        })
      }

      originalRequest._retry = true
      isRefreshing = true

      const refreshToken = localStorage.getItem('refresh_token')

      if (!refreshToken) {
        isRefreshing = false
        const authStore = useAuthStore()
        authStore.logout()
        return Promise.reject(error)
      }

      try {
        const { data } = await axios.post('/api/auth/refresh', {
          refresh_token: refreshToken,
        })

        const newToken = data.token
        localStorage.setItem('token', newToken)
        api.defaults.headers.common['Authorization'] = `Bearer ${newToken}`

        processQueue(null, newToken)
        return api(originalRequest)
      } catch (refreshError) {
        processQueue(refreshError as Error, null)
        const authStore = useAuthStore()
        authStore.logout()
        return Promise.reject(refreshError)
      } finally {
        isRefreshing = false
      }
    }

    return Promise.reject(error)
  },
)

export default api
