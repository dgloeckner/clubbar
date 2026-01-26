import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://localhost:8080',
        changeOrigin: true,
        secure: false,
        // Ensure cookies are properly handled
        onProxyRes: (proxyRes, req, res) => {
          // Don't modify Set-Cookie headers - let them pass through
          // Ensure withCredentials works properly
        },
        // Pass through all cookies from the backend
        ws: true,
      }
    }
  },
  build: {
    outDir: 'dist',
    sourcemap: true
  }
})
