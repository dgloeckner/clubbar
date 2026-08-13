import { test, expect, APIRequestContext } from "@playwright/test";
import { TEST_CREDENTIALS } from "../../config/test-credentials";
import { generateTotp, submitTotpWithRetry } from "../../utils/totp";

/**
 * Serial within this file: these tests perform full password+MFA logins as the
 * shared seeded admin, and `totp_last_timestep` (#338) accepts one TOTP code
 * per 30-second step per account. Run 4-wide, the second login in a step is
 * correctly rejected as a replay and the test fails for a reason that has
 * nothing to do with what it is testing. E2E Pattern 004: a test that cannot
 * be made independent of a shared resource is run in order instead.
 */
test.describe.configure({ mode: 'default' })

/**
 * MFA attempt cap (#78, ruling #145).
 *
 * `/api/auth/mfa` used to accept unlimited 6-digit guesses inside its 300s
 * window, so a stolen password was enough to brute-force the second factor.
 * Five wrong codes now destroy the MFA-pending session and the password must be
 * entered again.
 *
 * The other half of the fix — the persistent counter that makes re-entering the
 * password run into a 429 rather than mint a fresh window — needs the rate
 * limiter enabled and lives in `auth-rate-limit.spec.ts`, which is excluded from
 * the default run. The cap tested here is per session, so it holds either way.
 *
 * Parallel-safe (Pattern 004): every test opens its own MFA-pending session via
 * its own cookie, and the cap is counted inside that session.
 */

const API_BASE = "http://localhost:8080/api";
const WRONG_CODE = "000000";
const MFA_MAX_ATTEMPTS = 5;

// The __Host- prefix (issue #251) only works on a Secure cookie, so the name
// follows the scheme the suite runs on. See admin-auth.spec.ts and
// backend/tests/Unit/Shared/Config/AppConfigTest.php for the derivation.
const EXPECTED_SESSION_COOKIE_NAME = API_BASE.startsWith("https://") ? "__Host-session" : "_session";

/** Start a login and return the cookie carrying the MFA-pending session. */
async function startMfaPendingSession(request: APIRequestContext): Promise<string> {
  const response = await request.post(`${API_BASE}/auth/login`, {
    data: {
      email: TEST_CREDENTIALS.admin.email,
      password: TEST_CREDENTIALS.admin.password,
    },
  });

  expect(response.status()).toBe(200);
  expect(await response.json()).toHaveProperty("requiresMfa", true);

  const setCookie = response.headers()["set-cookie"];
  const cookie = (Array.isArray(setCookie) ? setCookie[0] : setCookie || "").split(";")[0];
  expect(cookie).toContain(EXPECTED_SESSION_COOKIE_NAME);

  return cookie;
}

function submitCode(request: APIRequestContext, cookie: string, code: string) {
  return request.post(`${API_BASE}/auth/mfa`, {
    data: { code },
    headers: { cookie },
  });
}

test.describe("MFA attempt cap", () => {
  test("a wrong code below the cap leaves the pending session usable", async ({ request }) => {
    // Retries against a same-window replay collision on the shared admin secret (#338).
    test.setTimeout(360_000);
    const cookie = await startMfaPendingSession(request);

    const rejected = await submitCode(request, cookie, WRONG_CODE);
    expect(rejected.status()).toBe(401);
    expect((await rejected.json()).error).toBe("invalid_credentials");

    // A fat-fingered digit must not cost the user their session.
    const accepted = await submitTotpWithRetry(TEST_CREDENTIALS.totp.adminSecret, (code) =>
      submitCode(request, cookie, code)
    );
    expect(accepted.status()).toBe(200);
    expect(await accepted.json()).toHaveProperty("message", "Login successful");
  });

  test("the fifth wrong code destroys the pending session", async ({ request }) => {
    const cookie = await startMfaPendingSession(request);

    for (let attempt = 1; attempt < MFA_MAX_ATTEMPTS; attempt++) {
      const response = await submitCode(request, cookie, WRONG_CODE);
      expect(response.status()).toBe(401);
      expect((await response.json()).error).toBe("invalid_credentials");
    }

    const capped = await submitCode(request, cookie, WRONG_CODE);
    expect(capped.status()).toBe(401);
    expect((await capped.json()).error).toBe("mfa_attempts_exceeded");
  });

  test("a correct code is refused once the cap destroyed the session", async ({ request }) => {
    const cookie = await startMfaPendingSession(request);

    for (let attempt = 0; attempt < MFA_MAX_ATTEMPTS; attempt++) {
      await submitCode(request, cookie, WRONG_CODE);
    }

    // The guessing budget is spent: even the right answer is no longer accepted
    // on this session, which is what bounds the 10^6 code space.
    const response = await submitCode(request, cookie, generateTotp(TEST_CREDENTIALS.totp.adminSecret));
    expect(response.status()).toBe(401);
    expect((await response.json()).error).toBe("mfa_session_expired");
  });

  test("re-entering the password issues a fresh, usable session", async ({ request }) => {
    // Retries against a same-window replay collision on the shared admin secret (#338).
    test.setTimeout(360_000);
    const spent = await startMfaPendingSession(request);
    for (let attempt = 0; attempt < MFA_MAX_ATTEMPTS; attempt++) {
      await submitCode(request, spent, WRONG_CODE);
    }

    // The cap is a session cap, not an account lock — an admin who mistyped five
    // times signs in again rather than phoning someone.
    const fresh = await startMfaPendingSession(request);
    const response = await submitTotpWithRetry(TEST_CREDENTIALS.totp.adminSecret, (code) =>
      submitCode(request, fresh, code)
    );

    expect(response.status()).toBe(200);
    expect(await response.json()).toHaveProperty("message", "Login successful");
  });

  test("MFA without a pending session is reported as an expired sign-in", async ({ request }) => {
    const response = await request.post(`${API_BASE}/auth/mfa`, {
      data: { code: generateTotp(TEST_CREDENTIALS.totp.adminSecret) },
    });

    expect(response.status()).toBe(401);
    expect((await response.json()).error).toBe("mfa_session_expired");
  });
});
