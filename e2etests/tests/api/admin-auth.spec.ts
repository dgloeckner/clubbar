import { test, expect, APIRequestContext } from "@playwright/test";

const API_BASE = "http://localhost:8080/api";

// Test credentials
const ADMIN_EMAIL = "admin@example.com";
const ADMIN_PASSWORD = "password123";
const INVALID_PASSWORD = "wrongpassword";
const NONEXISTENT_EMAIL = "doesnotexist@example.com";

/**
 * Admin Authentication Tests
 *
 * Tests for Pattern 013: Admin Session Authentication
 * Validates login, logout, session management, and protected endpoint access.
 */

test.describe("Admin Authentication", () => {
  // Helper function to login and return cookies
  async function login(
    request: APIRequestContext,
    email: string,
    password: string
  ): Promise<string> {
    const loginResponse = await request.post(`${API_BASE}/auth/login`, {
      data: { email, password },
    });

    // Extract session cookie from Set-Cookie header
    const setCookieHeader = loginResponse.headers()["set-cookie"];
    const cookieString = Array.isArray(setCookieHeader)
      ? setCookieHeader[0]
      : setCookieHeader || "";

    return cookieString;
  }

  test.describe("POST /api/auth/login", () => {
    test("should login successfully with correct credentials", async ({
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
      expect(data).toHaveProperty("message", "Login successful");
      expect(data.admin).toHaveProperty("id");
      expect(data.admin).toHaveProperty("email", ADMIN_EMAIL);
      expect(data.admin).toHaveProperty("display_name");
      expect(data.admin).toHaveProperty("locale"); // mutable by i18n tests — don't assert specific value
      expect(["de", "en"]).toContain(data.admin.locale); // but must be a valid locale

      // Verify session cookie is set
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

  test.describe("POST /api/auth/logout", () => {
    test("should logout successfully with valid session", async ({
      request,
    }) => {
      // First login
      const loginResponse = await request.post(`${API_BASE}/auth/login`, {
        data: {
          email: ADMIN_EMAIL,
          password: ADMIN_PASSWORD,
        },
      });

      const setCookieHeader = loginResponse.headers()["set-cookie"];
      const cookieString = Array.isArray(setCookieHeader)
        ? setCookieHeader[0]
        : setCookieHeader;

      // Then logout with session
      const logoutResponse = await request.post(`${API_BASE}/auth/logout`, {
        headers: {
          cookie: cookieString,
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
      // Login
      const loginResponse = await request.post(`${API_BASE}/auth/login`, {
        data: {
          email: ADMIN_EMAIL,
          password: ADMIN_PASSWORD,
        },
      });

      const setCookieHeader = loginResponse.headers()["set-cookie"];
      const cookieString = Array.isArray(setCookieHeader)
        ? setCookieHeader[0]
        : setCookieHeader;

      // Logout
      await request.post(`${API_BASE}/auth/logout`, {
        headers: {
          cookie: cookieString,
        },
      });

      // Try to use the old session
      const profileResponse = await request.get(`${API_BASE}/auth/profile`, {
        headers: {
          cookie: cookieString,
        },
      });

      expect(profileResponse.status()).toBe(401);
    });
  });

  test.describe("GET /api/auth/profile", () => {
    test("should return admin profile with valid session", async ({
      request,
    }) => {
      // Login
      const loginResponse = await request.post(`${API_BASE}/auth/login`, {
        data: {
          email: ADMIN_EMAIL,
          password: ADMIN_PASSWORD,
        },
      });

      const setCookieHeader = loginResponse.headers()["set-cookie"];
      const cookieString = Array.isArray(setCookieHeader)
        ? setCookieHeader[0]
        : setCookieHeader;

      // Get profile
      const profileResponse = await request.get(`${API_BASE}/auth/profile`, {
        headers: {
          cookie: cookieString,
        },
      });

      expect(profileResponse.status()).toBe(200);

      const data = await profileResponse.json();
      expect(data).toHaveProperty("admin");
      expect(data.admin).toHaveProperty("id", "33e4567-e89b-12d3-a456-426614174000");
      expect(data.admin).toHaveProperty("email", ADMIN_EMAIL);
      expect(data.admin).toHaveProperty("display_name"); // mutable by profile tests — don't assert specific value
      expect(data.admin).toHaveProperty("locale"); // mutable by i18n tests — don't assert specific value
      expect(["de", "en"]).toContain(data.admin.locale); // but must be a valid locale
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
          cookie: "ruderbar_session=invalid_session_id",
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
      // Login
      const loginResponse = await request.post(`${API_BASE}/auth/login`, {
        data: {
          email: ADMIN_EMAIL,
          password: ADMIN_PASSWORD,
        },
      });

      const setCookieHeader = loginResponse.headers()["set-cookie"];
      const cookieString = Array.isArray(setCookieHeader)
        ? setCookieHeader[0]
        : setCookieHeader;

      // Access protected endpoint
      const response = await request.get(`${API_BASE}/admin/members`, {
        headers: {
          cookie: cookieString,
        },
      });

      expect(response.status()).toBe(200);

      const data = await response.json();
      expect(data).toHaveProperty("items");
      expect(Array.isArray(data.items)).toBe(true);
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
      // Login
      const loginResponse = await request.post(`${API_BASE}/auth/login`, {
        data: {
          email: ADMIN_EMAIL,
          password: ADMIN_PASSWORD,
        },
      });

      const setCookieHeader = loginResponse.headers()["set-cookie"];
      const cookieString = Array.isArray(setCookieHeader)
        ? setCookieHeader[0]
        : setCookieHeader;

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

      // All requests should succeed with the same session
      expect(profile1.status()).toBe(200);
      expect(members.status()).toBe(200);
      expect(profile2.status()).toBe(200);
    });

    test("should expire session after logout", async ({ request }) => {
      // Login
      const loginResponse = await request.post(`${API_BASE}/auth/login`, {
        data: {
          email: ADMIN_EMAIL,
          password: ADMIN_PASSWORD,
        },
      });

      const setCookieHeader = loginResponse.headers()["set-cookie"];
      const cookieString = Array.isArray(setCookieHeader)
        ? setCookieHeader[0]
        : setCookieHeader;

      // Verify session works
      let profileResponse = await request.get(`${API_BASE}/auth/profile`, {
        headers: { cookie: cookieString },
      });
      expect(profileResponse.status()).toBe(200);

      // Logout
      const logoutResponse = await request.post(`${API_BASE}/auth/logout`, {
        headers: { cookie: cookieString },
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
});
