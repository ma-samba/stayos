import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': resolve(__dirname, 'src'),
    },
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    proxy: {
      // Proxy /api → backend Symfony
      '/api': {
        target: 'http://nginx:80',
        changeOrigin: true,
      },
      // Proxy /superadmin → backend Symfony (back-office SuperAdmin).
      // Le bypass laisse passer les requêtes HTML du navigateur (chargement
      // initial de la SPA sur /superadmin/login, etc.) vers Vite, et ne
      // proxifie vers le backend que les appels API (Accept: application/json).
      '/superadmin': {
        target: 'http://nginx:80',
        changeOrigin: true,
        bypass: (req) => {
          if (req.headers.accept?.includes('text/html')) {
            return req.url
          }
        },
      },
      // Proxy /public → backend Symfony (endpoints publics, ex.
      // acceptation d'invitation employé). Pas de routing Vue Router
      // sur /public, donc pas besoin du bypass HTML.
      '/public': {
        target: 'http://nginx:80',
        changeOrigin: true,
      },
      // Proxy Mercure SSE
      '/.well-known/mercure': {
        target: 'http://mercure:9090',
        changeOrigin: true,
      },
    },
  },
  build: {
    outDir: 'dist',
    sourcemap: true,
  },
})
