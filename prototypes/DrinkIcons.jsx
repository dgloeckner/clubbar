// ============================================
// Drink & Token Icons for frgs-admin.html
// ============================================
// Copy the icons you need into your ICONS section

// ========== BEER ICONS ==========

// Pils - classic German pilsner
const PilsIcon = () => (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
    <path d="M7 20L6 8c0-1 .5-2 2-2h8c1.5 0 2 1 2 2l-1 12c0 1-1 2-5 2s-5-1-5-2z" fill="#fbbf24" fillOpacity="0.3"/>
    <path d="M6 8h12"/>
    <ellipse cx="12" cy="8" rx="5" ry="1.5" fill="#fef3c7"/>
    <circle cx="10" cy="14" r="0.5" fill="currentColor" opacity="0.3"/>
    <circle cx="13" cy="12" r="0.5" fill="currentColor" opacity="0.3"/>
  </svg>
);

// Weizen - wheat beer in tall curved glass
const WeizenIcon = () => (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
    <path d="M8 20c0 1 1 2 4 2s4-1 4-2l1-8c.5-4-1-6-2-8-.5-1-.5-2 0-3h-6c.5 1 .5 2 0 3-1 2-2.5 4-2 8z" fill="#f59e0b" fillOpacity="0.3"/>
    <ellipse cx="12" cy="7" rx="3" ry="1.5" fill="#fef3c7"/>
    <ellipse cx="12" cy="5.5" rx="2.5" ry="1.5" fill="white"/>
  </svg>
);

// Alcohol-free beer with 0.0% badge
const BeerAFIcon = () => (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
    <path d="M7 20L6 8c0-1 .5-2 2-2h8c1.5 0 2 1 2 2l-1 12c0 1-1 2-5 2s-5-1-5-2z" fill="#fbbf24" fillOpacity="0.3"/>
    <ellipse cx="12" cy="8" rx="5" ry="1.5" fill="#fef3c7"/>
    <circle cx="18" cy="16" r="4" fill="#22c55e" stroke="none"/>
    <text x="18" y="17.5" textAnchor="middle" fill="white" fontSize="5" fontWeight="bold">0%</text>
  </svg>
);

// ========== MIXED DRINKS ==========

// Radler - beer + lemonade with lemon slice
const RadlerIcon = () => (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
    <path d="M7 20L6 8c0-1 .5-2 2-2h8c1.5 0 2 1 2 2l-1 12c0 1-1 2-5 2s-5-1-5-2z" fill="#fde047" fillOpacity="0.4"/>
    <ellipse cx="12" cy="8" rx="5" ry="1.5" fill="#fef9c3"/>
    <circle cx="17" cy="6" r="3" fill="#fde047" stroke="#facc15"/>
    <circle cx="17" cy="6" r="2" fill="#fef9c3" stroke="none"/>
  </svg>
);

// ========== SOFT DRINKS ==========

// Lemonade with ice and lemon
const LemonadeIcon = () => (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
    <path d="M7 20L6 6c0-1 .5-2 2-2h8c1.5 0 2 1 2 2l-1 14c0 1-1 2-5 2s-5-1-5-2z" fill="#fef08a" fillOpacity="0.4"/>
    <rect x="9" y="9" width="3" height="2.5" rx="0.5" fill="white" fillOpacity="0.6" transform="rotate(10, 10, 10)"/>
    <circle cx="17" cy="5" r="3" fill="#fde047" stroke="#facc15"/>
    <circle cx="10" cy="14" r="0.5" fill="currentColor" opacity="0.3"/>
  </svg>
);

// Apple Juice / Apfelschorle
const AppleJuiceIcon = () => (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
    <path d="M7 20L6 6c0-1 .5-2 2-2h8c1.5 0 2 1 2 2l-1 14c0 1-1 2-5 2s-5-1-5-2z" fill="#fb923c" fillOpacity="0.4"/>
    <circle cx="17" cy="5" r="2.5" fill="#22c55e"/>
    <path d="M17 2.5q1-1 1 0" stroke="#166534" strokeWidth="1" fill="none"/>
    <circle cx="10" cy="12" r="0.5" fill="currentColor" opacity="0.3"/>
    <circle cx="13" cy="15" r="0.5" fill="currentColor" opacity="0.3"/>
  </svg>
);

// ========== ÄPPLER (Frankfurt specialty) ==========

// Äppler in traditional Geripptes glass with diamond pattern
const ApplerIcon = () => (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
    <path d="M7 20L6 6c0-1 .5-2 2-2h8c1.5 0 2 1 2 2l-1 14c0 1-1 2-5 2s-5-1-5-2z" fill="#d97706" fillOpacity="0.4"/>
    {/* Diamond/Rauten pattern - the Geripptes signature */}
    <line x1="7" y1="8" x2="10" y2="18" stroke="currentColor" strokeWidth="0.5" opacity="0.4"/>
    <line x1="10" y1="8" x2="13" y2="18" stroke="currentColor" strokeWidth="0.5" opacity="0.4"/>
    <line x1="13" y1="8" x2="16" y2="18" stroke="currentColor" strokeWidth="0.5" opacity="0.4"/>
    <line x1="17" y1="8" x2="14" y2="18" stroke="currentColor" strokeWidth="0.5" opacity="0.4"/>
    <line x1="14" y1="8" x2="11" y2="18" stroke="currentColor" strokeWidth="0.5" opacity="0.4"/>
    <line x1="11" y1="8" x2="8" y2="18" stroke="currentColor" strokeWidth="0.5" opacity="0.4"/>
    <circle cx="18" cy="5" r="2" fill="#dc2626"/>
  </svg>
);

// ========== WATER ==========

// Large sparkling water (1L bottle)
const WaterLargeIcon = () => (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
    <path d="M8 21V9c0-1 1-2 2-2V5c0-1 .5-2 2-2s2 1 2 2v2c1 0 2 1 2 2v12c0 1-1 2-4 2s-4-1-4-2z" fill="#60a5fa" fillOpacity="0.2"/>
    <rect x="10" y="2" width="4" height="2" rx="0.5" fill="#3b82f6"/>
    <circle cx="10" cy="14" r="0.7" fill="currentColor" opacity="0.3"/>
    <circle cx="13" cy="11" r="0.5" fill="currentColor" opacity="0.3"/>
    <circle cx="11" cy="17" r="0.6" fill="currentColor" opacity="0.3"/>
    <text x="12" y="15" textAnchor="middle" fill="currentColor" fontSize="4" opacity="0.6">1L</text>
  </svg>
);

// Small sparkling water (0.33L)
const WaterSmallIcon = () => (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
    <path d="M9 20V8c0-1 1-2 2-2h2c1 0 2 1 2 2v12c0 1-.5 2-3 2s-3-1-3-2z" fill="#60a5fa" fillOpacity="0.2"/>
    <circle cx="11" cy="13" r="0.6" fill="currentColor" opacity="0.3"/>
    <circle cx="13" cy="11" r="0.5" fill="currentColor" opacity="0.3"/>
    <circle cx="12" cy="16" r="0.5" fill="currentColor" opacity="0.3"/>
    <text x="12" y="5" textAnchor="middle" fill="currentColor" fontSize="4" opacity="0.6">0,33</text>
  </svg>
);

// ========== SAUNA TOKENS ==========

// Sauna token - Steam variant (recommended)
const SaunaTokenIcon = () => (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
    <circle cx="12" cy="12" r="10" fill="#d97706" stroke="#b45309"/>
    <circle cx="12" cy="12" r="8" fill="none" stroke="#fbbf24" strokeWidth="0.5"/>
    {/* Steam waves */}
    <path d="M8 14q-1-2 0-4t-1-4" stroke="#fef3c7" strokeWidth="1.5"/>
    <path d="M12 15q-1-2 0-4t-1-4" stroke="#fef3c7" strokeWidth="1.5"/>
    <path d="M16 14q-1-2 0-4t-1-4" stroke="#fef3c7" strokeWidth="1.5"/>
  </svg>
);

// Sauna token - Thermometer variant
const SaunaThermometerIcon = () => (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
    <circle cx="12" cy="12" r="10" fill="#dc2626" stroke="#991b1b"/>
    <circle cx="12" cy="12" r="8" fill="none" stroke="#fca5a5" strokeWidth="0.5"/>
    <rect x="10" y="5" width="4" height="10" rx="2" fill="#fef2f2" stroke="#fca5a5" strokeWidth="0.5"/>
    <circle cx="12" cy="17" r="3" fill="#fef2f2" stroke="#fca5a5" strokeWidth="0.5"/>
    <rect x="11" y="8" width="2" height="6" fill="#dc2626"/>
    <circle cx="12" cy="17" r="2" fill="#dc2626"/>
  </svg>
);

// Sauna token - 30 min time variant
const SaunaTimeIcon = () => (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
    <circle cx="12" cy="12" r="10" fill="#7c3aed" stroke="#5b21b6"/>
    <circle cx="12" cy="12" r="8" fill="none" stroke="#c4b5fd" strokeWidth="0.5"/>
    <circle cx="12" cy="10" r="5" fill="#ede9fe" stroke="#c4b5fd" strokeWidth="0.5"/>
    <line x1="12" y1="10" x2="12" y2="6" stroke="#5b21b6" strokeWidth="1.5"/>
    <line x1="12" y1="10" x2="12" y2="13" stroke="#7c3aed" strokeWidth="1"/>
    <circle cx="12" cy="10" r="1" fill="#5b21b6"/>
    <text x="12" y="20" textAnchor="middle" fill="#ede9fe" fontSize="4" fontWeight="bold">30m</text>
  </svg>
);

// Sauna token - Cabin variant
const SaunaCabinIcon = () => (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
    <circle cx="12" cy="12" r="10" fill="#0891b2" stroke="#0e7490"/>
    <circle cx="12" cy="12" r="8" fill="none" stroke="#67e8f9" strokeWidth="0.5"/>
    {/* Cabin roof */}
    <path d="M6 11L12 5l6 6" stroke="#ecfeff" strokeWidth="1.5"/>
    {/* Cabin walls */}
    <rect x="7" y="11" width="10" height="7" fill="#ecfeff" fillOpacity="0.2" stroke="#ecfeff" strokeWidth="1"/>
    {/* Door */}
    <rect x="10" y="13" width="4" height="5" fill="#0891b2" stroke="#ecfeff" strokeWidth="0.5"/>
    {/* Steam */}
    <path d="M15 4q-0.5-1 0-2" stroke="#67e8f9" strokeWidth="1"/>
  </svg>
);

export {
  PilsIcon,
  WeizenIcon,
  BeerAFIcon,
  RadlerIcon,
  LemonadeIcon,
  AppleJuiceIcon,
  ApplerIcon,
  WaterLargeIcon,
  WaterSmallIcon,
  SaunaTokenIcon,
  SaunaThermometerIcon,
  SaunaTimeIcon,
  SaunaCabinIcon,
};
