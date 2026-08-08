import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'

// Unit tests run in a non-UTC timezone on purpose. UTC is the one zone in
// which a UTC-vs-local date bug cannot reproduce, so a suite pinned to it
// certifies date handling it never exercised — that is how #95 shipped.
//
// It has to be set here, before vitest spawns its workers: a worker is handed
// a *copy* of process.env, so assigning TZ inside a test never reaches tzset
// and the zone silently stays put. An ambient TZ still wins, which is what
// `npm run test:timezones` uses to run the suite on both sides of Greenwich
// (each direction only breaks on one side) and what lets a contributor
// reproduce a report from their own zone.
if (process.env.VITEST) {
  process.env.TZ ||= 'America/Los_Angeles'
}

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
