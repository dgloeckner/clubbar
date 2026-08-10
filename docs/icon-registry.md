# Icon Registry

**Reference documentation for product icons across backend, admin frontend, and terminal.**

The authoritative source of truth for icon validation is `backend/src/Modules/Products/Validators/IconNameValidator.php`. This document mirrors that list for human reference.

## Format

- Icon names are lowercase, hyphen-separated (kebab-case)
- No "Icon" suffix
- ASCII characters only (a-z, 0-9, hyphen)
- Descriptive of the product category

## Icon Definitions

### Beverages - Beer

| Icon Name | Description | Terminal Emoji |
|-----------|-------------|----------------|
| `beer-pils` | Pilsner beer | 🍺 |
| `beer-weizen` | Wheat beer (Weißbier) | 🍺 |
| `beer-weizen-new` | Fresh wheat beer (with garnish) | 🍺 |
| `beer-radler` | Radler (beer + lemonade) | 🍺 |
| `beer-alcohol-free` | Non-alcoholic beer | 🍺 |

### Beverages - Cider & Spritzers

| Icon Name | Description | Terminal Emoji |
|-----------|-------------|----------------|
| `cider-apfelwein` | Apfelwein / Äppler (Bembel) | 🍺 |
| `cider-appler` | Äppler cider can | 🍺 |
| `spritzer-apple` | Apfelschorle (apple juice + sparkling water) | 🍎 |

### Beverages - Soft Drinks

| Icon Name | Description | Terminal Emoji |
|-----------|-------------|----------------|
| `soda-lemonade` | Lemonade | 🥤 |
| `soda-limonade` | Limonade (bottled) | 🥤 |
| `juice-apple` | Apple juice | 🍎 |
| `juice-orange` | Orange juice | 🍊 |
| `soda` | Generic soft drink / soda | 🥤 |

### Beverages - Hot Drinks

| Icon Name | Description | Terminal Emoji |
|-----------|-------------|----------------|
| `coffee` | Coffee | ☕ |

### Beverages - Wine

| Icon Name | Description | Terminal Emoji |
|-----------|-------------|----------------|
| `wine-red` | Red wine | 🍷 |
| `wine-white` | White wine | 🥂 |

### Beverages - Water

| Icon Name | Description | Terminal Emoji |
|-----------|-------------|----------------|
| `water` | Water (0.5L) | 💧 |
| `water-large` | Water (1.0L) | 💧 |
| `water-small` | Water (0.25L) | 💧 |

### Food

| Icon Name | Description | Terminal Emoji |
|-----------|-------------|----------------|
| `food-pizza` | Pizza | 🍕 |
| `food-sandwich` | Sandwich | 🥪 |
| `food-bratwurst` | Bratwurst in bun | 🌭 |
| `food-hamburger` | Hamburger | 🍔 |
| `food-fish-sandwich` | Fish sandwich | 🐟 |
| `food-crisps` | Crisps / chips bag | 🥨 |
| `food-fries` | French fries | 🍟 |
| `food-bretzel` | Bretzel / pretzel | 🥨 |
| `food-crackers` | Crackers | 🍘 |
| `food-steak` | Steak | 🥩 |
| `food-salad` | Salad bowl | 🥗 |
| `snack-chips` | Chips / crisps | 🍟 |
| `snack` | Generic snack | 🍿 |

### Services - Sauna

| Icon Name | Description | Terminal Emoji |
|-----------|-------------|----------------|
| `sauna-token` | Sauna access token | 🪙 |
| `sauna-thermometer` | Sauna thermometer | 🌡️ |
| `sauna-session` | Sauna session time | 🧖 |
| `sauna-cabin` | Sauna cabin rental | 🏠 |
| `sauna-infusion` | Aufguss (sauna infusion) | 💨 |
| `sauna-ice` | Ice bucket | 🧊 |
| `sauna-shower` | Shower | 🚿 |
| `sauna-towel` | Towel rental | 🧺 |
| `sauna-wellness` | Wellness / spa | 🌸 |
| `sauna-whisk` | Birch whisk (venik) | 🌿 |

### Special

| Icon Name | Description | Terminal Emoji |
|-----------|-------------|----------------|
| `correction` | Manual correction | 📝 |
| `unknown` | Unknown/undefined | 🛒 |

## Category Icons

Categories previously had no icon set of their own — a category chip could
only wear one of the product icons above (#299), so e.g. the Sauna category
wore a "sauna token" coin that read as *coin*, not *sauna*. These names
resolve to the dedicated assets in `terminal-frontend/assets/icons/categories/`
instead; a product icon name set on a category still resolves too, for
existing deployments.

| Icon Name | Description |
|-----------|-------------|
| `category-folder` | Folder outline |
| `category-tags` | Price/label tag |
| `category-layers` | Stacked layers |
| `category-list` | Bulleted list |
| `category-generic` | 2×2 grid — the default category glyph |

Unrecognized category names (and `null`) fall back to a neutral `Icons.category`
glyph in the terminal — never a product icon by accident.

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

1. Add name to `IconNameValidator::CANONICAL_ICONS` in `backend/src/Modules/Products/Validators/IconNameValidator.php`
2. Add SVG file to `terminal-frontend/assets/icons/products/`
3. Add canonical + legacy entries in `terminal-frontend/lib/utils/icon_registry.dart`
4. Add emoji mapping in `terminal-frontend/lib/utils/product_icons.dart`
5. Add React component in `admin-frontend/src/components/icons/product-icons/`
6. Export from `admin-frontend/src/components/icons/product-icons/index.ts`
7. Register in `admin-frontend/src/components/icons/IconRegistry.ts`
8. Update this document to match

## Validation

- **Backend** (authoritative): `IconNameValidator.php` validates on product creation/update
- **Admin frontend**: Dropdown from `PRODUCT_ICON_NAMES` in `IconRegistry.ts`
- **Terminal**: Graceful fallback to default icon for unrecognized names
