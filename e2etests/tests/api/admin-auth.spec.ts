import { test, expect, APIRequestContext } from "@playwright/test";
import { TEST_CREDENTIALS } from "../../config/test-credentials";
import { generateTotp } from "../../utils/totp";
import { loginAs } from "../../utils/csrf";

const API_BASE = "http://localhost:8080/api";

const ADMIN_EMAIL = TEST_CREDENTIALS.admin.email;
const ADMIN_PASSWORD = TEST_CREDENTIALS.admin.password;
const INVALID_PASSWORD = "wrongpassword";
const NONEXISTENT_EMAIL = "doesnotexist@example.com";

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
      const code = generateTotp(secret);

      const mfaResponse = await request.post(`${API_BASE}/auth/mfa`, {
        data: { code },
        headers: { cookie: cookieString },
      });

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
      expect(setCookieHeader).toContain("_session");
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
      const code = generateTotp(TEST_CREDENTIALS.totp.adminSecret);
      const mfaResponse = await request.post(`${API_BASE}/auth/mfa`, {
        data: { code },
        headers: { cookie: cookieString },
      });

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
      const { cookieString } = await login(request, ADMIN_EMAIL, ADMIN_PASSWORD);

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
      const { cookieString } = await login(request, ADMIN_EMAIL, ADMIN_PASSWORD);

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
      const { cookieString } = await login(request, ADMIN_EMAIL, ADMIN_PASSWORD);

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
    /** Create a fresh admin and return a fully-logged-in context for it. */
    async function freshAdminContext(
      request: APIRequestContext,
      playwright: Parameters<typeof loginAs>[0],
    ) {
      const { cookieString, csrfToken } = await login(
        request,
        ADMIN_EMAIL,
        ADMIN_PASSWORD,
      );

      const suffix = `${Date.now()}${Math.random().toString(36).slice(2, 7)}`;
      const createResponse = await request.post(`${API_BASE}/admin/admin-users`, {
        headers: { cookie: cookieString, "X-CSRF-Token": csrfToken },
        data: {
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

    test("should update the caller's own display name, email and locale", async ({
      request,
      playwright,
    }) => {
      const { admin, context } = await freshAdminContext(request, playwright);
      const newEmail = `renamed-${Date.now()}@test.example.com`;

      try {
        const response = await context.patch(`${API_BASE}/auth/profile`, {
          data: {
            display_name: "Renamed Admin",
            email: newEmail,
            locale: "en",
          },
        });

        expect(response.status(), await response.text()).toBe(200);
        const body = await response.json();
        expect(body.message).toBe("Profile updated");
        expect(body.admin.id).toBe(admin.id);
        expect(body.admin.display_name).toBe("Renamed Admin");
        expect(body.admin.email).toBe(newEmail);
        expect(body.admin.locale).toBe("en");

        // Persisted, not just echoed back.
        const profile = await context.get(`${API_BASE}/auth/profile`);
        expect(profile.status()).toBe(200);
        const stored = (await profile.json()).admin;
        expect(stored.display_name).toBe("Renamed Admin");
        expect(stored.email).toBe(newEmail);
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

        expect(setCookie).toMatch(/_session=/)
        expect(setCookie).toMatch(/HttpOnly/i)
        expect(setCookie).toMatch(/SameSite=Lax/i)

        // Secure is derived (issue #105): on over HTTPS, off here — the suite
        // runs on http://localhost, where the browser drops a Secure cookie and
        // no session could be established at all. The derivation itself is
        // covered by backend/tests/Unit/Shared/Config/AppConfigTest.php.
        const overHttps = API_BASE.startsWith("https://")
        expect(/;\s*Secure/i.test(setCookie)).toBe(overHttps)
      } finally {
        await ctx.dispose()
      }
    })
  })
});
