import { randomUUID } from 'crypto';
import { test, expect } from '../../fixtures/auth.fixture';
import type { APIRequestContext } from '@playwright/test';

/**
 * Admin Transactions - Text Search Tests
 *
 * Tests the search filter on GET /api/admin/transactions endpoint.
 * Verifies that search matches product names in addition to member names and notes.
 *
 * Uses E2E Pattern 001: Test Data Isolation
 * Uses E2E Pattern 002: Authentication Isolation
 * Uses E2E Pattern 003: Database-Agnostic Assertions (find by ID)
 */

// Helper to create a member via admin API
async function createMember(authenticatedRequest: APIRequestContext, lastNameSuffix?: string) {
  const uniqueId = randomUUID().substring(0, 8);
  const mandateDate = new Date().toISOString().slice(0, 10);
  const lastName = lastNameSuffix ? `Member${lastNameSuffix}` : `Member${uniqueId}`;

  const response = await authenticatedRequest.post('/api/admin/members', {
    data: {
      first_name: `Search${uniqueId}`,
      last_name: lastName,
      email: `search${uniqueId}@example.com`,
      iban: 'DE89370400440532013000',
      mandate_signed_at: mandateDate,
      preferred_language: 'de',
    },
  });

  if (!response.ok()) {
    throw new Error(`Failed to create member: ${response.status()} - ${await response.text()}`);
  }
  return await response.json();
}

// Helper to create a category via admin API
async function createCategory(authenticatedRequest: APIRequestContext) {
  const uniqueId = randomUUID().substring(0, 8);
  const response = await authenticatedRequest.post('/api/admin/categories', {
    data: {
      names: { de: `Kategorie ${uniqueId}`, en: `Category ${uniqueId}` },
    },
  });

  if (!response.ok()) {
    throw new Error(`Failed to create category: ${response.status()} - ${await response.text()}`);
  }
  return await response.json();
}

// Helper to create a product with a specific name via admin API
async function createProduct(authenticatedRequest: APIRequestContext, categoryId: string, productName: string) {
  const response = await authenticatedRequest.post('/api/admin/products', {
    data: {
      names: { de: productName, en: productName },
      price_cents: 350,
      category_id: categoryId,
    },
  });

  if (!response.ok()) {
    throw new Error(`Failed to create product: ${response.status()} - ${await response.text()}`);
  }
  return await response.json();
}

// Helper to submit a transaction via terminal sync API
async function submitTransaction(
  authenticatedTerminalRequest: APIRequestContext,
  memberId: string,
  productId: string,
) {
  const transaction = {
    id: randomUUID(),
    member_id: memberId,
    product_id: productId,
    amount_cents: 350,
    created_at: new Date().toISOString(),
  };

  const response = await authenticatedTerminalRequest.post('/api/sync/transactions', {
    data: { transactions: [transaction] },
  });

  if (!response.ok()) {
    throw new Error(`Failed to submit transaction: ${response.status()} - ${await response.text()}`);
  }

  return transaction;
}

test.describe('Admin Transactions - Text Search', () => {
  test('GET /api/admin/transactions?search= finds transactions by product name', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    // Pattern 001: Create unique test data with a distinctive product name
    const uniqueId = randomUUID().substring(0, 8);
    const productName = `Schinkenbrot${uniqueId}`;

    const member = await createMember(authenticatedRequest);
    const category = await createCategory(authenticatedRequest);
    const product = await createProduct(authenticatedRequest, category.id, productName);
    const transaction = await submitTransaction(authenticatedTerminalRequest, member.id, product.id);

    // Search by product name
    const response = await authenticatedRequest.get('/api/admin/transactions', {
      params: { search: productName },
    });

    expect(response.ok()).toBeTruthy();
    expect(response.status()).toBe(200);

    const body = await response.json();

    // Pattern 003: Find by transaction ID, not by position
    const found = body.items.find((tx: any) => tx.id === transaction.id);

    // E2E verification: transaction with matching product name must appear in results
    expect(found).toBeDefined();
    expect(found.id).toBe(transaction.id);
  });

  test('GET /api/admin/transactions?search= still finds transactions by member name', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    // Pattern 001: Create unique test data with a known last name suffix
    const uniqueId = randomUUID().substring(0, 8);

    const member = await createMember(authenticatedRequest, uniqueId);
    const category = await createCategory(authenticatedRequest);
    const product = await createProduct(authenticatedRequest, category.id, `Produkt${uniqueId}`);
    const transaction = await submitTransaction(authenticatedTerminalRequest, member.id, product.id);

    // Search by member last name
    const response = await authenticatedRequest.get('/api/admin/transactions', {
      params: { search: `Member${uniqueId}` },
    });

    expect(response.ok()).toBeTruthy();

    const body = await response.json();

    // Pattern 003: Find by transaction ID
    const found = body.items.find((tx: any) => tx.id === transaction.id);
    expect(found).toBeDefined();
  });

  test('GET /api/admin/transactions?search= returns empty results for non-matching product name', async ({
    authenticatedRequest,
  }) => {
    // Search for a product name that does not exist
    const nonExistentName = `NoSuchProduct${randomUUID()}`;

    const response = await authenticatedRequest.get('/api/admin/transactions', {
      params: { search: nonExistentName },
    });

    expect(response.ok()).toBeTruthy();

    const body = await response.json();
    expect(body.items).toBeDefined();
    expect(body.total).toBe(0);
    expect(body.items.length).toBe(0);
  });
});
