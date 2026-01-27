// ============================================
// Category Icons for frgs-admin.html
// ============================================

// Option 1: Grid (recommended - classic category representation)
const CategoryIcon = () => (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <rect x="3" y="3" width="7" height="7" rx="1"/>
    <rect x="14" y="3" width="7" height="7" rx="1"/>
    <rect x="3" y="14" width="7" height="7" rx="1"/>
    <rect x="14" y="14" width="7" height="7" rx="1"/>
  </svg>
);

// Option 2: Tags (product labels feel)
const CategoryTagsIcon = () => (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <path d="M2 6a1 1 0 0 1 1-1h6l9 9a1 1 0 0 1 0 1.41l-5.59 5.59a1 1 0 0 1-1.41 0L2 12V6z"/>
    <circle cx="6" cy="9" r="1" fill="currentColor"/>
    <path d="M7 3h6l9 9a1 1 0 0 1 0 1.41" opacity="0.5"/>
  </svg>
);

// Option 3: Layers (stacked categories)
const CategoryLayersIcon = () => (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <polygon points="12,2 22,8.5 12,15 2,8.5"/>
    <polyline points="2,12.5 12,19 22,12.5"/>
    <polyline points="2,16.5 12,23 22,16.5"/>
  </svg>
);

// Option 4: Folder (organized files feel)
const CategoryFolderIcon = () => (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <path d="M2 4a1 1 0 0 1 1-1h4l2 2h10a1 1 0 0 1 1 1v3H2V4z"/>
    <path d="M2 9h20v10a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V9z"/>
    <path d="M6 13h4"/>
    <path d="M6 17h8"/>
  </svg>
);

// Option 5: List (menu/bullet list)
const CategoryListIcon = () => (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <circle cx="4" cy="5" r="1.5" fill="currentColor"/>
    <line x1="9" y1="5" x2="21" y2="5"/>
    <circle cx="4" cy="12" r="1.5" fill="currentColor"/>
    <line x1="9" y1="12" x2="18" y2="12"/>
    <circle cx="4" cy="19" r="1.5" fill="currentColor"/>
    <line x1="9" y1="19" x2="20" y2="19"/>
  </svg>
);

export { CategoryIcon, CategoryTagsIcon, CategoryLayersIcon, CategoryFolderIcon, CategoryListIcon };
export default CategoryIcon;
