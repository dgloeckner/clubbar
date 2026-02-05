/**
 * Audit Log E2E Tests
 *
 * End-to-end tests that verify audit log entries are created for CRUD operations
 * and that the UI correctly displays, filters, and expands them.
 *
 * Test Patterns:
 * - Pattern 001: Test Data Isolation (unique members/categories per test via timestamps)
 * - Pattern 003: Database-Agnostic Assertions (search by entity_id, not position)
 * - Pattern 004: Parallel Execution Safety (independent data per test)
 * - Pattern 005: Test IDs for element selection
 * - Pattern 006: Page Object Model (AuditLogPage)
 * - Pattern 008: Playwright Assertions & Auto-Waiting
 */

import { test, expect } from '../../fixtures/auth.fixture'
import { AuditLogPage } from '../../pages/AuditLogPage'

const API_BASE = 'http://localhost:8080/api'

test.describe('Audit Log E2E: CRUD Operations', () => {
  test('create member appears in audit log', async ({ page, testTransactions }) => {
    const auditLog = new AuditLogPage(page)
    let memberId: string
    let memberFirstName: string

    await test.step('Create test member', async () => {
      const ts = Date.now()
      const member = await testTransactions.createMember(`AuditM${ts}`, 'CreateTest')
      memberId = member.id
      memberFirstName = member.first_name
      expect(memberId).toBeTruthy()
    })

    await test.step('Navigate to audit log and filter', async () => {
      await auditLog.navigateTo()
      await auditLog.expectPageVisible()
      await auditLog.filterByAction('create')
      await auditLog.filterByEntityType('member')
      await auditLog.search(memberId!)
    })

    await test.step('Verify entry exists with correct values', async () => {
      await auditLog.expectEntryExists(memberId!)
      const row = auditLog.findRowByEntityId(memberId!)
      const entryId = await auditLog.getEntryIdFromRow(row.first())
      const cells = await auditLog.getRowCellValues(entryId)
      expect(cells.action).toBe('create')
      expect(cells.entityType).toBe('member')
      expect(cells.entityId).toContain(memberId!)
    })

    await test.step('Expand details and verify new_values', async () => {
      const row = auditLog.findRowByEntityId(memberId!)
      const entryId = await auditLog.getEntryIdFromRow(row.first())
      await auditLog.expandDetails(entryId)
      await auditLog.expectDetailsVisible(entryId)
      const newValues = await auditLog.getNewValues(entryId)
      expect(newValues).toContain(memberFirstName!)
    })
  })

  test('update member shows old and new values', async ({ page, authenticatedRequest, testTransactions }) => {
    const auditLog = new AuditLogPage(page)
    let memberId: string
    const originalLastName = 'OrigLName'
    const updatedLastName = `Updated${Date.now()}`

    await test.step('Create and update member', async () => {
      const ts = Date.now()
      const member = await testTransactions.createMember(`AuditUpd${ts}`, originalLastName)
      memberId = member.id

      // Update member's last name
      const patchResponse = await authenticatedRequest.patch(`${API_BASE}/admin/members/${memberId}`, {
        data: { last_name: updatedLastName },
      })
      expect(patchResponse.status()).toBe(200)
    })

    await test.step('Navigate and filter for update entry', async () => {
      await auditLog.navigateTo()
      await auditLog.expectPageVisible()
      await auditLog.filterByAction('update')
      await auditLog.filterByEntityType('member')
      await auditLog.search(memberId!)
    })

    await test.step('Verify old and new values in details', async () => {
      await auditLog.expectEntryExists(memberId!)
      const row = auditLog.findRowByEntityId(memberId!)
      const entryId = await auditLog.getEntryIdFromRow(row.first())
      await auditLog.expandDetails(entryId)
      await auditLog.expectDetailsVisible(entryId)

      const oldValues = await auditLog.getOldValues(entryId)
      const newValues = await auditLog.getNewValues(entryId)
      expect(oldValues).toContain(originalLastName)
      expect(newValues).toContain(updatedLastName)
    })
  })

  test('create category appears in audit log', async ({ page, authenticatedRequest }) => {
    const auditLog = new AuditLogPage(page)
    let categoryId: string
    const ts = Date.now()
    const catNameDe = `AuditKat${ts}`
    const catNameEn = `AuditCat${ts}`

    await test.step('Create category via API', async () => {
      const response = await authenticatedRequest.post(`${API_BASE}/admin/categories`, {
        data: { names: { de: catNameDe, en: catNameEn } },
      })
      expect(response.status()).toBe(201)
      const body = await response.json()
      categoryId = body.id
    })

    await test.step('Navigate and filter for category create', async () => {
      await auditLog.navigateTo()
      await auditLog.expectPageVisible()
      await auditLog.filterByAction('create')
      await auditLog.filterByEntityType('category')
      await auditLog.search(categoryId!)
    })

    await test.step('Verify entry and details', async () => {
      await auditLog.expectEntryExists(categoryId!)
      const row = auditLog.findRowByEntityId(categoryId!)
      const entryId = await auditLog.getEntryIdFromRow(row.first())
      const cells = await auditLog.getRowCellValues(entryId)
      expect(cells.action).toBe('create')
      expect(cells.entityType).toBe('category')

      await auditLog.expandDetails(entryId)
      const newValues = await auditLog.getNewValues(entryId)
      expect(newValues).toContain(catNameDe)
    })
  })

  test('create product appears in audit log', async ({ page, authenticatedRequest }) => {
    const auditLog = new AuditLogPage(page)
    let productId: string
    const ts = Date.now()
    const prodNameDe = `AuditProd${ts}`

    await test.step('Create category and product', async () => {
      const catResponse = await authenticatedRequest.post(`${API_BASE}/admin/categories`, {
        data: { names: { de: `APCat${ts}`, en: `APCat${ts}` } },
      })
      expect(catResponse.status()).toBe(201)
      const cat = await catResponse.json()

      const prodResponse = await authenticatedRequest.post(`${API_BASE}/admin/products`, {
        data: {
          names: { de: prodNameDe, en: `AuditProduct${ts}` },
          price_cents: 350,
          category_id: cat.id,
        },
      })
      expect(prodResponse.status()).toBe(201)
      const prod = await prodResponse.json()
      productId = prod.id
    })

    await test.step('Navigate and filter for product create', async () => {
      await auditLog.navigateTo()
      await auditLog.expectPageVisible()
      await auditLog.filterByAction('create')
      await auditLog.filterByEntityType('product')
      await auditLog.search(productId!)
    })

    await test.step('Verify entry and details', async () => {
      await auditLog.expectEntryExists(productId!)
      const row = auditLog.findRowByEntityId(productId!)
      const entryId = await auditLog.getEntryIdFromRow(row.first())

      await auditLog.expandDetails(entryId)
      const newValues = await auditLog.getNewValues(entryId)
      expect(newValues).toContain(prodNameDe)
      expect(newValues).toContain('350')
    })
  })
})

test.describe('Audit Log E2E: Filtering & UI', () => {
  test('filter by action narrows results', async ({ page, testTransactions }) => {
    const auditLog = new AuditLogPage(page)

    // Ensure at least one create entry exists
    await testTransactions.createMember(`AuditFilt${Date.now()}`, 'FilterTest')

    let initialCount: number

    await test.step('Navigate and get initial count', async () => {
      await auditLog.navigateTo()
      await auditLog.expectPageVisible()
      await auditLog.expectTableVisible()
      initialCount = await auditLog.getResultsCountNumber()
      expect(initialCount).toBeGreaterThan(0)
    })

    await test.step('Filter by create and verify narrowing', async () => {
      await auditLog.filterByAction('create')
      const filteredCount = await auditLog.getResultsCountNumber()
      expect(filteredCount).toBeLessThanOrEqual(initialCount!)
      expect(filteredCount).toBeGreaterThan(0)

      // Verify all visible rows show action=create
      const rowCount = await auditLog.getTableRowCount()
      for (let i = 0; i < Math.min(rowCount, 5); i++) {
        const action = await auditLog.getAction(i)
        expect(action).toBe('create')
      }
    })
  })

  test('filter by entity type narrows results', async ({ page, testTransactions }) => {
    const auditLog = new AuditLogPage(page)

    // Ensure a member entry exists
    await testTransactions.createMember(`AuditET${Date.now()}`, 'EntityTypeTest')

    await test.step('Navigate and filter by member', async () => {
      await auditLog.navigateTo()
      await auditLog.expectPageVisible()
      await auditLog.filterByEntityType('member')
    })

    await test.step('Verify all visible rows are member type', async () => {
      const rowCount = await auditLog.getTableRowCount()
      expect(rowCount).toBeGreaterThan(0)
      for (let i = 0; i < Math.min(rowCount, 5); i++) {
        const entityType = await auditLog.getEntityType(i)
        expect(entityType).toBe('member')
      }
    })
  })

  test('search by entity ID finds specific entry', async ({ page, testTransactions }) => {
    const auditLog = new AuditLogPage(page)
    let memberId: string

    await test.step('Create member to search for', async () => {
      const member = await testTransactions.createMember(`AuditSrch${Date.now()}`, 'SearchTest')
      memberId = member.id
    })

    await test.step('Navigate and search by entity ID', async () => {
      await auditLog.navigateTo()
      await auditLog.expectPageVisible()
      await auditLog.search(memberId!)
    })

    await test.step('Verify matching entry found', async () => {
      const rowCount = await auditLog.getTableRowCount()
      expect(rowCount).toBeGreaterThan(0)
      await auditLog.expectEntryExists(memberId!)
    })
  })

  test('expand and collapse details row', async ({ page, testTransactions }) => {
    const auditLog = new AuditLogPage(page)

    // Ensure at least one entry exists
    await testTransactions.createMember(`AuditExp${Date.now()}`, 'ExpandTest')

    await test.step('Navigate to audit log', async () => {
      await auditLog.navigateTo()
      await auditLog.expectPageVisible()
      await auditLog.expectTableVisible()
    })

    await test.step('Expand, verify visible, collapse, verify hidden', async () => {
      const entryId = await auditLog.getFirstEntryId()

      // Initially hidden
      await auditLog.expectDetailsHidden(entryId)

      // Expand
      await auditLog.expandDetails(entryId)
      await auditLog.expectDetailsVisible(entryId)

      // Collapse
      await auditLog.collapseDetails(entryId)
      await auditLog.expectDetailsHidden(entryId)
    })
  })

  test('admin name displayed correctly', async ({ page, testTransactions }) => {
    const auditLog = new AuditLogPage(page)
    let memberId: string

    await test.step('Create member to trigger audit entry', async () => {
      const member = await testTransactions.createMember(`AuditAdm${Date.now()}`, 'AdminNameTest')
      memberId = member.id
    })

    await test.step('Navigate and search for entry', async () => {
      await auditLog.navigateTo()
      await auditLog.expectPageVisible()
      await auditLog.search(memberId!)
    })

    await test.step('Verify admin column shows real name', async () => {
      await auditLog.expectEntryExists(memberId!)
      const row = auditLog.findRowByEntityId(memberId!)
      const entryId = await auditLog.getEntryIdFromRow(row.first())
      const cells = await auditLog.getRowCellValues(entryId)

      // Admin name should not be empty or the fallback "(Failed Login)"
      expect(cells.admin).toBeTruthy()
      expect(cells.admin).not.toBe('(Failed Login)')
    })
  })
})
