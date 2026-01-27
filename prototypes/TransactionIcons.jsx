// ============================================
// Transaction Icon for frgs-admin.html
// ============================================

// Option 1: Inline style (matches existing icons in frgs-admin.html)
const TransactionIcon = () => (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <path d="M17 8l4-4-4-4"/><path d="M3 4h18"/>
    <path d="M7 16l-4 4 4 4"/><path d="M21 20H3"/>
  </svg>
);

// ============================================
// Copy this directly into frgs-admin.html:
// ============================================
/*

    const TransactionIcon = () => (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <path d="M17 8l4-4-4-4"/><path d="M3 4h18"/>
        <path d="M7 16l-4 4 4 4"/><path d="M21 20H3"/>
      </svg>
    );

*/

// ============================================
// Alternative designs:
// ============================================

// Option 2: Arrows with coin/circle (more financial feel)
const TransactionIconAlt1 = () => (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <circle cx="12" cy="12" r="3"/>
    <path d="M3 12h4"/><path d="M17 12h4"/>
    <path d="M5 9l-2 3 2 3"/><path d="M19 9l2 3-2 3"/>
  </svg>
);

// Option 3: Receipt with arrow (document transaction)
const TransactionIconAlt2 = () => (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
    <path d="M14 2v6h6"/><path d="M9 15l2 2 4-4"/>
  </svg>
);

// Option 4: Wallet with arrows
const TransactionIconAlt3 = () => (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <path d="M20 12V8H6a2 2 0 0 1-2-2c0-1.1.9-2 2-2h12v4"/>
    <path d="M4 6v12c0 1.1.9 2 2 2h14v-4"/>
    <path d="M18 12a2 2 0 0 0 0 4h4v-4z"/>
  </svg>
);

export { TransactionIcon, TransactionIconAlt1, TransactionIconAlt2, TransactionIconAlt3 };
export default TransactionIcon;
