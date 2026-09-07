import { defineConfig } from 'orval'

export default defineConfig({
  admin: {
    input: '../api/admin.yaml',
    output: {
      mode: 'tags-split',
      target: 'src/api/generated/',
      // The schemas get a directory of their own, away from the tag
      // factories. Both writers emit an `index.ts`, so pointing them at one
      // directory makes the two barrels race for the same file and whichever
      // orval writes last wins. orval 8.26 wrote the schema barrel last,
      // orval 8.28 the tag barrel, and the upgrade took every type export off
      // `src/api/generated/index.ts` with nothing in the generator's output
      // saying so (#843). Keep these two paths distinct.
      schemas: 'src/api/generated/model',
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
