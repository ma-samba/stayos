/**
 * Formatage monétaire unifié pour StayOS.
 * Toujours afficher les montants en FCFA (franc CFA - XOF).
 *
 * formatCurrency('165000')                      → "165 000 FCFA"
 * formatCurrency('165000', { perNight: true })  → "165 000 FCFA/nuit"
 * formatCurrency(55000)                         → "55 000 FCFA"
 */
export function formatCurrency(
  amount: string | number,
  options: { perNight?: boolean } = {},
): string {
  const value = typeof amount === 'string' ? Number(amount) : amount
  const safe = Number.isFinite(value) ? value : 0

  const formatted = new Intl.NumberFormat('fr-FR', {
    maximumFractionDigits: 0,
    minimumFractionDigits: 0,
  }).format(safe)

  const base = `${formatted} FCFA`
  return options.perNight ? `${base}/nuit` : base
}
