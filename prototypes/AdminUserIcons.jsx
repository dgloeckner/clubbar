// ============================================
// Admin User Icons for frgs-admin.html
// ============================================

// Option 1: Shield (recommended - security/protection feel)
const AdminUserIcon = () => (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <path d="M8 18c-3 0-6 1.5-6 3v2h12v-2c0-1.5-3-3-6-3z"/>
    <circle cx="8" cy="12" r="4"/>
    <path d="M16 4l4 2v4c0 3-2 5.5-4 6.5-2-1-4-3.5-4-6.5V6l4-2z"/>
    <path d="M16 9l-1.5 1.5 3 3" strokeWidth="1.5"/>
  </svg>
);

// Option 2: Gear (settings/control feel)
const AdminGearIcon = () => (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <path d="M8 18c-3 0-6 1.5-6 3v2h12v-2c0-1.5-3-3-6-3z"/>
    <circle cx="8" cy="12" r="4"/>
    <circle cx="18" cy="8" r="2"/>
    <path d="M18 4v1m0 6v1m-3-4.5h1m6 0h1m-6.25-2.75l.75.75m3.5 3.5l.75.75m-5 0l.75-.75m3.5-3.5l.75-.75"/>
  </svg>
);

// Option 3: Crown (royalty/authority feel)
const AdminCrownIcon = () => (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <path d="M12 19c-3 0-6 1.5-6 3v2h12v-2c0-1.5-3-3-6-3z"/>
    <circle cx="12" cy="13" r="4"/>
    <path d="M6 4l2 4 4-2 4 2 2-4v6H6V4z"/>
  </svg>
);

// Option 4: Star (VIP/special status)
const AdminStarIcon = () => (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <path d="M8 18c-3 0-6 1.5-6 3v2h12v-2c0-1.5-3-3-6-3z"/>
    <circle cx="8" cy="12" r="4"/>
    <path d="M18 3l1 2 2.25 0.25-1.625 1.625 0.375 2.25L18 8l-2 1.125 0.375-2.25-1.625-1.625L17 5l1-2z"/>
  </svg>
);

// Option 5: Key (access/permissions feel)
const AdminKeyIcon = () => (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <path d="M8 18c-3 0-6 1.5-6 3v2h12v-2c0-1.5-3-3-6-3z"/>
    <circle cx="8" cy="12" r="4"/>
    <circle cx="19" cy="5" r="2.5"/>
    <path d="M17.25 6.75L13 11l1 1-1 1 1 1 3-3"/>
  </svg>
);

export { AdminUserIcon, AdminGearIcon, AdminCrownIcon, AdminStarIcon, AdminKeyIcon };
export default AdminUserIcon;


// ============================================
// Copy directly into frgs-admin.html:
// ============================================
/*

    // Option 1: Shield (recommended)
    const AdminUserIcon = () => (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <path d="M8 18c-3 0-6 1.5-6 3v2h12v-2c0-1.5-3-3-6-3z"/>
        <circle cx="8" cy="12" r="4"/>
        <path d="M16 4l4 2v4c0 3-2 5.5-4 6.5-2-1-4-3.5-4-6.5V6l4-2z"/>
        <path d="M16 9l-1.5 1.5 3 3" strokeWidth="1.5"/>
      </svg>
    );

*/
