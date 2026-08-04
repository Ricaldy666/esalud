import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import path from 'path'

export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    allowedHosts: true,
    proxy: {
      '/api/v1': {
        target: 'http://localhost:8080',
        changeOrigin: true,
      },
      '/sanctum': {
        target: 'http://localhost:8080',
        changeOrigin: true,
      },
      '/api/functional-criteria': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
      '/api/evaluate': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
      '/api/validate-rem': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
      '/api/login': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
      '/api/logout': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
      '/api/user': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  },
})
