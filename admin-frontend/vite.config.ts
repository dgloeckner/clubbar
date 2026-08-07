import { defineConfig } from 'vitest/config'
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
  },
  test: {
    environment: 'node',
    coverage: {
      provider: 'v8',
      // Delegating pages and interactive components to the Playwright E2E
      // suite is a ruling (#166, amended there), not an oversight — #168
      // left it deliberately undisturbed, and patch coverage inherits this
      // same scope. Unit coverage is measured over the pure logic seams
      // (utils and hooks), which sit at ~100%. The 80% floor fails the
      // build on regression (#103).
      include: ['src/utils/**', 'src/hooks/**'],
      thresholds: {
        lines: 80,
        functions: 80,
        branches: 80,
        statements: 80,
      },
    },
  }
})
