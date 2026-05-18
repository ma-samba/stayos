export default {
  title:    'Rooms',
  singular: 'Room',
  plan:     'Room floor plan',
  subtitle: 'Real-time status · Updated automatically',

  status: {
    available:    'Available',
    occupied:     'Occupied',
    cleaning:     'Cleaning',
    maintenance:  'Maintenance',
    out_of_order: 'Out of order',
  },

  filters: {
    all:          'All',
    available:    'Available',
    occupied:     'Occupied',
    cleaning:     'Cleaning',
    maintenance:  'Maintenance',
    out_of_order: 'Out of order',
  },

  stats: {
    available:   'Available',
    occupied:    'Occupied',
    cleaning:    'Cleaning',
    maintenance: 'Maintenance',
    occupancy:   'Occupancy rate',
  },

  labels: {
    number:    'Room number',
    floor:     'Floor',
    capacity:  'Capacity',
    rate:      'Rate / night',
    maxGuests: '{n} guests',
  },

  actions: {
    markAvailable:   'Mark available',
    markOccupied:    'Mark occupied',
    markCleaning:    'Set to cleaning',
    markMaintenance: 'Set to maintenance',
    markOutOfOrder:  'Set out of order',
    changeStatus:    'Change status',
  },

  modal: {
    title:           'Change status',
    confirmText:     'Mark room as {status}',
    notePlaceholder: 'E.g.: Leaking faucet, VIP guest...',
    noteLabel:       'Note (optional)',
  },

  empty: 'No rooms with this status',
  error: 'Unable to load rooms',
  retry: 'Retry',

  floor: 'Floor {number}',
  rooms: 'room | rooms',
}
