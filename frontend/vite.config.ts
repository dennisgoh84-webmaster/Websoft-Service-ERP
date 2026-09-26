import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  server: {
    proxy: {
      // API_TARGET lets the self-test (selftest/run.sh) point a second
      // copy of the app at its own throwaway backend, beside the usual one.
      '/api': {
        target: process.env.API_TARGET ?? 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
})
