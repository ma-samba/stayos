import axios from 'axios'
import { resolveTenantSlug } from './tenant'

interface LoginResponse {
  token: string
  refresh_token: string
}

const API_URL = import.meta.env.VITE_API_URL ?? '/api'

export const authService = {
  async login(email: string, password: string): Promise<LoginResponse> {
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
    }
    // En dev local : le slug vient du hostname (subdomain.localhost) avec
    // fallback sur VITE_DEFAULT_TENANT_SLUG. En prod le subdomain suffit
    // (header inoffensif mais redondant). Si null : on n'envoie rien et le
    // backend rejettera proprement plutôt que de chercher dans le mauvais
    // schéma.
    const slug = resolveTenantSlug()
    if (slug) {
      headers['X-Tenant-Slug'] = slug
    }
    const { data } = await axios.post<LoginResponse>(
      `${API_URL}/auth/login`,
      { email, password },
      { headers },
    )
    return data
  },
}
