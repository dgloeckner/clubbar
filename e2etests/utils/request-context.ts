import type { APIRequestContext } from '@playwright/test'

/**
 * The slice of Playwright's APIRequestContext our API helpers actually use.
 *
 * Helpers are typed against this rather than the full interface so that a
 * CSRF-aware wrapper — what `loginAs` in utils/csrf.ts returns — can be passed
 * wherever a request context is expected. That is what lets a test act as a
 * *different* admin, which some assertions need: the settlements list, for one,
 * only has more than one `created_by` value if more than one admin created a row.
 *
 * A real APIRequestContext satisfies this type, so existing callers are unaffected.
 */
export type ApiRequestLike = Pick<
  APIRequestContext,
  'get' | 'post' | 'patch' | 'put' | 'delete'
>
