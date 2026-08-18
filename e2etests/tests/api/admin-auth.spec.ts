import { test, expect, APIRequestContext } from "@playwright/test";
import { readFileSync } from "fs";
import path from "path";
import { TEST_CREDENTIALS } from "../../config/test-credentials";
import { submitTotpWithRetry } from "../../utils/totp";
import { loginAs } from "../../utils/csrf";
import { stepUp } from "../../fixtures/stepUp";

/**
 * Serial within this file: these tests perform full password+MFA logins as the
 * shared seeded admin, and `totp_last_timestep` (#338) accepts one TOTP code
 * per 30-second step per account. Run 4-wide, the second login in a step is
 * correctly rejected as a replay and the test fails for a reason that has
 * nothing to do with what it is testing. E2E Pattern 004: a test that cannot
 * be made independent of a shared resource is run in order instead.
 */
test.describe.configure({ mode: 'default' })

const API_BASE = "http://localhost:8080/api";
// Same path auth.setup.ts writes its storageState to (see tests/auth.setup.ts).
const ADMIN_STORAGE_STATE_PATH = path.join("playwright", ".auth", "admin.json");

const ADMIN_EMAIL = TEST_CREDENTIALS.admin.email;
const ADMIN_PASSWORD = TEST_CREDENTIALS.admin.password;
const INVALID_PASSWORD = "wrongpassword";
const NONEXISTENT_EMAIL = "doesnotexist@example.com";

// The __Host- prefix (issue #251) only works on a Secure cookie, so the name
// follows the same scheme the suite runs on — https:// gets the prefixed
// name, http:// (this suite) keeps the plain one. See AppConfigTest.php for
// the derivation itself.
const EXPECTED_SESSION_COOKIE_NAME = API_BASE.startsWith("https://") ? "__Host-session" : "_session";

/**
 * Admin Authentication Tests
 *
 * Tests for Pattern 013: Admin Session Authentication
 * Validates login, logout, session management, and protected endpoint access.
 *
 * TOTP Note:
 * The seeded admin (admin@example.com) has TOTP pre-enrolled. Login therefore
 * returns { requiresMfa: true } rather than { message: "Login successful" }
 * directly. The login() helper below completes the MFA step automatically so
 * that other test sections can obtain a fully-authenticated session.
 */

test.describe("Admin Authentication", () => {
  /**
   * Fully authenticate as admin (including TOTP MFA) and return the session
   * cookie string and CSRF token needed for subsequent requests.
   *
   * Handles all three login outcomes:
   *  - requiresMfa: true  → complete MFA with TOTP code
   *  - requiresTotpSetup  → not expected for the seeded admin; throws
   *  - direct success     → use csrf_token from login response
   */
  async function login(
    request: APIRequestContext,
    email: string,
    password: string,
    totpSecret?: string,
  ): Promise<{ cookieString: string; csrfToken: string }> {
    const loginResponse = await request.post(`${API_BASE}/auth/login`, {
      data: { email, password },
    });

    // Extract session cookie (present on login response regardless of TOTP state)
    const setCookieHeader = loginResponse.headers()["set-cookie"];
    let cookieString = (Array.isArray(setCookieHeader)
      ? setCookieHeader[0]
      : setCookieHeader || "").split(";")[0];

    const loginData = await loginResponse.json();

    // TOTP MFA verification required
    if (loginData.requiresMfa) {
      const secret = totpSecret ?? TEST_CREDENTIALS.totp.adminSecret;

      // Retries against a same-window replay collision on the shared admin
      // secret (#338) — this helper is called repeatedly across this file's
      // tests, which run in quick succession and can land in the same window.
      test.setTimeout(360_000);
      const mfaResponse = await submitTotpWithRetry(secret, (code) =>
        request.post(`${API_BASE}/auth/mfa`, {
          data: { code },
          headers: { cookie: cookieString },
        })
      );

      if (!mfaResponse.ok()) {
        const body = await mfaResponse.text();
        throw new Error(`MFA verification failed (${mfaResponse.status()}): ${body}`);
      }

      // Session is regenerated after MFA — capture updated cookie
      const mfaSetCookie = mfaResponse.headers()["set-cookie"];
      if (mfaSetCookie) {
        const newCookie = (Array.isArray(mfaSetCookie) ? mfaSetCookie[0] : mfaSetCookie).split(";")[0];
        if (newCookie) cookieString = newCookie;
      }

      const mfaData = await mfaResponse.json();
      return { cookieString, csrfToken: mfaData.csrf_token || "" };
    }

    // First-time TOTP setup required — unexpected for the seeded admin
    if (loginData.requiresTotpSetup) {
      throw new Error(
        `login() called for an unenrolled user (${email}). ` +
          "Complete TOTP enrollment before using this helper, " +
          "or use loginAs() from utils/csrf.ts which handles enrollment automatically."
      );
    }

    // Plain login (no TOTP)
    return { cookieString, csrfToken: loginData.csrf_token || "" };
  }

  // Session shared by tests that only ever read through it (never logged out).
  // Reuses the ONE session "setup auth" (auth.setup.ts) already established
  // instead of performing another real password+MFA login — logging in as the
  // shared admin is a real TOTP exchange, and doing that on every one of this
  // file's tests would race replay protection (#338) for no reason when the
  // exact same session works for all of them. Tests that exercise logout call
  // login() directly instead, so they get their own (real, freshly-obtained) session.
  let cachedSession: Promise<{ cookieString: string; csrfToken: string }> | null = null;
  function loginCached(request: APIRequestContext): Promise<{ cookieString: string; csrfToken: string }> {
    if (!cachedSession) {
      cachedSession = (async () => {
        const stored = JSON.parse(readFileSync(ADMIN_STORAGE_STATE_PATH, "utf-8"));
        const sessionCookie = stored.cookies?.find((c: any) => c.name === "_session");
        if (!sessionCookie) {
          throw new Error(`Missing session cookie in ${ADMIN_STORAGE_STATE_PATH}`);
        }
        const cookieString = `${sessionCookie.name}=${sessionCookie.value}`;
        // The CSRF token is no longer persisted to localStorage (#109) — fetch a
        // fresh one from the session itself, same as the frontend does on boot.
        const profileResponse = await request.get(`${API_BASE}/auth/profile`, {
          headers: { cookie: cookieString },
        });
        if (!profileResponse.ok()) {
          throw new Error(`Failed to fetch CSRF token via /auth/profile (${profileResponse.status()})`);
        }
        const csrfToken = (await profileResponse.json()).csrf_token;
        if (!csrfToken) {
          throw new Error("No csrf_token in /auth/profile response");
        }
        return { cookieString, csrfToken };
      })();
    }
    return cachedSession;
  }

  test.describe("POST /api/auth/login", () => {
    test("should return requiresMfa for enrolled admin credentials", async ({
      request,
    }) => {
      const response = await request.post(`${API_BASE}/auth/login`, {
        data: {
          email: ADMIN_EMAIL,
          password: ADMIN_PASSWORD,
        },
      });

      expect(response.status()).toBe(200);

      const data = await response.json();
      // Seeded admin is pre-enrolled; login must not grant full session yet
      expect(data).toHaveProperty("requiresMfa", true);
      expect(data).not.toHaveProperty("message", "Login successful");

      // Session cookie must be set so the MFA step can be linked to this session
      const setCookieHeader = response.headers()["set-cookie"];
      expect(setCookieHeader).toBeTruthy();
      expect(setCookieHeader).toContain(EXPECTED_SESSION_COOKIE_NAME);
    });

    test("should reject login with invalid password", async ({ request }) => {
      const response = await request.post(`${API_BASE}/auth/login`, {
        data: {
          email: ADMIN_EMAIL,
          password: INVALID_PASSWORD,
        },
      });

      expect(response.status()).toBe(401);

      const data = await response.json();
      expect(data).toHaveProperty("error", "invalid_credentials");
      expect(data).toHaveProperty("message");
    });

    test("should reject login with nonexistent email", async ({ request }) => {
      const response = await request.post(`${API_BASE}/auth/login`, {
        data: {
          email: NONEXISTENT_EMAIL,
          password: ADMIN_PASSWORD,
        },
      });

      expect(response.status()).toBe(401);

      const data = await response.json();
      expect(data).toHaveProperty("error", "invalid_credentials");
    });
  });

  test.describe("POST /api/auth/mfa", () => {
    test("should complete login with valid TOTP code", async ({ request }) => {
      // Retries against a same-window replay collision on the shared admin secret (#338).
      test.setTimeout(360_000);

      // Step 1: Initial login
      const loginResponse = await request.post(`${API_BASE}/auth/login`, {
        data: { email: ADMIN_EMAIL, password: ADMIN_PASSWORD },
      });
      expect(loginResponse.status()).toBe(200);
      const loginData = await loginResponse.json();
      expect(loginData.requiresMfa).toBe(true);

      const setCookieHeader = loginResponse.headers()["set-cookie"];
      const cookieString = Array.isArray(setCookieHeader)
        ? setCookieHeader[0]
        : setCookieHeader || "";

      // Step 2: Complete MFA
      const mfaResponse = await submitTotpWithRetry(TEST_CREDENTIALS.totp.adminSecret, (code) =>
        request.post(`${API_BASE}/auth/mfa`, {
          data: { code },
          headers: { cookie: cookieString },
        })
      );

      expect(mfaResponse.status()).toBe(200);

      const mfaData = await mfaResponse.json();
      expect(mfaData).toHaveProperty("message", "Login successful");
      expect(mfaData.admin).toHaveProperty("id");
      expect(mfaData.admin).toHaveProperty("email", ADMIN_EMAIL);
      expect(mfaData.admin).toHaveProperty("display_name");
      expect(mfaData.admin).toHaveProperty("locale");
      expect(["de", "en"]).toContain(mfaData.admin.locale);
      expect(mfaData).toHaveProperty("csrf_token");
    });

    test("should reject invalid TOTP code with 401", async ({ request }) => {
      // Step 1: Initial login
      const loginResponse = await request.post(`${API_BASE}/auth/login`, {
        data: { email: ADMIN_EMAIL, password: ADMIN_PASSWORD },
      });
      const loginData = await loginResponse.json();
      expect(loginData.requiresMfa).toBe(true);

      const setCookieHeader = loginResponse.headers()["set-cookie"];
      const cookieString = Array.isArray(setCookieHeader)
        ? setCookieHeader[0]
        : setCookieHeader || "";

      // Step 2: Submit obviously wrong code
      const mfaResponse = await request.post(`${API_BASE}/auth/mfa`, {
        data: { code: "000000" },
        headers: { cookie: cookieString },
      });

      expect(mfaResponse.status()).toBe(401);
    });

    test("should reject MFA call without a pending login session", async ({
      request,
    }) => {
      const mfaResponse = await request.post(`${API_BASE}/auth/mfa`, {
        data: { code: "123456" },
        // No cookie — no pending session
      });

      expect(mfaResponse.status()).toBe(401);
    });
  });

  test.describe("POST /api/auth/logout", () => {
    test("should logout successfully with valid session", async ({
      request,
    }) => {
      const { cookieString, csrfToken } = await login(request, ADMIN_EMAIL, ADMIN_PASSWORD);

      const logoutResponse = await request.post(`${API_BASE}/auth/logout`, {
        headers: {
          cookie: cookieString,
          "X-CSRF-Token": csrfToken,
        },
      });

      expect(logoutResponse.status()).toBe(200);

      const data = await logoutResponse.json();
      expect(data).toHaveProperty("message", "Logout successful");
    });

    test("should reject logout without session", async ({ request }) => {
      const response = await request.post(`${API_BASE}/auth/logout`);

      expect(response.status()).toBe(401);

      const data = await response.json();
      expect(data).toHaveProperty("error", "admin_not_authenticated");
    });

    test("should invalidate session after logout", async ({ request }) => {
      const { cookieString, csrfToken } = await login(request, ADMIN_EMAIL, ADMIN_PASSWORD);

      // Logout
      await request.post(`${API_BASE}/auth/logout`, {
        headers: { cookie: cookieString, "X-CSRF-Token": csrfToken },
      });

      // Try to use the old session
      const profileResponse = await request.get(`${API_BASE}/auth/profile`, {
        headers: { cookie: cookieString },
      });

      expect(profileResponse.status()).toBe(401);
    });
  });

  test.describe("GET /api/auth/profile", () => {
    test("should return admin profile with valid session", async ({
      request,
    }) => {
      const { cookieString } = await loginCached(request);

      const profileResponse = await request.get(`${API_BASE}/auth/profile`, {
        headers: { cookie: cookieString },
      });

      expect(profileResponse.status()).toBe(200);

      const data = await profileResponse.json();
      expect(data).toHaveProperty("admin");
      expect(data.admin).toHaveProperty("id");
      expect(data.admin.id).toMatch(/^[0-9a-f-]{36}$/i);
      expect(data.admin).toHaveProperty("email", ADMIN_EMAIL);
      expect(data.admin).toHaveProperty("display_name");
      expect(data.admin).toHaveProperty("locale");
      expect(["de", "en"]).toContain(data.admin.locale);
      expect(data.admin).toHaveProperty("last_login_at");
    });

    test("should reject profile request without session", async ({
      request,
    }) => {
      const response = await request.get(`${API_BASE}/auth/profile`);

      expect(response.status()).toBe(401);

      const data = await response.json();
      expect(data).toHaveProperty("error", "admin_not_authenticated");
    });

    test("should reject profile request with invalid session", async ({
      request,
    }) => {
      const response = await request.get(`${API_BASE}/auth/profile`, {
        headers: {
          cookie: "clubbar_session=invalid_session_id",
        },
      });

      expect(response.status()).toBe(401);
    });
  });

  test.describe("Protected Admin Endpoints", () => {
    test("should require authentication for GET /api/admin/members", async ({
      request,
    }) => {
      const response = await request.get(`${API_BASE}/admin/members`);

      expect(response.status()).toBe(401);

      const data = await response.json();
      expect(data).toHaveProperty("error", "admin_not_authenticated");
    });

    test("should allow authenticated access to GET /api/admin/members", async ({
      request,
    }) => {
      const { cookieString } = await loginCached(request);

      const response = await request.get(`${API_BASE}/admin/members`, {
        headers: { cookie: cookieString },
      });

      expect(response.status()).toBe(200);

      const data = await response.json();
      expect(data).toHaveProperty("data");
      expect(Array.isArray(data.data)).toBe(true);
    });

    test("should require authentication for POST /api/admin/members", async ({
      request,
    }) => {
      const response = await request.post(`${API_BASE}/admin/members`, {
        data: {
          email: "newmember@example.com",
          first_name: "Test",
          last_name: "Member",
        },
      });

      expect(response.status()).toBe(401);
    });

    test("should require authentication for PATCH /api/admin/members/{id}", async ({
      request,
    }) => {
      const response = await request.patch(
        `${API_BASE}/admin/members/test-id`,
        {
          data: {
            first_name: "Updated",
          },
        }
      );

      expect(response.status()).toBe(401);
    });

    test("should require authentication for DELETE /api/admin/members/{id}", async ({
      request,
    }) => {
      const response = await request.delete(
        `${API_BASE}/admin/members/test-id`
      );

      expect(response.status()).toBe(401);
    });
  });

  test.describe("Session Management", () => {
    test("should maintain session across multiple requests", async ({
      request,
    }) => {
      const { cookieString } = await loginCached(request);

      // Make multiple requests with same session
      const profile1 = await request.get(`${API_BASE}/auth/profile`, {
        headers: { cookie: cookieString },
      });
      expect(profile1.status()).toBe(200);

      const members = await request.get(`${API_BASE}/admin/members`, {
        headers: { cookie: cookieString },
      });
      expect(members.status()).toBe(200);

      const profile2 = await request.get(`${API_BASE}/auth/profile`, {
        headers: { cookie: cookieString },
      });
      expect(profile2.status()).toBe(200);
    });

    test("should expire session after logout", async ({ request }) => {
      const { cookieString, csrfToken } = await login(request, ADMIN_EMAIL, ADMIN_PASSWORD);

      // Verify session works
      let profileResponse = await request.get(`${API_BASE}/auth/profile`, {
        headers: { cookie: cookieString },
      });
      expect(profileResponse.status()).toBe(200);

      // Logout
      const logoutResponse = await request.post(`${API_BASE}/auth/logout`, {
        headers: { cookie: cookieString, "X-CSRF-Token": csrfToken },
      });
      expect(logoutResponse.status()).toBe(200);

      // Verify session is invalid
      profileResponse = await request.get(`${API_BASE}/auth/profile`, {
        headers: { cookie: cookieString },
      });
      expect(profileResponse.status()).toBe(401);
    });
  });

  test.describe("Error Handling", () => {
    test("should return 404 for nonexistent endpoint", async ({ request }) => {
      const response = await request.get(
        `${API_BASE}/auth/nonexistent`
      );

      expect(response.status()).toBe(404);
    });
  });

  /**
   * PATCH /api/auth/profile (#100).
   *
   * The endpoint an admin uses to change their own display name, email and
   * UI language. It was only ever reached indirectly through the profile
   * page, so neither its validation nor the fact that it persists anything
   * was asserted at the API layer.
   *
   * Every test edits a throwaway admin's own profile, never the seeded
   * account other tests log in with (E2E Pattern 001) — a stray email change
   * there would lock the rest of the suite out.
   */
  test.describe("PATCH /api/auth/profile", () => {
    /**
     * Create a fresh admin and return a fully-logged-in context for it.
     *
     * Uses `loginCached` (the shared admin's already-established "setup auth"
     * session), not `login()`, to get privilege for the admin-users create
     * call below: this helper only needs a privileged session to mint a
     * throwaway admin, it doesn't exercise the login endpoint itself, so a
     * real password+MFA login here would just be another caller racing
     * `totp_last_timestep` (#338) for no reason — the same reasoning that
     * gave `loginCached` to the tests above.
     */
    async function freshAdminContext(
      request: APIRequestContext,
      playwright: Parameters<typeof loginAs>[0],
    ) {
      const { cookieString, csrfToken } = await loginCached(request);

      const suffix = `${Date.now()}${Math.random().toString(36).slice(2, 7)}`;
      const createResponse = await request.post(`${API_BASE}/admin/admin-users`, {
        headers: { cookie: cookieString, "X-CSRF-Token": csrfToken },
        data: {
          ...stepUp(),
          email: `profile-${suffix}@test.example.com`,
          display_name: "Profile Test Admin",
          locale: "de",
        },
      });
      expect(createResponse.status(), await createResponse.text()).toBe(201);
      const { admin, password } = await createResponse.json();

      const context = await loginAs(playwright, admin.email, password);
      return { admin, context };
    }

    /**
     * Email is deliberately not exercised here any more. Changing it moves the
     * login identifier and now needs a step-up — the caller's own password and
     * their own fresh TOTP code — and this context cannot produce one: `loginAs`
     * enrolls a fresh admin against a secret the server generated and then
     * discards it. The email path, both halves of it, is covered in
     * tests/api/self-service-credentials.spec.ts, which enrolls inline and keeps
     * the secret.
     *
     * What is worth keeping here is the other half of the contract: the fields
     * that are *not* credentials still update with no credential at all.
     */
    test("should update the caller's own display name and locale", async ({
      request,
      playwright,
    }) => {
      const { admin, context } = await freshAdminContext(request, playwright);

      try {
        const response = await context.patch(`${API_BASE}/auth/profile`, {
          data: {
            display_name: "Renamed Admin",
            locale: "en",
          },
        });

        expect(response.status(), await response.text()).toBe(200);
        const body = await response.json();
        expect(body.message).toBe("Profile updated");
        expect(body.admin.id).toBe(admin.id);
        expect(body.admin.display_name).toBe("Renamed Admin");
        expect(body.admin.locale).toBe("en");

        // Persisted, not just echoed back.
        const profile = await context.get(`${API_BASE}/auth/profile`);
        expect(profile.status()).toBe(200);
        const stored = (await profile.json()).admin;
        expect(stored.display_name).toBe("Renamed Admin");
        expect(stored.locale).toBe("en");
      } finally {
        await context.dispose();
      }
    });

    test("should reject an unsupported locale", async ({
      request,
      playwright,
    }) => {
      const { context } = await freshAdminContext(request, playwright);

      try {
        const response = await context.patch(`${API_BASE}/auth/profile`, {
          data: { locale: "fr" },
        });

        expect(response.status()).toBe(422);
        const body = await response.json();
        expect(body.error).toBe("validation_failed");
        expect(body.messages).toHaveProperty("locale");
      } finally {
        await context.dispose();
      }
    });

    test("should reject a malformed email", async ({ request, playwright }) => {
      const { admin, context } = await freshAdminContext(request, playwright);

      try {
        const response = await context.patch(`${API_BASE}/auth/profile`, {
          data: { email: "not-an-email" },
        });

        expect(response.status()).toBe(422);
        expect((await response.json()).messages).toHaveProperty("email");

        // The rejected value never reached the account.
        const stored = (await (await context.get(`${API_BASE}/auth/profile`)).json()).admin;
        expect(stored.email).toBe(admin.email);
      } finally {
        await context.dispose();
      }
    });

    test("should require authentication", async ({ request }) => {
      const response = await request.patch(`${API_BASE}/auth/profile`, {
        data: { display_name: "Nobody" },
      });

      expect([301, 302, 401, 403]).toContain(response.status());
    });
  });

  test.describe("Session Cookie", () => {
    test("login Set-Cookie includes Max-Age matching SESSION_MAX_AGE", async ({ playwright }) => {
      const ctx = await playwright.request.newContext()
      try {
        const resp = await ctx.post(`${API_BASE}/auth/login`, {
          data: {
            email: TEST_CREDENTIALS.admin.email,
            password: TEST_CREDENTIALS.admin.password,
          },
        })
        // Admin is TOTP-enrolled, so we get requiresMfa — but the cookie is still set
        const setCookie = resp.headers()["set-cookie"] ?? ""
        expect(setCookie).toMatch(/Max-Age=7200/i)
      } finally {
        await ctx.dispose()
      }
    })

    test("login Set-Cookie carries the hardening attributes", async ({ playwright }) => {
      const ctx = await playwright.request.newContext()
      try {
        const resp = await ctx.post(`${API_BASE}/auth/login`, {
          data: {
            email: TEST_CREDENTIALS.admin.email,
            password: TEST_CREDENTIALS.admin.password,
          },
        })
        const setCookie = resp.headers()["set-cookie"] ?? ""

        expect(setCookie).toMatch(new RegExp(`${EXPECTED_SESSION_COOKIE_NAME}=`))
        expect(setCookie).toMatch(/HttpOnly/i)
        expect(setCookie).toMatch(/SameSite=Lax/i)

        // Secure is derived (issue #105): on over HTTPS, off here — the suite
        // runs on http://localhost, where the browser drops a Secure cookie and
        // no session could be established at all. The derivation itself is
        // covered by backend/tests/Unit/Shared/Config/AppConfigTest.php.
        //
        // The cookie name follows the same scheme (issue #251): __Host- only
        // works on a Secure cookie, so the two must always agree.
        const overHttps = API_BASE.startsWith("https://")
        expect(/;\s*Secure/i.test(setCookie)).toBe(overHttps)
      } finally {
        await ctx.dispose()
      }
    })
  })
});
