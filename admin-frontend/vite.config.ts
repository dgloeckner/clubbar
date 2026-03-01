import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

const apiProxy = {
  '/api': {
    target: 'http://localhost:8080',
    changeOrigin: true,
    secure: false,
    cookieDomainRewrite: {
      '*': 'localhost'
    },
    ws: true,
  }
}

export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    proxy: apiProxy,
  },
  preview: {
    port: 5173,
    proxy: apiProxy,
  },
  build: {
    outDir: 'dist',
    sourcemap: true
  }
})
