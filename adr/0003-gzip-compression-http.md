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

**Enable HTTP GZIP compression on all API responses. Backend automatically compresses JSON responses when client sends Accept-Encoding header. Compression threshold: 1 KB (do not compress tiny responses).**

### HTTP Compression Negotiation

```mermaid
flowchart LR
    A["Client Request<br/>(Terminal or Admin)"] -->|"Accept-Encoding: gzip"| B["Backend Receives<br/>(Apache, Nginx, or PHP)"]
    B -->|"Content-Encoding: gzip<br/>(compressed payload)"| C["Client Decompresses<br/>(transparent)"]
    C -->|"JSON parsed<br/>(no code changes)"| D["Application<br/>(Terminal/Admin)"]
```

### Backend Configuration

Enable gzip via web server configuration:

- **Apache**: Enable `mod_deflate` in `.htaccess` or vhost config
- **Nginx**: Configure `gzip on` in `nginx.conf`
- **PHP**: Use native `zlib` output buffering (fallback)

All modern web servers support transparent gzip negotiation via Accept-Encoding header. No application code changes required.

### Frontend (Terminal & Admin)

**Automatic decompression**:
- Browser Fetch API decompresses transparently
- Axios decompresses automatically
- No configuration needed; standard HTTP

Query to verify compression working:
```bash
curl -H "Accept-Encoding: gzip" https://api.example.com/api/sync/products?since=...
# Response headers should include: Content-Encoding: gzip
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

## Implementation Notes

**Backend**: Configure compression at web server level (Apache mod_deflate or Nginx gzip module). No application code changes needed.

**Compression settings**: Level 6 (default), minimum 1 KB threshold, apply to all content types (JSON, HTML, CSS, JavaScript).

**Testing**: Use `curl -H "Accept-Encoding: gzip" <url>` to verify Content-Encoding header in response. Browser DevTools Network tab shows transferred vs. original size.

**Monitoring**: Track bandwidth reduction and server CPU impact (should be negligible on modern hardware).

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

## Post-Implementation Monitoring

- Monitor server CPU usage (should be negligible)
- Track network bandwidth savings (expect 70-85% reduction)
- Measure sync performance improvement
- Verify no client decompression errors
- Collect user feedback
