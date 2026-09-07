/**
 * Tests for the merge queue's App token exchange.
 *
 * This is the credential that will merge to main, so the parts worth pinning
 * are the ones that fail *quietly* if they drift: a JWT GitHub silently
 * rejects (wrong lifetime, wrong issuer, a signature that does not verify),
 * and an error body swallowed into a bare "401" nobody can act on.
 *
 * The keypair is generated here rather than committed — a test fixture that is
 * a real private key is a real private key.
 */

import { test } from 'node:test'
import assert from 'node:assert/strict'
import { createVerify, generateKeyPairSync } from 'node:crypto'

import {
  JWT_BACKDATE_SECONDS,
  JWT_LIFETIME_SECONDS,
  appJwt,
  mintInstallationToken,
} from './app-token.mjs'

const { privateKey, publicKey } = generateKeyPairSync('rsa', { modulusLength: 2048 })

const NOW = 1_757_000_000

const decode = (jwt) => {
  const [header, payload] = jwt.split('.')
  return {
    header: JSON.parse(Buffer.from(header, 'base64url').toString()),
    payload: JSON.parse(Buffer.from(payload, 'base64url').toString()),
  }
}

test('the JWT says which App it is, and says it in RS256', () => {
  const { header, payload } = decode(appJwt(1234, privateKey, NOW))

  assert.deepEqual(header, { alg: 'RS256', typ: 'JWT' })
  assert.equal(payload.iss, '1234', 'the App id travels as a string; GitHub rejects a number')
})

test('its signature verifies against the App public key', () => {
  const jwt = appJwt(1234, privateKey, NOW)
  const [header, payload, signature] = jwt.split('.')

  const verifier = createVerify('RSA-SHA256')
  verifier.update(`${header}.${payload}`)

  assert.ok(verifier.verify(publicKey, Buffer.from(signature, 'base64url')), 'signature must verify')
})

test('it lives under ten minutes and is backdated against clock skew', () => {
  // Both are hard limits on GitHub's side, and breaking either yields a 401
  // that says nothing about time.
  const { payload } = decode(appJwt(1234, privateKey, NOW))

  assert.equal(payload.iat, NOW - JWT_BACKDATE_SECONDS)
  assert.ok(payload.exp - payload.iat < 600, 'a JWT older than ten minutes is refused outright')
  assert.equal(payload.exp - NOW, JWT_LIFETIME_SECONDS)
})

/** A fetch that answers from a table and records what it was asked. */
function fakeGitHub(routes) {
  const calls = []
  return {
    calls,
    fetchImpl: async (url, options) => {
      calls.push({ url, method: options.method ?? 'GET', authorization: options.headers.authorization })
      const route = routes[new URL(url).pathname]
      if (!route) throw new Error(`unexpected request: ${url}`)
      return {
        ok: route.status === undefined || route.status < 300,
        status: route.status ?? 200,
        json: async () => route.body,
      }
    },
  }
}

test('the App identity is traded for a repository-scoped installation token', async () => {
  const github = fakeGitHub({
    '/repos/dgloeckner/clubbar/installation': { body: { id: 42 } },
    '/app/installations/42/access_tokens': { body: { token: 'ghs_secret', expires_at: '2026-09-07T20:00:00Z' } },
  })

  const result = await mintInstallationToken({
    repo: 'dgloeckner/clubbar',
    appId: 1234,
    privateKey,
    nowSeconds: NOW,
    fetchImpl: github.fetchImpl,
  })

  assert.equal(result.token, 'ghs_secret')
  assert.equal(result.installationId, 42)

  // The installation is looked up per repository, not per App: an App on
  // several repositories has one installation each, and only this one may act
  // here.
  assert.deepEqual(
    github.calls.map((c) => `${c.method} ${new URL(c.url).pathname}`),
    ['GET /repos/dgloeckner/clubbar/installation', 'POST /app/installations/42/access_tokens'],
  )
  assert.ok(github.calls.every((c) => c.authorization.startsWith('Bearer ey')), 'both calls carry the JWT')
})

test('a refusal keeps the reason GitHub gave', async () => {
  // Without the body this reads as a bare 401, and the difference between a
  // wrong key and an uninstalled App is the whole diagnosis.
  const github = fakeGitHub({
    '/repos/dgloeckner/clubbar/installation': { status: 404, body: { message: 'Not Found' } },
  })

  await assert.rejects(
    mintInstallationToken({
      repo: 'dgloeckner/clubbar',
      appId: 1234,
      privateKey,
      nowSeconds: NOW,
      fetchImpl: github.fetchImpl,
    }),
    /installation failed: 404 Not Found/,
  )
})
