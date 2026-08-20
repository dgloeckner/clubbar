import { test as setup } from '../../fixtures/auth.fixture';

const API_BASE = 'http://localhost:8080/api';

setup('seed walkthrough data', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
  // --- 1. Get existing products to use real product IDs ---
  const productsResp = await authenticatedRequest.get(`${API_BASE}/admin/products`);
  const productsData = await productsResp.json();
  const activeProducts = (productsData.data ?? []).filter((p: any) => p.is_active);

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
        date_of_birth: '1985-06-15',
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

  // --- 2b. Create an inactive member ---
  const inactiveResp = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
    data: {
      first_name: 'Klaus',
      last_name: 'Bauer',
      email: 'klaus.bauer@sportverein-demo.de',
      date_of_birth: '1985-06-15',
      preferred_language: 'de',
      iban: 'DE27100777770209299700',
      mandate_signed_at: '2025-03-10',
    },
  });
  if (inactiveResp.status() === 201) {
    const inactiveMember = await inactiveResp.json();
    // Deactivate via PATCH
    await authenticatedRequest.patch(`${API_BASE}/admin/members/${inactiveMember.id}`, {
      data: { is_active: false },
    });
    console.log('Inactive member created: Klaus Bauer');
  } else {
    console.log(`Skipping inactive member Klaus Bauer: ${inactiveResp.status()}`);
  }

  // --- 2c. Create a member without SEPA (no IBAN, no mandate) ---
  const noSepaResp = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
    data: {
      first_name: 'Lisa',
      last_name: 'Hoffmann',
      email: 'lisa.hoffmann@sportverein-demo.de',
      date_of_birth: '1985-06-15',
      preferred_language: 'de',
    },
  });
  if (noSepaResp.status() === 201) {
    console.log('SEPA-missing member created: Lisa Hoffmann');
  } else {
    console.log(`Skipping SEPA-missing member Lisa Hoffmann: ${noSepaResp.status()}`);
  }

  if (memberIds.length === 0) {
    // Try to fetch existing members instead
    const membersResp = await authenticatedRequest.get(`${API_BASE}/admin/members?per_page=10`);
    const membersData = await membersResp.json();
    for (const m of (membersData.data ?? []).slice(0, 5)) {
      memberIds.push(m.id);
    }
  }

  if (memberIds.length === 0) {
    throw new Error('No members available for seeding transactions');
  }

  // --- 2d. Create an inactive product ---
  if (activeProducts.length > 0) {
    const categoryId = activeProducts[0].category_id;
    const inactiveProductResp = await authenticatedRequest.post(`${API_BASE}/admin/products`, {
      data: {
        names: { de: 'Altes Hausbier', en: 'Old House Beer' },
        price_cents: 280,
        category_id: categoryId,
        is_active: true,
      },
    });
    if (inactiveProductResp.status() === 201) {
      const inactiveProduct = await inactiveProductResp.json();
      // Deactivate via PATCH
      await authenticatedRequest.patch(`${API_BASE}/admin/products/${inactiveProduct.id}/status`, {
        data: { is_active: false },
      });
      console.log('Inactive product created: Altes Hausbier');
    } else {
      console.log(`Skipping inactive product: ${inactiveProductResp.status()}`);
    }
  }

  // --- 3. Ensure SEPA config is set (required for SEPA export) ---
  const sepaResp = await authenticatedRequest.put(`${API_BASE}/admin/sepa-config`, {
    data: {
      creditor_name: 'Sportverein Demo e.V.',
      creditor_iban: 'DE89370400440532013000',
      creditor_bic: 'COBADEFFXXX',
      creditor_id: 'DE98ZZZ09999999999',
      // #360/#456: SepaExportService also requires this now.
      mandate_template_url: 'https://club.example/anmeldung',
    },
  });
  console.log(`SEPA config: ${sepaResp.status()}`);

  // --- 4. Create ~80 transactions over the last 3 months (realistic bar usage) ---
  const generateUUID = () =>
    'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
      const r = (Math.random() * 16) | 0;
      return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });

  const transactions: any[] = [];

  // Spread ~80 transactions across 90 days with realistic patterns:
  // - More transactions on weekends (Fri/Sat)
  // - Varying quantities per member (some drink more)
  // - Mix of products (beverages dominate, some sauna)
  const TOTAL_TRANSACTIONS = 80;
  const DAYS_BACK = 90;

  for (let i = 0; i < TOTAL_TRANSACTIONS; i++) {
    const memberId = memberIds[i % memberIds.length];
    // Weight product selection: first few products (beverages) more likely
    const productIndex = Math.random() < 0.7
      ? Math.floor(Math.random() * Math.min(5, activeProducts.length))
      : Math.floor(Math.random() * activeProducts.length);
    const product = activeProducts[productIndex];

    const daysAgo = Math.floor(Math.random() * DAYS_BACK);
    const date = new Date();
    date.setDate(date.getDate() - daysAgo);
    // Bar hours: 17:00–22:00
    date.setHours(17 + Math.floor(Math.random() * 5), Math.floor(Math.random() * 60));

    const quantity = Math.random() < 0.8 ? 1 : Math.floor(Math.random() * 3) + 2;

    transactions.push({
      id: generateUUID(),
      member_id: memberId,
      type: 'product',
      product_id: product.id,
      quantity,
      unit_price_cents: product.price_cents,
      amount_cents: product.price_cents * quantity,
      notes: '',
      created_at: date.toISOString(),
    });
  }

  // Sort by date (oldest first) for realistic sync order
  transactions.sort((a, b) => new Date(a.created_at).getTime() - new Date(b.created_at).getTime());

  // Send transactions in batches of 10 (sync API style)
  for (let i = 0; i < transactions.length; i += 10) {
    const batch = transactions.slice(i, i + 10);
    const resp = await authenticatedTerminalRequest.post(`${API_BASE}/sync/transactions`, {
      data: { transactions: batch },
    });

    if (resp.status() !== 201) {
      const error = await resp.text();
      console.log(`Transaction batch warning: ${resp.status()} — ${error}`);
    }
  }

  // No pre-created settlement — the walkthrough will create one interactively

  console.log(`Walkthrough data seeded: ${memberIds.length} members, ${transactions.length} transactions`);
});
