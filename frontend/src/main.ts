import { createApp } from 'vue'
import { createPinia } from 'pinia'
import * as Sentry from '@sentry/vue'
import App from './App.vue'
import router from './router'
import { i18n } from './i18n'
import '@/shared/styles/tokens.css'

const app = createApp(App)

// ── Sentry — Sprint 14-C polish ──────────────────────────────────────────────
//
// Activation CONDITIONNELLE : Sentry n'est initialisé que si VITE_SENTRY_DSN
// est posé et non vide. En dev local (DSN absent), aucune init → pas
// d'envoi réseau, pas de bruit dans les logs, pas d'erreur silencieuse
// au démarrage.
//
// La passe se fait AVANT app.use(…)/app.mount() pour que Sentry capture
// les erreurs survenant pendant l'enregistrement de Pinia/router/i18n et
// pendant le premier render. Pattern recommandé par @sentry/vue pour Vue 3.
const sentryDsn = import.meta.env.VITE_SENTRY_DSN
if (sentryDsn) {
    Sentry.init({
        app,
        dsn: sentryDsn,
        // `import.meta.env.MODE` = 'development' | 'production' (+ tout
        // mode Vite custom). Permet de filtrer côté Sentry l'environnement
        // sans poser une 2e Config Var dédiée.
        environment: import.meta.env.MODE,
        // 10% des transactions tracées en V1 — assez pour repérer les
        // tendances, conserve le quota Sentry confortable. À ré-évaluer
        // si l'on active browserTracingIntegration plus tard.
        tracesSampleRate: 0.1,
        // V1 minimale : on s'en tient au capture d'exceptions Vue.
        // Pas de browserTracingIntegration / replayIntegration tant que
        // l'on n'a pas besoin du tracing distribué côté front.
        integrations: [],
    })
}

app.use(createPinia())
app.use(router)
app.use(i18n)

app.mount('#app')
