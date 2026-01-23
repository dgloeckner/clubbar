# ADR-0002: Product Internationalization (i18n) Strategy

**Status**: Accepted

**Date**: 2025-01-23

**Deciders**: Architecture Team

---

## Context

The Member Bar system is designed for open-source use in international organizations. Products (beverages, snacks, services) are created and managed via the Admin UI. Organizations need to support products in multiple languages, with each terminal operator viewing products in their preferred language.

Key requirements:
- **Admin UI**: Create products in multiple languages via a single product form
- **Terminal**: Display products in operator's preferred language (persisted in user record)
- **API**: Simple, language-agnostic sync (always return all translations)
- **Backend configuration**: Organization specifies enabled languages and default language
- **Graceful fallback**: If translation missing, display backend default language
- **Flexibility**: Support any number of languages (not hardcoded to 2-3)
- **Backward compatibility**: Existing single-language products should continue to work
- **User preference**: Store language preference persistently (not just in browser localStorage)
- **Simplicity**: Language selection is display-time concern, not API concern

---

## Decision

**Products store translatable fields (name, description) as JSON dictionaries, keyed by ISO 639-1 language codes. User language preference is persisted in user data. API responses are language-agnostic (always include all translations). Frontend handles localization at display time based on user preference and backend configuration.**

### Key Principles

1. **Backend configuration**: Organization defines enabled languages and default language
2. **API is language-agnostic**: `/sync/products` always returns all translations (no `?language=` parameter)
3. **User preference persisted**: Stored in user record (not ephemeral browser state)
4. **Display-time localization**: Frontend applies language preference when rendering
5. **Graceful fallback**: Missing translation → backend default language → any available language

### Database Schema

#### Products Table

```sql
CREATE TABLE products (
  id BINARY(16) PRIMARY KEY,

  -- Translatable fields (JSON structure)
  names JSON NOT NULL,                -- {"en": "Pilsner 0.5L", "de": "Pils 0,5L"}
  descriptions JSON,                  -- {"en": "German lager", "de": "Deutsches Lagerbier"}

  -- Non-translatable fields
  category VARCHAR(50) NOT NULL,      -- beverages, snacks, services (not translated)
  price_cents INT NOT NULL,
  is_active BOOLEAN DEFAULT TRUE,

  -- Metadata
  created_at DATETIME DEFAULT NOW(),
  updated_at DATETIME DEFAULT NOW() ON UPDATE NOW(),

  -- Indexes for querying
  INDEX idx_is_active (is_active),
  INDEX idx_category (category)
);
```

**Example record:**
```sql
INSERT INTO products VALUES (
  UNHEX('987f6543e21a11d3b456426614174999'),
  JSON_OBJECT('en', 'Pilsner 0.5L', 'de', 'Pils 0,5L'),
  JSON_OBJECT('en', 'German lager beer', 'de', 'Deutsches Lagerbier'),
  'beverages',
  350,
  TRUE,
  NOW(),
  NOW()
);
```

#### Members Table (User Preference)

```sql
ALTER TABLE members ADD COLUMN preferred_language VARCHAR(10) DEFAULT 'en';

-- Example: Member prefers German
UPDATE members SET preferred_language = 'de' WHERE id = '...';
```

#### Backend Configuration

```php
// config/languages.php or environment variables
return [
    'enabled_languages' => ['en', 'de', 'fr'],    // Organization's supported languages
    'default_language' => 'en',                   // Fallback if translation missing
];
```

### Admin UI Implementation

**Product Form Features:**

1. **Language tabs**: Tabs for each enabled language (de, en, fr, etc.)
   ```jsx
   <LanguageTabs languages={['de', 'en', 'fr']}>
     <Tab label="Deutsch">
       <TextInput label="Name" {...register('names.de')} />
       <Textarea label="Beschreibung" {...register('descriptions.de')} />
     </Tab>
     <Tab label="English">
       <TextInput label="Name" {...register('names.en')} />
       <Textarea label="Description" {...register('descriptions.en')} />
     </Tab>
     ...
   </LanguageTabs>
   ```

2. **Validation**: At least one language must have a name
   ```javascript
   Zod.object({
     names: Zod.record(Zod.string().min(1)).refine(
       (names) => Object.keys(names).length > 0,
       "At least one language required"
     )
   })
   ```

3. **Copy from language**: Quick action to copy name/description from one language to another
   ```
   [Copy from: [de ▼]] → Quickly populate other languages
   ```

4. **Organization language settings**: Admin can specify which languages are enabled for the organization
   ```sql
   -- Settings table (or config file)
   enabled_languages: ['de', 'en', 'fr']  -- Used by Admin UI
   ```

### Terminal API: Language-Agnostic Sync

**Endpoint:**

```yaml
GET /sync/products?since={timestamp}
```

**Query Parameters:**
- `since`: ISO 8601 timestamp (existing)
- No language parameter. API always returns all translations.

**Response: All Translations Included**

```json
{
  "products": [
    {
      "id": "987f6543-e21a-11d3-b456-426614174999",
      "names": {                                 // All available translations
        "en": "Pilsner 0.5L",
        "de": "Pils 0,5L",
        "fr": "Pilsner 0,5L"
      },
      "descriptions": {                          // All available translations
        "en": "German lager beer",
        "de": "Deutsches Lagerbier",
        "fr": "Bière lager allemande"
      },
      "category": "beverages",
      "price_cents": 350,
      "is_active": true,
      "created_at": "2025-01-01T00:00:00Z",
      "updated_at": "2025-01-10T10:15:30Z"
    }
  ],
  "cursor": "2025-01-10T10:15:30Z",
  "count": 1,
  "has_more": false
}
```

**Frontend Localization:**

Terminal applies localization at display time:

```javascript
const productName = product.names[userLanguage]     // User preference
                 || product.names[defaultLanguage]  // Backend config fallback
                 || Object.values(product.names)[0]; // Any available
```

### Terminal Implementation

**User Language Preference:**

Terminal reads `member.preferred_language` from sync response:

```javascript
// After syncing members, extract user's language preference
const currentMember = syncedMembers.find(m => m.id === currentUserId);
const userLanguage = currentMember.preferred_language;  // e.g., 'de'

// Or from terminal settings (if terminal handles multiple users)
const userLanguage = currentUser.preferred_language;
```

**Product Caching & Display:**

1. **Simple product sync** (language-agnostic):
   ```javascript
   fetch(`/api/sync/products?since=${lastSync}`, {
     headers: { 'Authorization': `Bearer ${token}` }
   })
   ```

2. **Cache all translations** (not localized):
   ```javascript
   // Save full product object (with ALL translations, no localization)
   db.exec(`
     INSERT OR REPLACE INTO products (id, names, descriptions, category, ...)
     VALUES (?, ?, ?, ?, ...)
   `, [product.id, JSON.stringify(product.names), JSON.stringify(product.descriptions), ...])
   ```

3. **Display-time localization**:
   ```javascript
   const getLocalizedProductName = (product, userLanguage, defaultLanguage) => {
     // 1. Use user's preferred language
     if (product.names[userLanguage]) {
       return product.names[userLanguage];
     }
     // 2. Fall back to backend default language
     if (product.names[defaultLanguage]) {
       return product.names[defaultLanguage];
     }
     // 3. Use any available language (shouldn't happen)
     return Object.values(product.names)[0];
   };

   // When rendering
   const productName = getLocalizedProductName(product, userLanguage, defaultLanguage);
   ```

4. **Settings Menu** (optional): Allow terminal operator to change language
   ```
   Settings → Language: [Deutsch ▼] [English ▼] [Français ▼]

   When changed, update via API:
   PATCH /api/members/{id} { preferred_language: 'fr' }
   ```
   This persists the preference for the next session.

### Backend Implementation (PHP)

**API endpoint:**

```php
<?php
// GET /api/sync/products?since={timestamp}

$since = $_GET['since'] ?? '1970-01-01T00:00:00Z';
$limit = min((int)($_GET['limit'] ?? 500), 1000);

// Fetch products modified since timestamp
$stmt = $db->prepare("
    SELECT id, names, descriptions, category, price_cents, is_active,
           created_at, updated_at
    FROM products
    WHERE updated_at >= ?
    ORDER BY updated_at ASC
    LIMIT ?
");
$stmt->execute([$since, $limit + 1]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$hasMore = count($rows) > $limit;
$products = array_slice($rows, 0, $limit);

// Transform for API response (no localization - send all translations)
$response = array_map(function($product) {
    $names = json_decode($product['names'], true) ?? [];
    $descriptions = json_decode($product['descriptions'], true) ?? [];

    return [
        'id' => $product['id'],
        'names' => $names,                      // All translations
        'descriptions' => $descriptions,        // All translations
        'category' => $product['category'],
        'price_cents' => (int)$product['price_cents'],
        'is_active' => (bool)$product['is_active'],
        'created_at' => $product['created_at'],
        'updated_at' => $product['updated_at']
    ];
}, $products);

$cursor = end($products)['updated_at'] ?? $since;

return json_encode([
    'products' => $response,
    'cursor' => $cursor,
    'count' => count($response),
    'has_more' => $hasMore
]);
```

**Backend Configuration:**

```php
<?php
// config/languages.php
return [
    'enabled_languages' => ['en', 'de', 'fr'],    // Available languages
    'default_language' => 'en',                   // Fallback language
];

// Usage in other endpoints
$config = require('config/languages.php');
$defaultLanguage = $config['default_language'];  // 'en'
```

---

## Consequences

### Positive

✅ **Simple API**: Language-agnostic; no query parameters needed for sync
✅ **Flexible**: Supports any number of languages without schema changes
✅ **User preference persisted**: Language choice saved in user record (not ephemeral)
✅ **JSON avoids complex joins**: Simpler than separate translation tables
✅ **Terminal-efficient**: All translations cached locally for offline display
✅ **Admin-friendly**: Single product edit form with language tabs
✅ **Backend-configured**: Organization controls enabled languages and default
✅ **Backward compatible**: Single-language products work (e.g., `{"en": "Pilsner"}`)
✅ **Display-time localization**: Frontend handles language selection (simple, predictable)
✅ **Graceful degradation**: Missing translations fall back to backend default, then any available

### Negative

❌ **Frontend complexity**: Terminal must implement display-time localization logic
❌ **Larger API payload**: All translations sent (not filtered by language)
❌ **Data validation overhead**: Admin UI must validate at least one translation exists
❌ **User preference management**: Preferred language stored per user (adds field to schema)
❌ **Incomplete translations**: Risk of products with missing translations (process can mitigate)

### Mitigations

1. **UI/UX**: Highlight missing translations in product form
   ```
   ⚠️ Name missing in: [Français]
   ```

2. **Helper functions**: Provide utility for name/description lookup
   ```javascript
   // Terminal: getLocalizedString(product.names, preferredLang, fallbackLang)
   // PHP: getLocalizedString($product['names'], $language, 'en')
   ```

3. **Validation**: Admin UI prevents saving products without at least one language
4. **Documentation**: Clear examples for admin/developer on translation workflows
5. **API documentation**: Explicitly document fallback behavior

---

## Alternatives Considered

### Alternative 1: API Language Parameter (`?language=de`)
Backend returns localized product names based on query parameter.

**Pros**:
- Reduces API payload (only requested language returned)
- Simple frontend display logic

**Cons**:
- Adds complexity to API endpoint (language validation, localization logic)
- Terminal must make separate requests for each language
- Offline language switching requires cache of all translations anyway

**Rejected**: Additional API complexity not justified. Frontend caching all translations anyway; simpler to localize at display time.

### Alternative 2: Separate Translation Table
```sql
CREATE TABLE product_translations (
  product_id BINARY(16),
  language VARCHAR(5),
  name VARCHAR(255),
  description TEXT,
  PRIMARY KEY (product_id, language)
);
```

**Pros**: Normalized; standard database design
**Cons**: Requires JOIN on every product query; more complex API response; terminal sync becomes multi-query
**Rejected**: Additional complexity not justified for typical 5-20 languages per organization

### Alternative 3: Platform-Level i18n (External Service)
Use a service like Crowdin or Phrase for translations.

**Pros**: Professional translation workflows
**Cons**: External dependency; cost; overkill for small organizations; adds network latency
**Rejected**: Not appropriate for open-source self-hosted system

### Alternative 4: Admin UI Only i18n (hardcoded to 2 languages)
Support DE/EN in database, other languages via front-end translation files.

**Pros**: Simpler database schema
**Cons**: Inflexible; product names not translatable for 3rd language; mismatch between data and UI
**Rejected**: Defeats purpose of product i18n

### Alternative 5: No Persisted User Preference
Store language preference only in browser localStorage/app state (not in user record).

**Pros**:
- Simpler user data model
- No additional user table field

**Cons**:
- Preference lost after terminal restart or session change
- Multi-terminal deployments: different terminals show different languages for same user
- Admin has no way to set organization language defaults

**Rejected**: User preference should persist across sessions and terminals

---

## Implementation Roadmap

1. **Phase 1** (MVP): Enable in Terminal API only
   - Update `/sync/products` endpoint to support `language` parameter
   - Terminal displays products in selected language
   - Admin API still returns single language (upgrade later)

2. **Phase 2**: Enable in Admin API
   - Create product CRUD endpoints with JSON field support
   - Admin UI provides language tabs
   - Translation copy utility

3. **Phase 3**: Organization settings
   - Admin can configure enabled languages
   - Terminal respects org language settings
   - Optionally provide default product translations per language

---

## Related Decisions

- [ADR-0001: Monetary Values as Integer Cents](./0001-monetary-values-as-integer-cents.md)
- Terminal API spec: `/docs/api/terminal.yaml` (to be updated with language parameter)
- Admin API spec: `/docs/api/admin.yaml` (to be created)

---

## References

- ISO 639-1: Language codes (https://en.wikipedia.org/wiki/ISO_639-1)
- MariaDB JSON documentation: https://mariadb.com/docs/
- i18next: https://www.i18next.com/ (reference for i18n patterns)
- Internationalization best practices: https://www.w3.org/International/

---

## Approval

- **Decided by**: Architecture Team
- **Implementation start**: Phase 1 (Terminal API endpoint update)
- **Review date**: 2025-04-23 (after Phase 1 completion)
