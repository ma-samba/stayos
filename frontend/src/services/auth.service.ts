import axios from 'axios'

interface LoginResponse {
  token: string
  refresh_token: string
}

const API_URL = import.meta.env.VITE_API_URL ?? '/api'
const DEFAULT_TENANT_SLUG = import.meta.env.VITE_DEFAULT_TENANT_SLUG ?? ''

export const authService = {
  async login(email: string, password: string): Promise<LoginResponse> {
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
    }
    // En dev local (pas de subdomain), fournir le slug tenant.
    // En prod, le subdomain suffit (header inutile mais inoffensif).
    if (DEFAULT_TENANT_SLUG) {
      headers['X-Tenant-Slug'] = DEFAULT_TENANT_SLUG
    }
    const { data } = await axios.post<LoginResponse>(
      `${API_URL}/auth/login`,
      { email, password },
      { headers },
    )
    return data
  },
}
