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
const expectedConfig = fs.readFileSync(path.join(fixtures, 'golden.config.php.txt'))

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
  assert.equal(header.compression, 'gzip', 'The body is compressed, and the header says which codec.')
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

  // Async from container version 3 on: inflating uses DecompressionStream.
  const plaintext = await decryptor.open(sodium, archive, secretKey(DEV_SECRET_A))
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

  await assert.rejects(
    () => decryptor.open(sodium, archive, stranger),
    /not sealed to this key/i,
    'A wrong key must say so, not fail with a decryption error the holder cannot act on.',
  )
})

test('a truncated archive is refused rather than partly restored', async () => {
  await sodium.ready

  const truncated = archive.subarray(0, Math.floor(archive.length * 0.8))

  await assert.rejects(() => decryptor.open(sodium, truncated, secretKey(DEV_SECRET_A)))
})

test('a tampered archive fails authentication', async () => {
  await sodium.ready

  const tampered = Uint8Array.from(archive)
  tampered[tampered.length - 1] ^= 0xff

  await assert.rejects(() => decryptor.open(sodium, tampered, secretKey(DEV_SECRET_A)))
})

test('an archive whose codec this tool does not know is refused, not guessed at', async () => {
  await sodium.ready

  // Handing a holder a still-compressed file named `.sql` would give them
  // something that imports as garbage — the failure this container exists to
  // prevent, met at the worst moment of the club's year.
  const { header } = decryptor.readHeader(archive)
  const fake = Buffer.from(archive).toString('binary').replace('"compression":"gzip"', '"compression":"brot"')

  assert.equal(header.compression, 'gzip', 'Precondition: the fixture is gzip.')
  await assert.rejects(
    () => decryptor.open(sodium, Uint8Array.from(Buffer.from(fake, 'binary')), secretKey(DEV_SECRET_A)),
    /cannot decompress|newer version/i,
  )
})

test('JavaScript reads the config.php block PHP wrote (#692)', async () => {
  await sodium.ready
  // Awaited: #691 made `open()` async so it can inflate the compressed body,
  // and an un-awaited Promise reaches `extractConfig` as an object with no
  // bytes in it — which reads as "the archive carries no config" rather than
  // as a mistake in this line.
  const plaintext = await decryptor.open(sodium, archive, secretKey(DEV_SECRET_A))

  const config = decryptor.extractConfig(plaintext)

  assert.notEqual(config, null, 'the golden archive carries a config block and this reader missed it')
  assert.deepEqual(
    Buffer.from(config),
    expectedConfig,
    'the two implementations disagree about the config block format'
  )
})

test('the header says an archive carries a config, without any key', () => {
  const { header } = decryptor.readHeader(archive)

  // Readable before decrypting, because it changes what a restore still needs:
  // an archive without it restores a database nobody can log in to.
  assert.equal(header.config_included, true)
})

test('a dump with no config block reads as none rather than as empty', () => {
  assert.equal(decryptor.extractConfig(new TextEncoder().encode('-- dump\nSET NAMES utf8mb4;\n')), null)
})

/*
 * ---------------------------------------------------------------------------
 * The per-table split (#692)
 *
 * `DatabaseDump` brackets every table with terminated markers so a restore can
 * address one table, and so an archive too large for phpMyAdmin's upload limit
 * can still be imported. Cutting at those markers is now something the browser
 * tool does for the holder rather than something the runbook asks them to do by
 * hand.
 *
 * Which makes it a second format with two implementations, and the same rule
 * applies: neither is its own witness. `golden.split.sql` and the pieces beside
 * it are cut by PHP's `Tests\Support\SqlScript` — the helper whose pieces
 * `RestoreRoundTripTest` restores into a real database — so what is asserted
 * here is not "the splitter is self-consistent" but "it reproduces bytes that
 * are known to restore".
 * ---------------------------------------------------------------------------
 */

const splitSource = new Uint8Array(fs.readFileSync(path.join(fixtures, 'golden.split.sql')))

test('the per-table split reproduces, byte for byte, the pieces PHP cuts', () => {
  const split = decryptor.splitByTable(splitSource, { categories: 2, members: 2 })

  assert.deepEqual(split.tables.map((t) => t.name), ['categories', 'members'])

  for (const table of split.tables) {
    assert.deepEqual(
      Buffer.from(table.sql),
      fs.readFileSync(path.join(fixtures, `golden.split.${table.name}.sql`)),
      `the two implementations disagree about the ${table.name} section`
    )
  }
})

test('a section stops at its own marker instead of swallowing the table after it', () => {
  const [categories] = decryptor.splitByTable(splitSource, {}).tables
  const sql = Buffer.from(categories.sql).toString('utf8')

  // The fixture's first table holds a row whose *value* is `-- >>> TABLE
  // getranke`. It must survive in the piece — cutting on the marker text
  // wherever it appears would truncate this section mid-row, and the row that
  // did it would be a legitimate category name.
  assert.match(sql, /'-- >>> TABLE getranke'/)

  // And the next table must not be in it. This is the property one table
  // cannot test, and the reason `golden.split.sql` exists beside the
  // single-table golden archive.
  assert.doesNotMatch(sql, /TABLE members/)
  assert.doesNotMatch(sql, /INSERT INTO `members`/)
})

test('every piece carries the session settings, without the ALTER DATABASE line', () => {
  for (const table of decryptor.splitByTable(splitSource, {}).tables) {
    const sql = Buffer.from(table.sql).toString('utf8')

    // The one with no symptom when it is missing: a piece imported in the
    // host's own zone shifts every TIMESTAMP in it, consistently.
    assert.match(sql, /^SET time_zone = '\+00:00';/m)
    assert.match(sql, /^SET FOREIGN_KEY_CHECKS = 0;/m)
    assert.match(sql, /^SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';/m)

    // Dropped because it names no database, so it would retarget whichever
    // schema the operator happens to have open.
    assert.doesNotMatch(sql, /ALTER DATABASE/)
  }
})

test('the split reports each table row count from the header, and says so when it cannot', () => {
  const stated = decryptor.splitByTable(splitSource, { categories: 2 }).tables

  assert.equal(stated[0].rows, 2)
  // An archive whose header manifest does not name a table still yields the
  // table; the count reads as unknown rather than as zero, which would be a
  // claim about the data.
  assert.equal(stated[1].rows, null)
})

test('splitting the golden archive gives back a piece that is a prefix-free whole', async () => {
  await sodium.ready
  const plaintext = await decryptor.open(sodium, archive, secretKey(DEV_SECRET_A))
  const split = decryptor.splitByTable(plaintext, decryptor.readHeader(archive).header.manifest)

  assert.deepEqual(split.tables.map((t) => t.name), ['members'])

  // The config block trails the dump's footer and is not a table. A splitter
  // that scanned for anything marker-shaped would offer it as one.
  const sql = Buffer.from(split.tables[0].sql).toString('utf8')
  assert.doesNotMatch(sql, /CONFIG config\.php/)
  assert.match(sql, /^-- <<< TABLE members\n$/m)
})

test('something that is not a dump is refused rather than split into nothing', () => {
  assert.throws(
    () => decryptor.splitByTable(new TextEncoder().encode('SET NAMES utf8mb4;\n'), {}),
    /no table markers/
  )
})
