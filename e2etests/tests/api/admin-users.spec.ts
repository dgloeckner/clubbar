import path from "path";
import { test, expect } from "../../fixtures/auth.fixture";
import { loginAs } from "../../utils/csrf";
import { TEST_CREDENTIALS } from "../../config/test-credentials";
import { generateTotp } from "../../utils/totp";
import { stepUp } from "../../fixtures/stepUp";
import { acceptInvitation, tokenFromInvitationUrl, INVITED_ADMIN_PASSWORD } from "../../utils/adminInvitation";

// Same path auth.setup.ts writes its storageState to (see tests/auth.setup.ts).
const ADMIN_STORAGE_STATE_PATH = path.join("playwright", ".auth", "admin.json");

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

/**
 * Admin Users API Tests
 *
 * Tests for Module 6: Admin User Management
 * Covers CRUD operations, password management, business rules, and audit logging.
 *
 * Test Data Isolation (E2E Pattern 001):
 * - Each test creates its own unique test data
 * - Seeded admin password never modified by tests
 * - Password-modifying tests use unique test admins
 * - Enables safe parallel execution
 */

test.describe("Admin Users API", () => {
  // ========== LIST ADMIN USERS ==========

  test("should list all admin users with pagination", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/admin-users?page=1&per_page=20`
    );

    expect(response.status()).toBe(200);
    const data = await response.json();
    expect(data).toHaveProperty("data");
    expect(data).toHaveProperty("pagination");
    expect(Array.isArray(data.data)).toBe(true);
  });

  test("should filter admin users by active status", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/admin-users?status=active&per_page=100`
    );

    expect(response.status()).toBe(200);
    const data = await response.json();
    expect(data.data.every((admin: any) => admin.is_active === true)).toBe(
      true
    );
  });

  test("should require authentication for list", async ({ request }) => {
    const response = await request.get(`${API_BASE}/admin/admin-users`);
    expect(response.status()).toBe(401);
  });

  // ========== CREATE ADMIN USER ==========

  test("should create new admin user and invite them, with no password in the response", async ({
    authenticatedRequest,
  }) => {
    const timestamp = Date.now();
    const response = await authenticatedRequest.post(
      `${API_BASE}/admin/admin-users`,
      {
        data: {
          ...stepUp(),
          email: `create-${timestamp}@test.example.com`,
          display_name: "Test Admin Create",
          locale: "de",
        },
      }
    );

    expect(response.status()).toBe(201);
    const data = await response.json();
    expect(data.admin.email).toBe(`create-${timestamp}@test.example.com`);
    expect(data.admin.is_active).toBe(true);
    // Migration 058: no password is generated at all. The account is written
    // with none, and the invitation link is what gives it one — so there is
    // nothing here for the creating admin to carry to their colleague.
    expect(data).not.toHaveProperty("password");
    expect(data.invitation.url).toContain("/invite#");
    expect(data.invitation.email).toBe(`create-${timestamp}@test.example.com`);
  });

  test("should reject duplicate email on create", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.post(
      `${API_BASE}/admin/admin-users`,
      {
        data: {
          ...stepUp(),
          email: "admin@example.com",
          display_name: "Duplicate",
          locale: "de",
        },
      }
    );

    expect(response.status()).toBe(422);
    const data = await response.json();
    expect(data.messages).toHaveProperty("email");
  });

  test("should validate required fields on create", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.post(
      `${API_BASE}/admin/admin-users`,
      { data: {} }
    );

    expect(response.status()).toBe(422);
  });

  test("should require authentication for create", async ({ request }) => {
    const response = await request.post(`${API_BASE}/admin/admin-users`, {
      data: {
        email: "test@example.com",
        display_name: "Test",
        locale: "de",
      },
    });

    expect(response.status()).toBe(401);
  });

  // CsrfMiddleware protects the whole /api/admin route group generically
  // (csrf-protection.spec.ts:17-59 only exercises /admin/categories), so this
  // pins that protection specifically to admin-user creation — a future
  // route reshuffle that accidentally drops admin-users out of the
  // CSRF-protected group would otherwise go uncaught (#504).
  test("should reject creating an admin user without a CSRF token", async ({
    playwright,
    authenticatedRequest,
  }) => {
    // Reuse the shared admin session cookie (set up once by auth.setup.ts)
    // without the CSRF-token-injecting wrapper authenticatedRequest applies,
    // so this request is authenticated but carries no X-CSRF-Token header.
    const sessionOnlyRequest = await playwright.request.newContext({
      baseURL: API_BASE,
      storageState: ADMIN_STORAGE_STATE_PATH,
    });

    const timestamp = Date.now();
    const email = `csrf-${timestamp}@test.example.com`;
    const response = await sessionOnlyRequest.post(
      `${API_BASE}/admin/admin-users`,
      {
        data: {
          ...stepUp(),
          email,
          display_name: "CSRF Test",
          locale: "de",
        },
      }
    );

    expect(response.status()).toBe(403);
    const data = await response.json();
    expect(data.error).toBe("csrf_validation_failed");

    await sessionOnlyRequest.dispose();

    // No state change: no admin user was created with this email.
    const listResponse = await authenticatedRequest.get(
      `${API_BASE}/admin/admin-users?per_page=100`
    );
    const listData = await listResponse.json();
    expect(listData.data.some((admin: any) => admin.email === email)).toBe(
      false
    );
  });

  // Creating an admin user mints a persistent, full-privilege peer account
  // (ADR-0015's flat admin model), so the caller must first re-prove their
  // own identity with a step-up credential (#499) — same shape as
  // reset-password and terminal enrolment.
  test("should reject creating an admin user without a step-up credential", async ({
    authenticatedRequest,
  }) => {
    const timestamp = Date.now();
    const response = await authenticatedRequest.post(
      `${API_BASE}/admin/admin-users`,
      {
        data: {
          email: `no-stepup-${timestamp}@test.example.com`,
          display_name: "No Step-Up",
          locale: "de",
        },
      }
    );

    // Missing current_password fails the Validator rule, not the step-up check.
    expect(response.status()).toBe(422);
  });

  test("should reject creating an admin user with a wrong step-up credential", async ({
    authenticatedRequest,
  }) => {
    const timestamp = Date.now();
    const response = await authenticatedRequest.post(
      `${API_BASE}/admin/admin-users`,
      {
        data: {
          ...stepUp({ password: "definitely-wrong-password" }),
          email: `wrong-stepup-${timestamp}@test.example.com`,
          display_name: "Wrong Step-Up",
          locale: "de",
        },
      }
    );

    expect(response.status()).toBe(401);
    const data = await response.json();
    expect(data.error).toBe("invalid_credentials");

    // No state change: no admin user was created with this email.
    const listResponse = await authenticatedRequest.get(
      `${API_BASE}/admin/admin-users?per_page=100`
    );
    const listData = await listResponse.json();
    expect(
      listData.data.some(
        (admin: any) => admin.email === `wrong-stepup-${timestamp}@test.example.com`
      )
    ).toBe(false);
  });

  // ========== GET SINGLE ADMIN USER ==========

  test("should get single admin user by ID", async ({
    authenticatedRequest,
  }) => {
    const listResponse = await authenticatedRequest.get(
      `${API_BASE}/admin/admin-users?page=1&per_page=1`
    );
    const listData = await listResponse.json();
    const adminId = listData.data[0].id;

    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/admin-users/${adminId}`
    );

    expect(response.status()).toBe(200);
    const data = await response.json();
    expect(data.admin.id).toBe(adminId);
    expect(data.admin).not.toHaveProperty("password_hash");
  });

  test("should return 404 for nonexistent admin", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/admin-users/00000000-0000-0000-0000-000000000000`
    );

    expect(response.status()).toBe(404);
  });

  // ========== UPDATE ADMIN USER ==========

  test("should update admin user fields", async ({ authenticatedRequest }) => {
    const timestamp = Date.now();
    const createResponse = await authenticatedRequest.post(
      `${API_BASE}/admin/admin-users`,
      {
        data: {
          ...stepUp(),
          email: `update-${timestamp}@test.example.com`,
          display_name: "Original Name",
          locale: "de",
        },
      }
    );

    const createData = await createResponse.json();
    const adminId = createData.admin.id;

    const updateResponse = await authenticatedRequest.patch(
      `${API_BASE}/admin/admin-users/${adminId}`,
      {
        data: {
          display_name: "Updated Name",
          locale: "en",
        },
      }
    );

    expect(updateResponse.status()).toBe(200);
    const updateData = await updateResponse.json();
    expect(updateData.admin.display_name).toBe("Updated Name");
    expect(updateData.admin.locale).toBe("en");
  });

  test("should validate email uniqueness on update", async ({
    authenticatedRequest,
  }) => {
    const timestamp = Date.now();
    const createResponse = await authenticatedRequest.post(
      `${API_BASE}/admin/admin-users`,
      {
        data: {
          ...stepUp(),
          email: `unique-${timestamp}@test.example.com`,
          display_name: "Test",
          locale: "de",
        },
      }
    );

    const createData = await createResponse.json();
    const adminId = createData.admin.id;

    const updateResponse = await authenticatedRequest.patch(
      `${API_BASE}/admin/admin-users/${adminId}`,
      {
        data: {
          email: "admin@example.com",
        },
      }
    );

    expect(updateResponse.status()).toBe(422);
  });

  test("should allow partial updates", async ({ authenticatedRequest }) => {
    const timestamp = Date.now();
    const createResponse = await authenticatedRequest.post(
      `${API_BASE}/admin/admin-users`,
      {
        data: {
          ...stepUp(),
          email: `partial-${timestamp}@test.example.com`,
          display_name: "Original",
          locale: "de",
        },
      }
    );

    const createData = await createResponse.json();
    const adminId = createData.admin.id;
    const originalEmail = createData.admin.email;

    const updateResponse = await authenticatedRequest.patch(
      `${API_BASE}/admin/admin-users/${adminId}`,
      {
        data: {
          display_name: "Updated Only",
        },
      }
    );

    expect(updateResponse.status()).toBe(200);
    const data = await updateResponse.json();
    expect(data.admin.email).toBe(originalEmail);
    expect(data.admin.display_name).toBe("Updated Only");
  });

  // ========== DEACTIVATE ADMIN USER ==========

  test("should deactivate admin user", async ({ authenticatedRequest }) => {
    const timestamp = Date.now();
    const createResponse = await authenticatedRequest.post(
      `${API_BASE}/admin/admin-users`,
      {
        data: {
          ...stepUp(),
          email: `deactivate-${timestamp}@test.example.com`,
          display_name: "To Deactivate",
          locale: "de",
        },
      }
    );

    const createData = await createResponse.json();
    const adminId = createData.admin.id;

    const deactivateResponse = await authenticatedRequest.delete(
      `${API_BASE}/admin/admin-users/${adminId}`
    );

    expect(deactivateResponse.status()).toBe(200);
    const data = await deactivateResponse.json();
    expect(data.admin.is_active).toBe(false);
  });

  test("should prevent self-deactivation", async ({ authenticatedRequest }) => {
    const profileResponse = await authenticatedRequest.get(
      `${API_BASE}/auth/profile`
    );
    const profileData = await profileResponse.json();
    const currentAdminId = profileData.admin.id;

    const response = await authenticatedRequest.delete(
      `${API_BASE}/admin/admin-users/${currentAdminId}`
    );

    expect(response.status()).toBe(409);
    const data = await response.json();
    expect(data.error).toBe("business_rule_violation");
  });

  // ========== REACTIVATE ADMIN USER ==========

  test("should reactivate deactivated admin user", async ({
    authenticatedRequest,
  }) => {
    const timestamp = Date.now();
    const createResponse = await authenticatedRequest.post(
      `${API_BASE}/admin/admin-users`,
      {
        data: {
          ...stepUp(),
          email: `reactivate-${timestamp}@test.example.com`,
          display_name: "To Reactivate",
          locale: "de",
        },
      }
    );

    const createData = await createResponse.json();
    const adminId = createData.admin.id;

    await authenticatedRequest.delete(`${API_BASE}/admin/admin-users/${adminId}`);

    const reactivateResponse = await authenticatedRequest.post(
      `${API_BASE}/admin/admin-users/${adminId}/reactivate`
    );

    expect(reactivateResponse.status()).toBe(200);
    const data = await reactivateResponse.json();
    expect(data.admin.is_active).toBe(true);
  });

  // ========== RESET PASSWORD ==========

  test("should reset admin password to new 16-char password", async ({
    authenticatedRequest,
  }) => {
    const timestamp = Date.now();
    const createResponse = await authenticatedRequest.post(
      `${API_BASE}/admin/admin-users`,
      {
        data: {
          ...stepUp(),
          email: `reset-${timestamp}@test.example.com`,
          display_name: "To Reset Password",
          locale: "de",
        },
      }
    );

    const createData = await createResponse.json();
    const adminId = createData.admin.id;

    // The account is invited rather than given a password (migration 058), so
    // this test walks the invitation first: a reset is only meaningful against
    // an account that has a password to replace.
    // Session-less, as an invitee's browser is: accepting ends the caller's
    // session (#798), and `authenticatedRequest` is the one the run shares.
    await acceptInvitation(createData.invitation.url);

    // The caller must first re-prove their own identity with a step-up
    // credential (#337): their own password, plus their own fresh TOTP code
    // since they have 2FA enabled.
    const resetResponse = await authenticatedRequest.post(
      `${API_BASE}/admin/admin-users/${adminId}/reset-password`,
      {
        data: {
          current_password: TEST_CREDENTIALS.admin.password,
          totp_code: generateTotp(TEST_CREDENTIALS.totp.adminSecret),
        },
      }
    );

    expect(resetResponse.status()).toBe(200);
    const data = await resetResponse.json();
    expect(data.password.length).toBe(16);
    expect(data.password).not.toBe(INVITED_ADMIN_PASSWORD);
  });

  test("should reject resetting an admin's password with a wrong step-up credential", async ({
    authenticatedRequest,
  }) => {
    const timestamp = Date.now();
    const createResponse = await authenticatedRequest.post(
      `${API_BASE}/admin/admin-users`,
      {
        data: {
          ...stepUp(),
          email: `reset-reject-${timestamp}@test.example.com`,
          display_name: "To Reset Password Reject",
          locale: "de",
        },
      }
    );

    const createData = await createResponse.json();
    const adminId = createData.admin.id;

    const resetResponse = await authenticatedRequest.post(
      `${API_BASE}/admin/admin-users/${adminId}/reset-password`,
      {
        data: {
          current_password: "definitely-wrong-password",
          totp_code: generateTotp(TEST_CREDENTIALS.totp.adminSecret),
        },
      }
    );

    expect(resetResponse.status()).toBe(401);
    const data = await resetResponse.json();
    expect(data.error).toBe("invalid_credentials");

    // No state change: the target's password was not reset.
    const showResponse = await authenticatedRequest.get(
      `${API_BASE}/admin/admin-users/${adminId}`
    );
    expect(showResponse.status()).toBe(200);
  });

  // ========== CHANGE OWN PASSWORD ==========
  //
  // Test Data Isolation: These tests create unique admin users to test password
  // changes, ensuring the shared seeded admin (admin@example.com) is never modified.
  // This enables safe parallel test execution.

  test("should reject incorrect current password", async ({
    authenticatedRequest,
    playwright,
  }) => {
    // Create unique test admin
    const timestamp = Date.now();
    const createResponse = await authenticatedRequest.post(
      `${API_BASE}/admin/admin-users`,
      {
        data: {
          ...stepUp(),
          email: `pwtest-${timestamp}@test.example.com`,
          display_name: "Password Test Admin",
          locale: "de",
        },
      }
    );
    expect(createResponse.status()).toBe(201);
    const { admin, invitation } = await createResponse.json();

    // The invitation is what gives the account a password (migration 058), and
    // the password is this test's own — nothing in the system ever knew it.
    // Session-less, as an invitee's browser is: accepting ends the caller's
    // session (#798), and `authenticatedRequest` is the one the run shares.
    await acceptInvitation(invitation.url);
    const password = INVITED_ADMIN_PASSWORD;

    // Login as the new admin (returns CSRF-aware context)
    const context = await loginAs(playwright, admin.email, password);

    // Test password change with incorrect current password
    const response = await context.patch(`${API_BASE}/auth/change-password`, {
      data: {
        current_password: "wrongpassword",
        new_password: "NewPass1234",
        new_password_confirmation: "NewPass1234",
      },
    });

    expect(response.status()).toBe(401);
    await context.dispose();
  });

  test("should validate new password complexity", async ({
    authenticatedRequest,
    playwright,
  }) => {
    // Create unique test admin
    const timestamp = Date.now();
    const createResponse = await authenticatedRequest.post(
      `${API_BASE}/admin/admin-users`,
      {
        data: {
          ...stepUp(),
          email: `pwtest-${timestamp}@test.example.com`,
          display_name: "Password Test Admin",
          locale: "de",
        },
      }
    );
    expect(createResponse.status()).toBe(201);
    const { admin, invitation } = await createResponse.json();

    // The invitation is what gives the account a password (migration 058), and
    // the password is this test's own — nothing in the system ever knew it.
    // Session-less, as an invitee's browser is: accepting ends the caller's
    // session (#798), and `authenticatedRequest` is the one the run shares.
    await acceptInvitation(invitation.url);
    const password = INVITED_ADMIN_PASSWORD;

    // Login as the new admin (returns CSRF-aware context)
    const context = await loginAs(playwright, admin.email, password);

    // Test password change with weak password (no uppercase)
    const response = await context.patch(`${API_BASE}/auth/change-password`, {
      data: {
        current_password: password,
        new_password: "lowercase1234", // No uppercase
        new_password_confirmation: "lowercase1234",
      },
    });

    expect(response.status()).toBe(422);
    await context.dispose();
  });

  test("should validate new password minimum length", async ({
    authenticatedRequest,
    playwright,
  }) => {
    // Create unique test admin
    const timestamp = Date.now();
    const createResponse = await authenticatedRequest.post(
      `${API_BASE}/admin/admin-users`,
      {
        data: {
          ...stepUp(),
          email: `pwtest-${timestamp}@test.example.com`,
          display_name: "Password Test Admin",
          locale: "de",
        },
      }
    );
    expect(createResponse.status()).toBe(201);
    const { admin, invitation } = await createResponse.json();

    // The invitation is what gives the account a password (migration 058), and
    // the password is this test's own — nothing in the system ever knew it.
    // Session-less, as an invitee's browser is: accepting ends the caller's
    // session (#798), and `authenticatedRequest` is the one the run shares.
    await acceptInvitation(invitation.url);
    const password = INVITED_ADMIN_PASSWORD;

    // Login as the new admin (returns CSRF-aware context)
    const context = await loginAs(playwright, admin.email, password);

    // Test password change with short password
    const response = await context.patch(`${API_BASE}/auth/change-password`, {
      data: {
        current_password: password,
        new_password: "Pass1", // Too short
        new_password_confirmation: "Pass1",
      },
    });

    expect(response.status()).toBe(422);
    await context.dispose();
  });

  test("should require authentication for change password", async ({
    request,
  }) => {
    const response = await request.patch(`${API_BASE}/auth/change-password`, {
      data: {
        current_password: "password123",
        new_password: "NewPass1234",
        new_password_confirmation: "NewPass1234",
      },
    });

    expect(response.status()).toBe(401);
  });
});
