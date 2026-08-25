/**
 * The JavaScript half of the backup container's drift guard.
 *
 * `backend/tests/Fixtures/backup/golden.cbb` was sealed once by PHP
 * (`BackupSealedBox::seal()`) and is committed. Two readers open it:
 * `BackupSealedBoxGoldenFixtureTest` on the PHP side, and this file on the JS
 * side, using the *same* `tools/backup-decryptor.js` the offline decryptor
 * ships to a key holder.
 *
 * Neither implementation is its own witness. If the container format changes
 * and only one reader is updated, that reader's test goes red here rather than
 * on the day someone needs a restore.
 *
 * Runs in `lint-e2e` via `npm run test:lint-rules` (which globs
 * `scripts/*.test.mjs`) — no browser and no database, because the thing under
 * test is a pure format.
 *
 * Part of #689 and #703, epic #686.
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import crypto from 'node:crypto'
import path from 'node:path'
import Module from 'node:module'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'

const require = createRequire(import.meta.url)
const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..')
const vendor = path.join(repoRoot, 'tools/vendor')

// The npm build of libsodium-wrappers is CommonJS and requires its core
// package by bare name. Resolve that one name to the vendored file rather than
// editing the vendored file, so it stays byte-identical to what npm published.
const originalResolve = Module._resolveFilename
Module._resolveFilename = function (request, ...rest) {
  if (request === 'libsodium') return path.join(vendor, 'libsodium.js')
  return originalResolve.call(this, request, ...rest)
}

const sodium = require(path.join(vendor, 'libsodium-wrappers.js'))
const decryptor = require(path.join(repoRoot, 'tools/backup-decryptor.js'))

const fixtures = path.join(repoRoot, 'backend/tests/Fixtures/backup')
const archive = new Uint8Array(fs.readFileSync(path.join(fixtures, 'golden.cbb')))
const expectedSha = fs.readFileSync(path.join(fixtures, 'golden.plaintext.sha256'), 'utf8').trim()

// The published development keypairs (ADR-0036). Public by design; the sealing
// side refuses them outside development, so they can never protect real data.
const DEV_SECRET_A = 'f678fb17b592c29db54e43f808ee74fd67f7dd5c6c405b24e3e31ead38f3058a'

const secretKey = (hex) => Uint8Array.from(Buffer.from(hex, 'hex'))

test('the header is readable without any key at all', () => {
  const { header } = decryptor.readHeader(archive)

  assert.equal(header.version, decryptor.VERSION)
  assert.deepEqual(
    header.recipients.map((r) => r.label),
    ['admin', 'vorstand'],
    'The decryptor tells a holder which key to fetch before asking for one.',
  )
})

test('the header describes the archive, so the tool can show it before asking for a key', () => {
  // ADR-0049 decision 8: there is no backup state in the database, so an
  // archive found on a share years later has only this to be identified by —
  // and the JS half has to read all of it, not just the recipients, or the
  // offline tool shows a holder less than the file actually says.
  const { header } = decryptor.readHeader(archive)

  assert.equal(header.instance.name, 'SV Musterstadt')
  assert.equal(header.instance.database, 'clubbar')
  assert.equal(header.schema_version, '054_credit_limit_digest.sql')
  assert.equal(header.dump_format, 1)
  assert.ok(header.manifest.members > 0, 'The manifest names what is inside without decrypting.')
  assert.equal(
    header.plaintext_sha256,
    expectedSha,
    'The header states the checksum of what was sealed; the decryptor shows whether it holds.',
  )
})

test('an archive from a version this tool does not read is refused by version, not as garbage', async () => {
  // The version byte sits after the magic precisely so a later build's archive
  // says "this tool reads version N" rather than "bad magic", which would send
  // a holder looking for a corrupt file that is not corrupt.
  const future = Uint8Array.from(archive)
  future[decryptor.MAGIC.length] = 9

  assert.throws(() => decryptor.readHeader(future), /version 9.*reads version/i)
})

test('JavaScript opens what PHP sealed', async () => {
  await sodium.ready

  const plaintext = decryptor.open(sodium, archive, secretKey(DEV_SECRET_A))
  const sha = crypto.createHash('sha256').update(Buffer.from(plaintext)).digest('hex')

  assert.equal(
    sha,
    expectedSha,
    'The JavaScript decryptor no longer reproduces what PHP sealed. Either the container '
      + 'format changed and only one side was updated, or one of them has a bug.',
  )
})

test('a key the archive was not sealed to is refused by name', async () => {
  await sodium.ready

  const stranger = sodium.crypto_box_keypair().privateKey

  assert.throws(
    () => decryptor.open(sodium, archive, stranger),
    /not sealed to this key/i,
    'A wrong key must say so, not fail with a decryption error the holder cannot act on.',
  )
})

test('a truncated archive is refused rather than partly restored', async () => {
  await sodium.ready

  const truncated = archive.subarray(0, Math.floor(archive.length * 0.8))

  assert.throws(() => decryptor.open(sodium, truncated, secretKey(DEV_SECRET_A)))
})

test('a tampered archive fails authentication', async () => {
  await sodium.ready

  const tampered = Uint8Array.from(archive)
  tampered[tampered.length - 1] ^= 0xff

  assert.throws(() => decryptor.open(sodium, tampered, secretKey(DEV_SECRET_A)))
})
