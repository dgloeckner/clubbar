import { test, expect } from "../../fixtures/auth.fixture";

const API_BASE = "http://localhost:8080/api";

/**
 * Terminals API Tests
 *
 * Tests for Module 5: Terminal Device Management
 * Covers CRUD operations, token generation/rotation, and audit logging.
 *
 * Test Data Isolation (E2E Pattern 001):
 * - Each test creates its own unique test data
 * - Terminal names and device_ids use timestamps to prevent conflicts
 * - Enables safe parallel execution (4 workers)
 *
 * Database-Agnostic Assertions (E2E Pattern 003):
 * - Search for created terminals by ID, not position in list
 * - Verify presence of specific data, not list order
 * - Supports safe concurrent test execution
 */

test.describe("Terminals API", () => {
  // ========== TERMINAL CREATION ==========

  test("should create terminal with generated API token", async ({
    authenticatedRequest,
  }) => {
    const timestamp = Date.now();
    const response = await authenticatedRequest.post(
      `${API_BASE}/admin/terminals`,
      {
        data: {
          name: `Test Terminal ${timestamp}`,
          device_id: `test-device-${timestamp}`,
        },
      }
    );

    expect(response.status()).toBe(201);
    const data = await response.json();
    expect(data.terminal.name).toBe(`Test Terminal ${timestamp}`);
    expect(data.terminal.device_id).toBe(`test-device-${timestamp}`);
    expect(data.terminal.is_active).toBe(true);
    expect(data.api_token).toBeDefined();
    expect(data.api_token.length).toBe(64); // 256-bit entropy as hex
    expect(/^[a-f0-9]{64}$/.test(data.api_token)).toBe(true);
    expect(data.terminal).not.toHaveProperty("api_token_hash");
  });

  test("should return TerminalWithTokenDto on create (includes api_token)", async ({
    authenticatedRequest,
  }) => {
    const timestamp = Date.now();
    const response = await authenticatedRequest.post(
      `${API_BASE}/admin/terminals`,
      {
        data: {
          name: `Token Test ${timestamp}`,
          device_id: `device-token-${timestamp}`,
        },
      }
    );

    expect(response.status()).toBe(201);
    const data = await response.json();
    expect(data).toHaveProperty("terminal");
    expect(data).toHaveProperty("api_token");
    expect(data).toHaveProperty("message");
    expect(data.message).toContain("will not be shown again");
  });

  test("should validate name on create", async ({
    authenticatedRequest,
  }) => {
    const timestamp = Date.now();

    // Empty name
    const emptyResponse = await authenticatedRequest.post(
      `${API_BASE}/admin/terminals`,
      {
        data: {
          name: "",
          device_id: `device-${timestamp}`,
        },
      }
    );
    expect(emptyResponse.status()).toBe(422);

    // Name too long
    const longResponse = await authenticatedRequest.post(
      `${API_BASE}/admin/terminals`,
      {
        data: {
          name: "x".repeat(101),
          device_id: `device-${timestamp}`,
        },
      }
    );
    expect(longResponse.status()).toBe(422);
  });

  test("should reject duplicate device_id", async ({
    authenticatedRequest,
  }) => {
    const timestamp = Date.now();
    const deviceId = `unique-${timestamp}`;

    // Create first terminal
    const createResponse = await authenticatedRequest.post(
      `${API_BASE}/admin/terminals`,
      {
        data: {
          name: `First ${timestamp}`,
          device_id: deviceId,
        },
      }
    );
    expect(createResponse.status()).toBe(201);

    // Try to create duplicate
    const duplicateResponse = await authenticatedRequest.post(
      `${API_BASE}/admin/terminals`,
      {
        data: {
          name: `Second ${timestamp}`,
          device_id: deviceId,
        },
      }
    );
    expect(duplicateResponse.status()).toBe(422);
    const data = await duplicateResponse.json();
    expect(data.messages).toHaveProperty("device_id");
  });

  // ========== LIST TERMINALS ==========

  test("should list terminals with pagination", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/terminals?page=1&per_page=20`
    );

    expect(response.status()).toBe(200);
    const data = await response.json();
    expect(data).toHaveProperty("data");
    expect(data).toHaveProperty("pagination");
    expect(Array.isArray(data.data)).toBe(true);
    expect(data.pagination).toHaveProperty("page");
    expect(data.pagination).toHaveProperty("per_page");
    expect(data.pagination).toHaveProperty("total");
    expect(data.pagination).toHaveProperty("total_pages");
  });

  test("should filter terminals by is_active status", async ({
    authenticatedRequest,
  }) => {
    const timestamp = Date.now();
    const response = await authenticatedRequest.post(
      `${API_BASE}/admin/terminals`,
      {
        data: {
          name: `Active Test ${timestamp}`,
          device_id: `device-active-${timestamp}`,
        },
      }
    );

    expect(response.status()).toBe(201);
    const createData = await response.json();
    const terminalId = createData.terminal.id;

    // List active terminals
    const listResponse = await authenticatedRequest.get(
      `${API_BASE}/admin/terminals?is_active=true`
    );

    expect(listResponse.status()).toBe(200);
    const listData = await listResponse.json();
    const foundTerminal = listData.data.find((t: any) => t.id === terminalId);
    expect(foundTerminal).toBeDefined();
    expect(foundTerminal.is_active).toBe(true);
  });

  test("should not include api_token in list response", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/terminals?page=1&per_page=5`
    );

    expect(response.status()).toBe(200);
    const data = await response.json();
    data.data.forEach((terminal: any) => {
      expect(terminal).not.toHaveProperty("api_token");
      expect(terminal).not.toHaveProperty("api_token_hash");
    });
  });

  // ========== GET TERMINAL DETAILS ==========

  test("should get terminal by ID without api_token", async ({
    authenticatedRequest,
  }) => {
    const timestamp = Date.now();
    const createResponse = await authenticatedRequest.post(
      `${API_BASE}/admin/terminals`,
      {
        data: {
          name: `Detail Test ${timestamp}`,
          device_id: `device-detail-${timestamp}`,
        },
      }
    );

    expect(createResponse.status()).toBe(201);
    const createData = await createResponse.json();
    const terminalId = createData.terminal.id;

    const getResponse = await authenticatedRequest.get(
      `${API_BASE}/admin/terminals/${terminalId}`
    );

    expect(getResponse.status()).toBe(200);
    const data = await getResponse.json();
    expect(data.terminal.id).toBe(terminalId);
    expect(data.terminal).not.toHaveProperty("api_token");
    expect(data.terminal).not.toHaveProperty("api_token_hash");
  });

  test("should return 404 for nonexistent terminal", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/terminals/00000000-0000-0000-0000-000000000000`
    );

    expect(response.status()).toBe(404);
  });

  // ========== UPDATE TERMINAL ==========

  test("should update terminal name", async ({ authenticatedRequest }) => {
    const timestamp = Date.now();
    const createResponse = await authenticatedRequest.post(
      `${API_BASE}/admin/terminals`,
      {
        data: {
          name: `Original Name ${timestamp}`,
          device_id: `device-update-${timestamp}`,
        },
      }
    );

    const createData = await createResponse.json();
    const terminalId = createData.terminal.id;

    const updateResponse = await authenticatedRequest.patch(
      `${API_BASE}/admin/terminals/${terminalId}`,
      {
        data: {
          name: `Updated Name ${timestamp}`,
        },
      }
    );

    expect(updateResponse.status()).toBe(200);
    const updateData = await updateResponse.json();
    expect(updateData.terminal.name).toBe(`Updated Name ${timestamp}`);
    expect(updateData.terminal.id).toBe(terminalId);
  });

  test("should update terminal is_active status", async ({
    authenticatedRequest,
  }) => {
    const timestamp = Date.now();
    const createResponse = await authenticatedRequest.post(
      `${API_BASE}/admin/terminals`,
      {
        data: {
          name: `Status Test ${timestamp}`,
          device_id: `device-status-${timestamp}`,
        },
      }
    );

    const createData = await createResponse.json();
    const terminalId = createData.terminal.id;

    const updateResponse = await authenticatedRequest.patch(
      `${API_BASE}/admin/terminals/${terminalId}`,
      {
        data: {
          is_active: false,
        },
      }
    );

    expect(updateResponse.status()).toBe(200);
    const updateData = await updateResponse.json();
    expect(updateData.terminal.is_active).toBe(false);
  });

  test("should require at least one field to update", async ({
    authenticatedRequest,
  }) => {
    const timestamp = Date.now();
    const createResponse = await authenticatedRequest.post(
      `${API_BASE}/admin/terminals`,
      {
        data: {
          name: `No-Update Test ${timestamp}`,
          device_id: `device-noupdate-${timestamp}`,
        },
      }
    );

    const createData = await createResponse.json();
    const terminalId = createData.terminal.id;

    const updateResponse = await authenticatedRequest.patch(
      `${API_BASE}/admin/terminals/${terminalId}`,
      {
        data: {},
      }
    );

    expect(updateResponse.status()).toBe(422);
  });

  // ========== TOKEN ROTATION ==========

  test("should rotate terminal API token", async ({ authenticatedRequest }) => {
    const timestamp = Date.now();
    const createResponse = await authenticatedRequest.post(
      `${API_BASE}/admin/terminals`,
      {
        data: {
          name: `Rotate Test ${timestamp}`,
          device_id: `device-rotate-${timestamp}`,
        },
      }
    );

    const createData = await createResponse.json();
    const terminalId = createData.terminal.id;
    const oldToken = createData.api_token;

    const rotateResponse = await authenticatedRequest.post(
      `${API_BASE}/admin/terminals/${terminalId}/rotate-token`
    );

    expect(rotateResponse.status()).toBe(200);
    const rotateData = await rotateResponse.json();
    expect(rotateData.api_token).toBeDefined();
    expect(rotateData.api_token).not.toBe(oldToken);
    expect(rotateData.api_token.length).toBe(64);
    expect(/^[a-f0-9]{64}$/.test(rotateData.api_token)).toBe(true);
  });

  test("should return TerminalWithTokenDto on token rotation", async ({
    authenticatedRequest,
  }) => {
    const timestamp = Date.now();
    const createResponse = await authenticatedRequest.post(
      `${API_BASE}/admin/terminals`,
      {
        data: {
          name: `Rotate DTO Test ${timestamp}`,
          device_id: `device-rotate-dto-${timestamp}`,
        },
      }
    );

    const createData = await createResponse.json();
    const terminalId = createData.terminal.id;

    const rotateResponse = await authenticatedRequest.post(
      `${API_BASE}/admin/terminals/${terminalId}/rotate-token`
    );

    expect(rotateResponse.status()).toBe(200);
    const rotateData = await rotateResponse.json();
    expect(rotateData).toHaveProperty("terminal");
    expect(rotateData).toHaveProperty("api_token");
    expect(rotateData).toHaveProperty("message");
    expect(rotateData.message).toContain("rotated successfully");
  });

  // ========== REVOKE ACCESS & DELETE ==========

  test("should revoke terminal access and deactivate", async ({
    authenticatedRequest,
  }) => {
    const timestamp = Date.now();
    const createResponse = await authenticatedRequest.post(
      `${API_BASE}/admin/terminals`,
      {
        data: {
          name: `Revoke Test ${timestamp}`,
          device_id: `device-revoke-${timestamp}`,
        },
      }
    );

    const createData = await createResponse.json();
    const terminalId = createData.terminal.id;

    const revokeResponse = await authenticatedRequest.post(
      `${API_BASE}/admin/terminals/${terminalId}/revoke`
    );

    expect(revokeResponse.status()).toBe(200);

    // Verify terminal is deactivated and token removed
    const getResponse = await authenticatedRequest.get(
      `${API_BASE}/admin/terminals/${terminalId}`
    );
    const terminal = await getResponse.json();
    expect(terminal.terminal.is_active).toBe(false);
  });

  test("should delete (soft-delete) terminal", async ({
    authenticatedRequest,
  }) => {
    const timestamp = Date.now();
    const createResponse = await authenticatedRequest.post(
      `${API_BASE}/admin/terminals`,
      {
        data: {
          name: `Delete Test ${timestamp}`,
          device_id: `device-delete-${timestamp}`,
        },
      }
    );

    const createData = await createResponse.json();
    const terminalId = createData.terminal.id;

    const deleteResponse = await authenticatedRequest.delete(
      `${API_BASE}/admin/terminals/${terminalId}`
    );

    expect(deleteResponse.status()).toBe(200);

    // Verify terminal is deactivated (soft-delete)
    const getResponse = await authenticatedRequest.get(
      `${API_BASE}/admin/terminals/${terminalId}`
    );
    const terminal = await getResponse.json();
    expect(terminal.terminal.is_active).toBe(false);
  });

  // ========== AUTHENTICATION ==========

  test("should require authentication for all endpoints", async ({
    request,
  }) => {
    const response = await request.get(`${API_BASE}/admin/terminals`);
    expect(response.status()).toBe(401);

    const createResponse = await request.post(`${API_BASE}/admin/terminals`, {
      data: {
        name: "Test",
        device_id: "test",
      },
    });
    expect(createResponse.status()).toBe(401);
  });
});
