import { test as setup } from '../../fixtures/auth.fixture';

const API_BASE = 'http://localhost:8080/api';

setup('seed walkthrough data', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
  // --- 1. Get existing products to use real product IDs ---
  const productsResp = await authenticatedRequest.get(`${API_BASE}/admin/products`);
  const productsData = await productsResp.json();
  const products = productsData.data || productsData;
  const activeProducts = products.filter((p: any) => p.is_active);

  if (activeProducts.length === 0) {
    throw new Error('No active products found — seed some products first');
  }

  // --- 2. Create 5 members with realistic names ---
  const memberNames = [
    { first: 'Thomas', last: 'Müller', email: 'thomas.mueller' },
    { first: 'Sandra', last: 'Weber', email: 'sandra.weber' },
    { first: 'Michael', last: 'Schmidt', email: 'michael.schmidt' },
    { first: 'Julia', last: 'Fischer', email: 'julia.fischer' },
    { first: 'Andreas', last: 'Wagner', email: 'andreas.wagner' },
  ];

  const memberIds: string[] = [];

  for (const m of memberNames) {
    const resp = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
      data: {
        first_name: m.first,
        last_name: m.last,
        email: `${m.email}@sportverein-demo.de`,
        preferred_language: 'de',
        iban: 'DE89370400440532013000',
        mandate_signed_at: '2025-06-15',
      },
    });

    if (resp.status() === 201) {
      const member = await resp.json();
      memberIds.push(member.id);
    } else {
      // Member may already exist from a previous run — skip
      console.log(`Skipping member ${m.first} ${m.last}: ${resp.status()}`);
    }
  }

  if (memberIds.length === 0) {
    // Try to fetch existing members instead
    const membersResp = await authenticatedRequest.get(`${API_BASE}/admin/members?per_page=10`);
    const membersData = await membersResp.json();
    const members = membersData.data || membersData;
    for (const m of members.slice(0, 5)) {
      memberIds.push(m.id);
    }
  }

  if (memberIds.length === 0) {
    throw new Error('No members available for seeding transactions');
  }

  // --- 3. Create ~20 transactions via sync API (simulating terminal usage) ---
  const generateUUID = () =>
    'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
      const r = (Math.random() * 16) | 0;
      return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });

  const transactions: any[] = [];
  const transactionIds: string[] = [];

  // Spread transactions across the last 30 days
  for (let i = 0; i < 20; i++) {
    const memberId = memberIds[i % memberIds.length];
    const product = activeProducts[i % activeProducts.length];
    const daysAgo = Math.floor(Math.random() * 30);
    const date = new Date();
    date.setDate(date.getDate() - daysAgo);
    date.setHours(17 + Math.floor(Math.random() * 5), Math.floor(Math.random() * 60));

    const txnId = generateUUID();
    transactionIds.push(txnId);

    transactions.push({
      id: txnId,
      member_id: memberId,
      type: 'product',
      product_id: product.id,
      quantity: 1,
      unit_price_cents: product.price_cents,
      amount_cents: product.price_cents,
      notes: '',
      created_at: date.toISOString(),
    });
  }

  // Send transactions in batches of 5 (sync API style)
  for (let i = 0; i < transactions.length; i += 5) {
    const batch = transactions.slice(i, i + 5);
    const resp = await authenticatedTerminalRequest.post(`${API_BASE}/sync/transactions`, {
      data: { transactions: batch },
    });

    if (resp.status() !== 201) {
      const error = await resp.text();
      console.log(`Transaction batch warning: ${resp.status()} — ${error}`);
    }
  }

  // --- 4. Create a settlement from the first 10 transactions ---
  const settlementTxnIds = transactionIds.slice(0, 10);
  if (settlementTxnIds.length > 0) {
    const today = new Date().toISOString().split('T')[0];
    const execDate = new Date();
    execDate.setDate(execDate.getDate() + 7);

    const resp = await authenticatedRequest.post(`${API_BASE}/admin/settlements`, {
      data: {
        settlement_type: 'sepa',
        transaction_ids: settlementTxnIds,
        settlement_date: today,
        execution_date: execDate.toISOString().split('T')[0],
        period_start: new Date(Date.now() - 30 * 86400000).toISOString().split('T')[0],
        period_end: today,
      },
    });

    if (resp.status() === 201) {
      console.log('Settlement created successfully');
    } else {
      const error = await resp.text();
      console.log(`Settlement warning: ${resp.status()} — ${error}`);
    }
  }

  console.log(`Walkthrough data seeded: ${memberIds.length} members, ${transactions.length} transactions`);
});
