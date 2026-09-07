#!/usr/bin/env node
/**
 * Mint a GitHub App installation token for the merge queue.
 *
 * Why an App at all. The queue has to merge past a code-owner review rule, and
 * three identities could in principle do it:
 *
 *   * `GITHUB_TOKEN` cannot. Its approval is `github-actions[bot]`, which no
 *     code-owner rule accepts, and its push starts no workflow — so a merge it
 *     made would never build or deploy `main`. This is what the queue falls
 *     back to, and why it currently reports refusals instead of merging.
 *   * A personal access token can, by *being* the owner: the approval reads as
 *     theirs, the merge reads as theirs, and a year later nobody can tell which
 *     merges a person made. It also expires — fine-grained tokens must — so it
 *     is a scheduled outage with a year's notice.
 *   * An App is its own identity. Merges read `<app>[bot]`, its pushes trigger
 *     workflows like anyone's, a ruleset can name it as a bypass actor without
 *     weakening what a *human* pull request needs, and uninstalling it revokes
 *     everything at once. Nothing about it expires on a calendar: the private
 *     key is long-lived and each run mints a token that dies in an hour.
 *
 * Why this file rather than `actions/create-github-app-token`. The queue job
 * holds `contents: write` on this repository, and the workflow deliberately
 * installs nothing there — a job that can merge to main should not also run a
 * dependency tree nobody has read. The whole exchange is two HTTPS calls and an
 * RS256 signature, all of it in Node's standard library, so the cost of doing
 * it here is smaller than the cost of auditing an action that does it for us.
 *
 * The exchange, in full:
 *
 *   1. Sign a JWT with the App's private key — this proves "I am App N".
 *   2. GET /repos/{owner}/{repo}/installation — which installation of that App
 *      covers this repository.
 *   3. POST /app/installations/{id}/access_tokens — a token for it, valid one
 *      hour, carrying exactly the permissions the App was granted.
 *
 * Run by .github/workflows/dependabot-merge-queue.yaml, which skips the step
 * when MERGE_QUEUE_APP_ID is unset and falls back to the next identity.
 */

import { createSign } from 'node:crypto'
import { appendFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

const API = 'https://api.github.com'

/**
 * GitHub refuses a JWT whose `exp` is more than ten minutes out, and some of
 * its checks read that window as `exp - iat`. Eight minutes ahead plus the
 * minute of backdating below leaves the whole window at nine, inside either
 * reading, with room to spare for a slow runner.
 */
export const JWT_LIFETIME_SECONDS = 480

/** Backdated against clock skew between the runner and GitHub, as the docs advise. */
export const JWT_BACKDATE_SECONDS = 60

const base64url = (value) => Buffer.from(value).toString('base64url')

/**
 * A JWT that says "I am this App", signed with its private key.
 *
 * @param appId     The App's numeric id.
 * @param key       Its private key, PEM as GitHub issues it (PKCS#1 or PKCS#8).
 * @param nowSeconds Unix seconds; injectable so the tests are not clock-dependent.
 */
export function appJwt(appId, key, nowSeconds = Math.floor(Date.now() / 1000)) {
  const header = base64url(JSON.stringify({ alg: 'RS256', typ: 'JWT' }))
  const payload = base64url(
    JSON.stringify({
      iat: nowSeconds - JWT_BACKDATE_SECONDS,
      exp: nowSeconds + JWT_LIFETIME_SECONDS,
      iss: String(appId),
    }),
  )

  const signer = createSign('RSA-SHA256')
  signer.update(`${header}.${payload}`)
  return `${header}.${payload}.${signer.sign(key, 'base64url')}`
}

/** One authenticated call, with the failure body kept — a 401 here is otherwise mute. */
async function call(path, { token, method = 'GET', fetchImpl = fetch }) {
  const response = await fetchImpl(`${API}${path}`, {
    method,
    headers: {
      authorization: `Bearer ${token}`,
      accept: 'application/vnd.github+json',
      'x-github-api-version': '2022-11-28',
      'user-agent': 'clubbar-merge-queue',
    },
  })

  const body = await response.json().catch(() => ({}))
  if (!response.ok) {
    throw new Error(`${method} ${path} failed: ${response.status} ${body?.message ?? '(no message)'}`)
  }
  return body
}

/**
 * Trade the App's identity for a token that can act on this repository.
 *
 * @returns {Promise<{token: string, expiresAt: string, installationId: number}>}
 */
export async function mintInstallationToken({ repo, appId, privateKey, nowSeconds, fetchImpl = fetch }) {
  const jwt = appJwt(appId, privateKey, nowSeconds)

  // Per repository rather than per App: an App installed on several
  // repositories has one installation each, and only this one may act here.
  const installation = await call(`/repos/${repo}/installation`, { token: jwt, fetchImpl })
  const issued = await call(`/app/installations/${installation.id}/access_tokens`, {
    token: jwt,
    method: 'POST',
    fetchImpl,
  })

  return { token: issued.token, expiresAt: issued.expires_at, installationId: installation.id }
}

async function main() {
  const repo = process.env.GITHUB_REPOSITORY
  const appId = process.env.MERGE_QUEUE_APP_ID
  const privateKey = process.env.MERGE_QUEUE_PRIVATE_KEY

  if (!repo) throw new Error('GITHUB_REPOSITORY is not set')
  if (!appId) throw new Error('MERGE_QUEUE_APP_ID is not set')
  if (!privateKey) throw new Error('MERGE_QUEUE_PRIVATE_KEY is not set — the App id is there without its key')

  const { token, expiresAt, installationId } = await mintInstallationToken({ repo, appId, privateKey })

  // Mask before anything can echo it. A token in a log is a token to rotate.
  console.log(`::add-mask::${token}`)
  appendFileSync(process.env.GITHUB_ENV, `MERGE_QUEUE_GH_TOKEN=${token}\n`)
  console.log(`Minted a token for installation ${installationId}; it expires at ${expiresAt}.`)
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  main().catch((error) => {
    console.error(error.message)
    process.exit(1)
  })
}
