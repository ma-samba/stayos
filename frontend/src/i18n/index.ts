import { createI18n } from 'vue-i18n'
import fr from './fr'
import en from './en'

export const i18n = createI18n({
  legacy: false,
  locale: localStorage.getItem('locale') ?? 'fr',
  fallbackLocale: 'en',
  messages: { fr, en },
  numberFormats: {
    fr: {
      currency: {
        style: 'currency',
        currency: 'XOF',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
      },
      percent: {
        style: 'percent',
        minimumFractionDigits: 1,
      },
    },
    en: {
      currency: {
        style: 'currency',
        currency: 'XOF',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
      },
    },
  },
  datetimeFormats: {
    fr: {
      short:    { day: '2-digit', month: '2-digit', year: 'numeric' },
      long:     { day: '2-digit', month: 'long',    year: 'numeric' },
      datetime: { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' },
      time:     { hour: '2-digit', minute: '2-digit' },
    },
    en: {
      short:    { day: '2-digit', month: '2-digit', year: 'numeric' },
      long:     { day: '2-digit', month: 'long',    year: 'numeric' },
      datetime: { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' },
      time:     { hour: '2-digit', minute: '2-digit' },
    },
  },
})
