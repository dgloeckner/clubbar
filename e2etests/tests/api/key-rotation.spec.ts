import type { APIRequestContext, APIResponse } from '@playwright/test'
import { test, expect } from '../../fixtures/auth.fixture'
import {
  DEV_PRIVATE_KEY,
  ROTATION_KEY_IDENTIFIER,
  ROTATION_PRIVATE_KEY,
  ROTATION_PUBLIC_KEY,
  WRONG_PRIVATE_KEY,
  exportSepaXml,
  listEncryptionKeys,
} from '../../fixtures/encryption'
import { TEST_CREDENTIALS } from '../../config/test-credentials'
import { submitTotpWithRetry } from '../../utils/totp'

/**
 * Annual key rotation, end to end (#394, ADR-0036).
 *
 * This spec moves the whole installation from one encryption keypair to the
 * next: register the successor, activate it, walk every stored IBAN across in
 * batches with the old private key, and retire the old one. Afterwards the
 * proof that it worked is that a SEPA export succeeds with the *new* key and is
 * refused with the old one — the club's ability to read its own members' bank
 * details has genuinely moved.
 *
 * **Why it lives in `api-ordered`.** Which key is ACTIVE is installation-wide
 * state: from the moment the successor is activated, every other spec's member
 * writes seal under it. Nothing here is safe to run beside the parallel suite,
 * so it runs alone, after it.
 *
 * **Why the successor is a committed keypair.** The private half is the only
 * way back to the IBANs sealed under it. A keypair invented inside this process
 * would disappear with the process and leave a database nothing could read —
 * including the export specs. So it is published in `fixtures/encryption.ts`,
 * and `IbanSealedBox` refuses it outside development for exactly that reason.
 *
 * **This spec needs a database that has not been rotated yet.** A rotation
 * cannot be undone through the API — that is the point of the state machine —
 * and a public key can only be registered once. Every CI shard brings up its
 * own MariaDB and seeds it, so that holds there by construction. Locally,
 * `docker compose down -v && scripts/dev-setup.sh` is what restores it; the
 * registration assertion below says so, rather than the spec quietly skipping
 * itself on a condition it read at runtime (ruling #146).
 *
 * Re-applying `seed.sql` alone puts `dev-key-2026` back in force — enough to
 * keep the rest of the suite working after this has run, not enough to run it
 * again.
 */

const API_BASE = 'http://localhost:8080/api'

/** Enough for any plausible test database; the assertion below names the cap. */
const MAX_BATCHES = 20

test.describe.configure({ mode: 'serial' })

/**
 * POST with a fresh step-up credential, retrying past a spent time-step.
 *
 * Every one of these endpoints wants the caller's own password and a current
 * TOTP code, and a code is single-use per 30-second step (#338) — so a rotation
 * that runs several batches back to back would otherwise fail on the second
 * one with a credential that is perfectly correct and merely already used.
 */
function stepUpPost(
  request: APIRequestContext,
  url: string,
  body: Record<string, unknown> = {},
): Promise<APIResponse> {
  return submitTotpWithRetry(TEST_CREDENTIALS.totp.adminSecret, (code) =>
    request.post(url, {
      data: { ...body, current_password: TEST_CREDENTIALS.admin.password, totp_code: code },
    }),
  )
}

test.describe('IBAN encryption key rotation', () => {
  test(
    'the installation moves onto a new keypair and only that key can export',
    async ({ authenticatedRequest, settlementFactory }) => {
      // Batches wait out TOTP time-steps between them; the work itself is fast.
      test.setTimeout(15 * 60 * 1000)

      const keys = await listEncryptionKeys(authenticatedRequest)
      const active = keys.find((key) => key.status === 'active')!
      expect(active, 'the dev seed installs an active key').toBeDefined()

      // A settlement created *before* the rotation: its IBANs are sealed under
      // the old key, so it is the thing the rotation actually has to carry
      // across. Exporting it afterwards is the proof that it did.
      const settlement = await settlementFactory.create({ amountCents: 1500 })

      // ── Register the successor ──────────────────────────────────────────
      const registered = await stepUpPost(authenticatedRequest, `${API_BASE}/admin/encryption-keys`, {
        public_key: ROTATION_PUBLIC_KEY,
        key_identifier: ROTATION_KEY_IDENTIFIER,
      })
      expect(
        registered.status(),
        'a public key can only be registered once — if this is 422, the database has already '
          + 'been rotated: recreate it with `docker compose down -v && scripts/dev-setup.sh`',
      ).toBe(201)

      const newKeyId = (await registered.json()).key.id as string
      expect((await registered.json()).key.status).toBe('pending')

      // A pending key seals nothing yet, so there is nothing to rotate away
      // from it — and the old key is still ACTIVE, which is equally refused.
      const tooEarly = await stepUpPost(
        authenticatedRequest,
        `${API_BASE}/admin/encryption-keys/${active.id}/rotate-batch`,
        { private_key: DEV_PRIVATE_KEY },
      )
      expect(tooEarly.status(), 'the active key cannot be rotated away from').toBe(409)

      // ── Activate it: this is what starts the rotation ───────────────────
      // The private half comes along as proof the club can open what this key
      // is about to seal — a public key alone proves nothing (ADR-0036).
      const activated = await stepUpPost(
        authenticatedRequest,
        `${API_BASE}/admin/encryption-keys/${newKeyId}/activate`,
        { private_key: ROTATION_PRIVATE_KEY },
      )
      expect(activated.status(), await activated.text()).toBe(200)
      expect((await activated.json()).key.status).toBe('active')

      const afterActivation = await listEncryptionKeys(authenticatedRequest)
      const oldKey = afterActivation.find((key) => key.id === active.id)!
      expect(oldKey.status, 'the superseded key moves to retiring').toBe('retiring')
      expect(
        oldKey.sealed_record_count ?? 0,
        'activation must not touch what is already stored',
      ).toBeGreaterThan(0)

      // ── The old key is the only thing that can open the backlog ─────────
      const wrongKey = await stepUpPost(
        authenticatedRequest,
        `${API_BASE}/admin/encryption-keys/${active.id}/rotate-batch`,
        { private_key: WRONG_PRIVATE_KEY },
      )
      expect(wrongKey.status()).toBe(422)
      expect((await wrongKey.json()).error).toBe('private_key_mismatch')

      // Nothing moved: a refused key must not consume any of the backlog.
      const afterRefusal = (await listEncryptionKeys(authenticatedRequest)).find(
        (key) => key.id === active.id,
      )!
      expect(afterRefusal.sealed_record_count).toBe(oldKey.sealed_record_count)

      // ── Walk the backlog, one batch per request ─────────────────────────
      let batches = 0
      let remaining = oldKey.sealed_record_count ?? 0
      let movedTotal = 0

      while (remaining > 0 && batches < MAX_BATCHES) {
        const response = await stepUpPost(
          authenticatedRequest,
          `${API_BASE}/admin/encryption-keys/${active.id}/rotate-batch`,
          { private_key: DEV_PRIVATE_KEY },
        )
        expect(response.status(), await response.text()).toBe(200)

        const rotation = (await response.json()).rotation
        expect(rotation.target_key_id).toBe(newKeyId)
        // Progress is guaranteed: a batch that neither moved nor skipped a row
        // while rows remain would loop forever.
        expect(rotation.processed + rotation.skipped).toBeGreaterThan(0)

        movedTotal += rotation.processed
        remaining = rotation.remaining
        batches++
      }

      expect(remaining, `the backlog did not drain within ${MAX_BATCHES} batches`).toBe(0)
      expect(movedTotal).toBeGreaterThan(0)

      // ── Retire the old key ──────────────────────────────────────────────
      const completed = await stepUpPost(
        authenticatedRequest,
        `${API_BASE}/admin/encryption-keys/${active.id}/complete-rotation`,
      )
      expect(completed.status(), await completed.text()).toBe(200)
      expect((await completed.json()).key.status).toBe('retired')
      expect((await completed.json()).key.sealed_record_count).toBe(0)

      // ── The proof: the capability has moved ─────────────────────────────
      const withNewKey = await exportSepaXml(authenticatedRequest, settlement.id, {
        privateKey: ROTATION_PRIVATE_KEY,
      })
      expect(withNewKey.status(), await withNewKey.text()).toBe(200)
      expect(await withNewKey.text()).toContain('<DbtrAcct>')

      const secondSettlement = await settlementFactory.create({ amountCents: 900 })
      const withOldKey = await exportSepaXml(authenticatedRequest, secondSettlement.id, {
        privateKey: DEV_PRIVATE_KEY,
      })
      expect(
        withOldKey.status(),
        'the retired key must no longer open anything',
      ).toBe(422)
      expect((await withOldKey.json()).error).toBe('private_key_mismatch')

      // ── The audit trail carries the row counts ──────────────────────────
      const auditResponse = await authenticatedRequest.get(
        `${API_BASE}/admin/audit-log?entity_id=${active.id}&per_page=50`,
      )
      expect(auditResponse.status()).toBe(200)

      const actions = ((await auditResponse.json()).data as Array<{ action: string }>).map(
        (entry) => entry.action,
      )
      expect(actions).toContain('key_rotation_started')
      expect(actions).toContain('key_rotation_batch_completed')
      expect(actions).toContain('key_rotation_completed')
      expect(actions).toContain('key_retired')

      const auditText = await auditResponse.text()
      expect(auditText).toContain('affected_record_count')
      // The trail records how much moved, never what moved.
      expect(auditText).not.toContain('DE89370400440532013000')
    },
  )
})
