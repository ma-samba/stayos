// Libellés humains pour les features de plan exposées par le backend.
// Les clés correspondent aux valeurs stockées en BDD (plan.features).
// Toute feature inconnue est affichée telle quelle (fallback).

const FEATURE_LABELS: Record<string, string> = {
  channel_manager:    'Channel Manager (OTA)',
  advanced_reports:   'Rapports avancés',
  api_access:         'Accès API',
  multi_property:     'Multi-établissements',
  revenue_management: 'Revenue management',
}

export function featureLabel(feature: string): string {
  return FEATURE_LABELS[feature]
    ?? feature.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())
}
