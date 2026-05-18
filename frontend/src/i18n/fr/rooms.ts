export default {
  title:    'Chambres',
  singular: 'Chambre',
  plan:     'Plan des chambres',
  subtitle: 'Statut en temps réel · Mis à jour automatiquement',

  status: {
    available:    'Disponible',
    occupied:     'Occupée',
    cleaning:     'Ménage en cours',
    maintenance:  'Maintenance',
    out_of_order: 'Hors service',
  },

  filters: {
    all:          'Toutes',
    available:    'Disponibles',
    occupied:     'Occupées',
    cleaning:     'Ménage',
    maintenance:  'Maintenance',
    out_of_order: 'Hors service',
  },

  stats: {
    available:   'Disponibles',
    occupied:    'Occupées',
    cleaning:    'Ménage',
    maintenance: 'Maintenance',
    occupancy:   'Taux occupation',
  },

  labels: {
    number:    'Numéro de chambre',
    floor:     'Étage',
    capacity:  'Capacité',
    rate:      'Tarif / nuit',
    maxGuests: '{n} pers.',
  },

  actions: {
    markAvailable:    'Marquer disponible',
    markOccupied:     'Marquer occupée',
    markCleaning:     'Mettre en ménage',
    markMaintenance:  'Mettre en maintenance',
    markOutOfOrder:   'Mettre hors service',
    changeStatus:     'Changer le statut',
  },

  modal: {
    title:       'Changer le statut',
    confirmText: 'Marquer la chambre comme {status}',
    notePlaceholder: 'Ex: Fuite robinet, client VIP...',
    noteLabel:   'Note (optionnel)',
  },

  empty: 'Aucune chambre dans ce statut',
  error: 'Impossible de charger les chambres',
  retry: 'Réessayer',

  floor: 'Étage {number}',
  rooms: 'chambre | chambres',
}
