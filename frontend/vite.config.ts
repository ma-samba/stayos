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
