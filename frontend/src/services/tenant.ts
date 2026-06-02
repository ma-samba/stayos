/**
 * Résout le slug tenant à utiliser pour le header X-Tenant-Slug.
 *
 * Priorité :
 *   1. Sous-domaine du hostname courant si ce n'est pas un host « nu »
 *      (localhost, 127.0.0.1, IP). Ex :
 *        savana.localhost          → "savana"
 *        villa-collines.localhost  → "villa-collines"
 *        savana.stayos.sn          → "savana"
 *   2. VITE_DEFAULT_TENANT_SLUG (fallback dev quand on tape localhost:5173).
 *   3. null si rien — le caller doit alors omettre le header.
 */
export function resolveTenantSlug(): string | null {
  const host = window.location.hostname
  const bare = /^(localhost|127\.0\.0\.1|\d+\.\d+\.\d+\.\d+)$/.test(host)

  if (!bare) {
    const firstLabel = host.split('.')[0]
    if (firstLabel && firstLabel !== 'www') {
      return firstLabel
    }
  }

  const fallback = import.meta.env.VITE_DEFAULT_TENANT_SLUG
  return typeof fallback === 'string' && fallback !== '' ? fallback : null
}
