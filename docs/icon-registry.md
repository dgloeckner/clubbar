# Icon Registry

**Single source of truth for product icons across backend, admin frontend, and terminal.**

## Purpose

This document defines the canonical set of icon identifiers used throughout the Club Bar application. All three systems (backend database, admin frontend React app, Flutter terminal) MUST reference these exact icon names.

## Format

- Icon names are lowercase, hyphen-separated (kebab-case)
- No "Icon" suffix
- ASCII characters only (a-z, 0-9, hyphen)
- Descriptive of the product category

## Icon Definitions

### Beverages - Beer

| Icon Name | Description | Terminal Emoji | Admin UI Icon | Notes |
|-----------|-------------|----------------|---------------|-------|
| `beer-pils` | Pilsner beer | 🍺 | TBD | Standard German lager |
| `beer-weizen` | Wheat beer (Weißbier) | 🍺 | TBD | Bavarian wheat beer |
| `beer-radler` | Radler (beer + lemonade) | 🍺 | TBD | Shandy, cyclist's drink |
| `beer-alcohol-free` | Non-alcoholic beer | 🍺 | TBD | 0.0% or <0.5% ABV |

### Beverages - Cider & Spritzers

| Icon Name | Description | Terminal Emoji | Admin UI Icon | Notes |
|-----------|-------------|----------------|---------------|-------|
| `cider-apfelwein` | Apfelwein / Äppler | 🍺 | TBD | Frankfurt specialty apple cider (Bembel) |
| `spritzer-apple` | Apfelschorle (apple juice + sparkling water) | 🍎 | TBD | Popular German soft drink |

### Beverages - Hot Drinks

| Icon Name | Description | Terminal Emoji | Admin UI Icon | Notes |
|-----------|-------------|----------------|---------------|-------|
| `coffee` | Coffee | ☕ | TBD | Hot brewed coffee |

### Beverages - Water & Soft Drinks

| Icon Name | Description | Terminal Emoji | Admin UI Icon | Notes |
|-----------|-------------|----------------|---------------|-------|
| `water` | Water (0.5L) | 💧 | TBD | Standard bottle size |
| `water-large` | Water (1.0L) | 💧 | TBD | Large bottle |
| `soda` | Soft drink / Soda | 🥤 | TBD | Generic carbonated drink |

### Food

| Icon Name | Description | Terminal Emoji | Admin UI Icon | Notes |
|-----------|-------------|----------------|---------------|-------|
| `food-pizza` | Pizza | 🍕 | TBD | - |
| `food-sandwich` | Sandwich | 🥪 | TBD | - |
| `snack-chips` | Chips / Crisps | 🍟 | TBD | - |
| `snack` | Generic snack | 🍿 | TBD | Fallback for other snacks |

### Services - Sauna

| Icon Name | Description | Terminal Emoji | Admin UI Icon | Notes |
|-----------|-------------|----------------|---------------|-------|
| `sauna-session` | Sauna session time | 🧖 | TBD | Time-based booking |
| `sauna-cabin` | Sauna cabin rental | 🏠 | TBD | Private cabin |
| `sauna-infusion` | Aufguss (sauna infusion) | 💨 | TBD | Aromatherapy session |
| `sauna-towel` | Towel rental | 🧺 | TBD | Linen service |

### Special

| Icon Name | Description | Terminal Emoji | Admin UI Icon | Notes |
|-----------|-------------|----------------|---------------|-------|
| `correction` | Manual correction | 📝 | TBD | Used for transaction corrections (no product) |
| `unknown` | Unknown/undefined | 🛒 | TBD | Fallback when icon not recognized |

## Database Migration Required

Current database uses camelCase names with "Icon" suffix:
- `PilsIcon` → `beer-pils`
- `WeizenIcon` → `beer-weizen`
- `RadlerIcon` → `beer-radler`
- `BeerAFIcon` → `beer-alcohol-free`
- `BembelIcon` → `cider-apfelwein`
- `ApfelschorleIcon` → `spritzer-apple`
- `CoffeeMugIcon` → `coffee`
- `WaterLargeIcon` → `water-large`
- `SaunaTimeIcon` → `sauna-session`
- `SaunaCabinIcon` → `sauna-cabin`
- `SaunaAufgussIcon` → `sauna-infusion`
- `SaunaTowelIcon` → `sauna-towel`

**Migration SQL:**
```sql
UPDATE products SET icon_name = 'beer-pils' WHERE icon_name = 'PilsIcon';
UPDATE products SET icon_name = 'beer-weizen' WHERE icon_name = 'WeizenIcon';
UPDATE products SET icon_name = 'beer-radler' WHERE icon_name = 'RadlerIcon';
UPDATE products SET icon_name = 'beer-alcohol-free' WHERE icon_name = 'BeerAFIcon';
UPDATE products SET icon_name = 'cider-apfelwein' WHERE icon_name = 'BembelIcon';
UPDATE products SET icon_name = 'spritzer-apple' WHERE icon_name = 'ApfelschorleIcon';
UPDATE products SET icon_name = 'coffee' WHERE icon_name = 'CoffeeMugIcon';
UPDATE products SET icon_name = 'water-large' WHERE icon_name = 'WaterLargeIcon';
UPDATE products SET icon_name = 'sauna-session' WHERE icon_name = 'SaunaTimeIcon';
UPDATE products SET icon_name = 'sauna-cabin' WHERE icon_name = 'SaunaCabinIcon';
UPDATE products SET icon_name = 'sauna-infusion' WHERE icon_name = 'SaunaAufgussIcon';
UPDATE products SET icon_name = 'sauna-towel' WHERE icon_name = 'SaunaTowelIcon';
```

## Implementation Guidelines

### Backend (PHP/MariaDB)

Store canonical names in `products.icon_name` column:
```sql
INSERT INTO products (..., icon_name) VALUES (..., 'beer-pils');
```

### Admin Frontend (React)

Map canonical names to UI icons:
```typescript
const PRODUCT_ICONS: Record<string, React.ReactNode> = {
  'beer-pils': <BeerIcon />,
  'coffee': <CoffeeIcon />,
  // ...
};
```

### Terminal Frontend (Flutter)

Map canonical names to emojis:
```dart
static const Map<String, String> _iconMap = {
  'beer-pils': '🍺',
  'coffee': '☕',
  // ...
};
```

## Adding New Icons

1. Add entry to this document with description
2. Update terminal Flutter emoji mapping (`lib/utils/product_icons.dart`)
3. Update admin frontend icon component mapping
4. Database automatically supports new names (no migration needed for additions)

## Validation

All systems MUST validate icon names against this registry:
- Backend: Validate on product creation/update
- Admin frontend: Dropdown/autocomplete from this list
- Terminal: Graceful fallback to `unknown` (🛒) for unrecognized names
