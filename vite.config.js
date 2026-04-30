import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// Default to the local Apache host used by this XAMPP setup. Override with
// VITE_BACKEND_TARGET when the PHP backend is served elsewhere.
const backendTarget = process.env.VITE_BACKEND_TARGET || 'http://localhost:8080'

export default defineConfig({
  plugins: [react()],
  server: {
    port: 3000,
    open: true,
    proxy: {
      '/api': {
        target: backendTarget,
        changeOrigin: true,
        rewrite: (path) => path.replace(/^\/api/, '')
      }
    }
  }
})
