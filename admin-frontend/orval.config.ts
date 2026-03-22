import { defineConfig } from 'orval'

export default defineConfig({
  admin: {
    input: '../api/admin.yaml',
    output: {
      mode: 'tags-split',
      target: 'src/api/generated/',
      schemas: 'src/api/generated',
      client: 'axios',
      override: {
        mutator: {
          path: 'src/api/client.ts',
          name: 'customInstance',
        },
      },
    },
  },
})
