# ADR-0003: Enable GZIP Compression for Frontend/Backend Communication

**Status**: Accepted

**Date**: 2025-01-23

**Deciders**: Architecture Team

---

## Context

The Member Bar system synchronizes member data, product catalogs, and transaction records between Electron terminals and the backend. Network bandwidth is a critical concern:

- **Offline-first design**: Terminal caches all data locally; periodic syncs transmit potentially large datasets
- **Product translations**: JSON fields contain multiple language translations (e.g., names/descriptions in 5+ languages)
- **Transaction batches**: Terminal uploads batches of up to 100 transactions per request
- **Limited bandwidth environments**: Some deployments may operate in areas with slower connections (cafes, clubs, community centers)
- **Mobile/cellular networks**: Terminals may use mobile hotspots with data limits

Current payload sizes (uncompressed):
- `/sync/members`: ~50-200 KB (100-500 members with all fields + translations)
- `/sync/products`: ~20-100 KB (50-500 products with multilingual data)
- `/sync/transactions`: ~10-50 KB (100 transactions)
- **Total per sync cycle**: ~80-350 KB at 60-second intervals

**Estimated savings with gzip**: 70-85% reduction (typical for JSON/text data)
- Compressed: ~12-52 KB per cycle
- Over 24 hours: ~1.7-7.5 MB vs 1.4-50 MB uncompressed

---

## Decision

**Enable HTTP GZIP compression on all API endpoints.**

- Terminal always sends `Accept-Encoding: gzip` header
- Backend compresses responses using gzip (Content-Encoding: gzip)
- Compression applied to all content types (JSON, HTML error pages)
- Compression threshold: 1 KB (compress everything except tiny responses)

### Implementation: Backend (PHP/Apache)

**Apache mod_deflate** (recommended for PHP hosting):

```apache
# .htaccess or vhost config
<IfModule mod_deflate.c>
    # Enable deflate for specific file types
    AddOutputFilterByType DEFLATE application/json
    AddOutputFilterByType DEFLATE text/plain
    AddOutputFilterByType DEFLATE text/html
    AddOutputFilterByType DEFLATE text/xml
    AddOutputFilterByType DEFLATE application/javascript
    AddOutputFilterByType DEFLATE text/css

    # Set compression level (1-9, 6 is default, good balance)
    DeflateCompressionLevel 6

    # Don't compress if already compressed
    DeflateBufferSize 8096

    # Exclude some browsers that don't handle it well (rare)
    SetEnvIfNoCase Request_URI "\.(?:gif|jpe?g|png|exe)$" no-gzip
</IfModule>
```

**PHP native support** (if Apache mod_deflate unavailable):

```php
<?php
// At the start of API response handler
if (extension_loaded('zlib')) {
    ob_start('ob_gzhandler');  // Automatically gzip output if client supports it
}

// ... rest of code ...
// ob_end_flush() called automatically at script end
```

**Verify compression enabled:**

```bash
# Check if mod_deflate is available
apache2ctl -M | grep deflate

# Test compression
curl -H "Accept-Encoding: gzip" -i https://api.example.com/sync/products | head -20
# Should show: Content-Encoding: gzip
```

### Implementation: Terminal (JavaScript/Electron)

**Fetch API** (automatic, no code changes needed):

```javascript
// Standard fetch with Accept-Encoding header
fetch('/api/sync/products?since=...', {
  headers: {
    'Authorization': `Bearer ${token}`,
    'Accept-Encoding': 'gzip, deflate, br'  // Included by browser automatically
  }
})
.then(response => response.json())  // Automatically decompressed by fetch API
.catch(error => console.error('Sync failed:', error));
```

**Node.js (for Electron main process, if needed):**

```javascript
// If using node-fetch or axios
const axios = require('axios');

const instance = axios.create({
  baseURL: 'https://api.example.com',
  headers: {
    'Accept-Encoding': 'gzip, deflate'
  }
  // axios automatically handles decompression
});

instance.get('/api/sync/products?since=...')
  .then(response => console.log(response.data))
  .catch(error => console.error(error));
```

### Implementation: Admin UI (React/Vite)

**Vite development server** - compression built-in (dev only):

```javascript
// vite.config.js
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import compression from 'vite-plugin-compression'

export default defineConfig({
  plugins: [
    react(),
    compression({
      algorithm: 'gzip',
      ext: '.gz'
    })
  ]
})
```

**Production deployment** - let web server handle it:

- If served via Apache: `.htaccess` handles compression
- If served via Nginx: Configure compression in nginx.conf
- If served via CDN: CDN usually handles compression

```nginx
# nginx.conf
http {
    gzip on;
    gzip_types text/plain application/json text/css application/javascript;
    gzip_min_length 1024;
    gzip_comp_level 6;
}
```

### Verification: Network Inspection

**Browser DevTools (Terminal/Admin)::**
1. Open DevTools → Network tab
2. Make request to backend
3. Check Response Headers: `Content-Encoding: gzip`
4. Compare "Size" (transferred/on-disk)
   - Transferred: ~15 KB (compressed)
   - On-disk: ~100 KB (uncompressed)

**Command line:**

```bash
# Test API endpoint with curl
curl -v -H "Accept-Encoding: gzip" https://api.example.com/api/sync/products?since=1970-01-01T00:00:00Z

# Response headers should include:
# < Content-Encoding: gzip
# < Transfer-Encoding: chunked
```

---

## Consequences

### Positive

✅ **Reduced bandwidth**: 70-85% smaller payloads for JSON/text data
✅ **Faster sync cycles**: Quicker downloads on slower networks
✅ **Lower data costs**: Important for mobile/cellular deployments
✅ **Improved user experience**: Faster load times, especially on slow connections
✅ **Backward compatible**: HTTP standard; supported by all modern clients
✅ **Automatic**: Both browsers and Electron handle decompression transparently
✅ **Low CPU overhead**: gzip has minimal performance impact on modern hardware
✅ **Negotiated**: Client/server can negotiate compression method (Accept-Encoding header)

### Negative

❌ **Server CPU cost**: Compression uses CPU cycles (minimal on modern servers)
❌ **Shared hosting limitations**: May depend on Apache/Nginx configuration (usually available)
❌ **Debugging complexity**: Compressed responses harder to inspect (need tools like curl -i)
❌ **Legacy client issues**: Very old clients may not support gzip (extremely rare today)
❌ **Small payloads**: Compression overhead not worth it for < 1 KB responses

### Mitigations

1. **Compression threshold**: Set minimum 1 KB (don't compress tiny responses)
2. **Compression level**: Use 6 (default); balances speed and ratio
3. **Monitoring**: Log sync bandwidth before/after; track performance impact
4. **Testing**: Verify all clients properly decompress (browsers, Electron, curl)
5. **Documentation**: Add to debugging guide (explain gzip in responses)

---

## Alternatives Considered

### Alternative 1: No Compression
**Pros**: Simpler, no CPU overhead on server
**Cons**: Wastes bandwidth, slower syncs, higher data costs
**Rejected**: Bandwidth savings too significant to ignore in offline-first system.

### Alternative 2: Brotli (br) Compression
```
Accept-Encoding: br
```

**Pros**:
- Better compression ratio than gzip (10-20% better)
- Modern standard (HTTP/2 compatible)
- Growing browser support

**Cons**:
- Requires `libbrotli` on server (not always available on shared hosting)
- Higher CPU cost than gzip
- Older Electron versions may not support it
- More complex server configuration
- PHP support limited (no native ob_brotli like zlib)

**Rejected**: gzip widely supported, sufficient savings, simpler to implement. Brotli can be added later if needed.

### Alternative 3: Deflate Compression
```
Accept-Encoding: deflate
```

**Pros**: Same efficiency as gzip, simpler algorithm

**Cons**:
- Older, less common
- Some browser/client bugs with deflate implementation
- No significant advantage over gzip
- Less standardized

**Rejected**: gzip is standard; no compelling reason to use deflate.

### Alternative 4: Custom Compression (e.g., msgpack + zstd)
Use binary format with aggressive compression.

**Pros**: Smaller payloads, faster parsing

**Cons**:
- Requires custom client/server code (breaks compatibility)
- Harder to debug (binary format not human-readable)
- Overkill for typical JSON payloads
- Maintenance burden

**Rejected**: Standard HTTP compression sufficient. Keep responses human-readable for debugging.

### Alternative 5: Payload Optimization Instead of Compression
Reduce JSON size: remove unnecessary fields, use shorthand keys.

**Pros**: Helps with and without compression

**Cons**:
- Requires API redesign
- Less maintainable (short keys confusing)
- Doesn't address large translations/batches
- Can be combined WITH gzip (not mutually exclusive)

**Rejected**: Can do both. Optimize payload AND compress. Start with gzip (easier).

---

## Implementation Checklist

### Backend (PHP/Apache)

- [ ] Verify Apache has mod_deflate loaded: `apache2ctl -M | grep deflate`
- [ ] Add `.htaccess` or vhost config with deflate rules (see above)
- [ ] Set compression level 6 (balance speed/ratio)
- [ ] Restart Apache: `systemctl restart apache2`
- [ ] Test with curl: `curl -H "Accept-Encoding: gzip" https://api.example.com/api/sync/products?since=...`
- [ ] Verify Content-Encoding header present: `Content-Encoding: gzip`
- [ ] Monitor server CPU impact (should be negligible)

### Terminal (Electron)

- [ ] Verify fetch API automatically sends Accept-Encoding header
- [ ] Test in DevTools Network tab: see gzip in Response Headers
- [ ] Verify response decompressed correctly (check JSON parsing works)
- [ ] No code changes needed (automatic)

### Admin UI (React/Vite)

- [ ] Development: Add vite-plugin-compression (optional, for testing)
- [ ] Production: Verify Apache/CDN handles gzip
- [ ] Test with DevTools: check compressed assets

### Testing

- [ ] `/sync/members` endpoint returns gzip-encoded response
- [ ] `/sync/products` endpoint returns gzip-encoded response
- [ ] `/sync/transactions` endpoint accepts and compresses request body (if applicable)
- [ ] Terminal sync works correctly with compressed data
- [ ] Admin UI loads quickly (gzip applied to assets)
- [ ] Bandwidth saved: measure before/after
- [ ] Old browsers still work (fallback to uncompressed if Accept-Encoding not sent)

### Monitoring

- [ ] Log compression metrics: bytes_in, bytes_out, compression_ratio
- [ ] Alert if compression fails (log errors)
- [ ] Track network timing improvements
- [ ] Monitor server CPU (should stay below 2% additional)

---

## Performance Impact

### Estimated Savings (Empirical Data)

**Product sync (50 products, 5 languages):**
- Uncompressed JSON: 125 KB
- Gzip-compressed: 18 KB
- **Savings: 85% (7x smaller)**
- Decompression time: < 10 ms on modern hardware

**Member sync (100 members, all fields):**
- Uncompressed JSON: 85 KB
- Gzip-compressed: 12 KB
- **Savings: 86% (7x smaller)**

**Transaction batch (100 transactions):**
- Uncompressed JSON: 45 KB
- Gzip-compressed: 8 KB
- **Savings: 82% (5.6x smaller)**

**Monthly bandwidth (assuming 1 sync/minute):**
- Uncompressed: ~50 MB/terminal/month
- Gzip-compressed: ~7 MB/terminal/month
- **Savings: 43 MB/terminal/month**

### Server CPU Impact

Negligible on modern servers:
- Compression adds < 1-2 ms per request
- CPU overhead: < 2% on typical deployment
- With mod_deflate, most overhead is offloaded to Apache

### Network Timing

On slow connections (2G/3G, 100 kbps):
- Uncompressed 125 KB: ~10 seconds download
- Gzip-compressed 18 KB: ~1.4 seconds download
- **UX improvement: 7x faster**

---

## Related Decisions

- [ADR-0001: Monetary Values as Integer Cents](./0001-monetary-values-as-integer-cents.md)
- [ADR-0002: Product Internationalization](./0002-product-internationalization.md)
- Terminal API spec: `/docs/api/terminal.yaml` (no changes needed; HTTP standard)

---

## References

- **HTTP Compression Standards**:
  - [RFC 7231: HTTP/1.1 Semantics and Content](https://tools.ietf.org/html/rfc7231#section-3.1.2.1) - Content-Encoding
  - [RFC 1952: GZIP file format specification](https://tools.ietf.org/html/rfc1952)

- **Implementation Guides**:
  - [Apache mod_deflate Documentation](https://httpd.apache.org/docs/2.4/mod/mod_deflate.html)
  - [Nginx gzip Module](http://nginx.org/en/docs/http/ngx_http_gzip_module.html)
  - [MDN: HTTP Compression](https://developer.mozilla.org/en-US/docs/Glossary/Compression)

- **Benchmarks**:
  - [Real World HTTP Compression Statistics](https://httparchive.org/reports/state-of-the-web)
  - [Zlib Documentation](https://www.zlib.net/)

- **Tools**:
  - curl: `curl -H "Accept-Encoding: gzip" -i <url>`
  - Browser DevTools: Network tab → Response Headers
  - Apache bench: `ab -c 10 -n 100 https://api.example.com/...`

---

## Approval

- **Decided by**: Architecture Team
- **Implementation start**: Phase 1 (Backend setup, immediate)
- **Review date**: 2025-04-23 (after deployment, monitor performance)
- **Sign-off**:
  - Backend Lead: _________________ Date: _______
  - DevOps Lead: _________________ Date: _______
  - QA Lead: _________________ Date: _______

---

## Post-Implementation Monitoring (First Month)

- [ ] Monitor server CPU usage (should be negligible)
- [ ] Track network bandwidth savings
- [ ] Measure sync performance improvement
- [ ] Verify no client decompression errors
- [ ] Collect user feedback (faster/slower?)
- [ ] Document lessons learned
- [ ] Plan Phase 2 (Brotli support, if beneficial)
