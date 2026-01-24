# Backend Docker Setup Guide

This backend uses the **serversideup/php:8.3-fpm-apache-bookworm-full** Docker image for development and testing.

## Quick Start

```bash
# 1. Install PHP dependencies (host machine)
cd backend && composer install && cd ..

# 2. Start containers
docker compose up -d

# 3. Verify backend is running
curl http://localhost:8080/api/health
```

## Image Overview

**Image**: `serversideup/php:8.3-fpm-apache-bookworm-full`

- **PHP Version**: 8.3
- **Web Server**: Apache + PHP-FPM
- **Base OS**: Debian 12 (Bookworm)
- **Port**: 8080 (HTTP), 8443 (HTTPS)
- **Document Root**: `/var/www/html/public` (Laravel)
- **User**: www-data (non-root, unprivileged)

**GitHub**: [serversideup/docker-php](https://github.com/serversideup/docker-php)
**Docs**: [serversideup.net/open-source/docker-php](https://serversideup.net/open-source/docker-php)

## Key Features

✓ **Pre-installed Extensions**:
- PDO, PDO-MySQL, PDO-PostgreSQL
- Redis (Laravel caching)
- PCNTL (Laravel queues)
- ZIP (file handling)
- OPcache (disabled by default)

✓ **Apache Features**:
- mod_rewrite enabled for Laravel routing
- .htaccess support
- Static file serving (fast)
- PHP-FPM via FastCGI (secure, scalable)

✓ **Laravel Automations** (optional):
- Auto-run migrations on startup
- Auto-generate app key
- Auto-create storage links

✓ **Development-Friendly**:
- Health check endpoint built-in
- Easy file mounting
- Self-signed SSL certificates available

## Configuration

### Environment Variables (docker-compose.yml)

```yaml
environment:
  # Apache Configuration
  APACHE_DOCUMENT_ROOT: /var/www/html/public  # Laravel public dir
  SSL_MODE: "off"                             # off|mixed|full

  # PHP Configuration
  PHP_MEMORY_LIMIT: "256M"                   # Default: 256M
  PHP_MAX_EXECUTION_TIME: "99"               # Default: 99 seconds
  PHP_UPLOAD_MAX_FILE_SIZE: "100M"           # Default: 100M
  PHP_POST_MAX_SIZE: "100M"                  # Default: 100M
  PHP_OPCACHE_ENABLE: "0"                    # Disable for dev (default)

  # Laravel Automations (optional)
  AUTORUN_ENABLED: "false"                   # Enable auto migrations
  AUTORUN_LARAVEL_MIGRATION: "false"         # Auto-run migrations
  AUTORUN_DEBUG: "false"                     # Debug autorun errors
```

### Port Mapping

The container runs on **port 8080 internally** (non-root user constraint).

In `docker-compose.yml`:
```yaml
ports:
  - "8080:80"    # Host:Container (HTTP)
  - "8443:443"   # Host:Container (HTTPS, if SSL_MODE=full)
```

Access the backend at: `http://localhost:8080`

## How It Works

1. **Apache** (port 8080):
   - Receives HTTP requests
   - Routes static files directly
   - Routes PHP requests to PHP-FPM via FastCFI

2. **PHP-FPM** (background):
   - Processes PHP scripts
   - Communicates with Apache via Unix socket
   - Isolated from web server (security + performance)

3. **Document Root**:
   - Apache serves files from `/var/www/html/public`
   - Laravel's `index.php` is the entry point
   - mod_rewrite routes all non-file/folder requests to `index.php`

## Laravel Routing (.htaccess)

The container includes Apache with `mod_rewrite` enabled. Laravel's `.htaccess` works out-of-the-box:

```apache
# File: /var/www/html/public/.htaccess
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [QSA,L]
```

This ensures all requests are routed through Laravel's routing system.

## File Permissions

The container runs as `www-data` (UID 33). If you have permission issues:

```bash
# Fix storage and cache permissions on host
chmod -R 777 backend/storage backend/bootstrap/cache

# Or inside container
docker compose exec backend chmod -R 775 storage bootstrap/cache
```

## Health Check

The image includes a built-in health check:

```bash
# From host
curl http://localhost:8080/healthcheck

# Expected response: 200 OK
```

## SSL/TLS Configuration

**For Development**:
```yaml
environment:
  SSL_MODE: "full"  # Auto-generates self-signed certificates
```

Access via HTTPS: `https://localhost:8443`

**Note**: Self-signed certificates are for development only.

## Troubleshooting

### Container won't start

**Check logs**:
```bash
docker compose logs backend
```

**Common causes**:
- `.env` file missing (copy from `.env.example`)
- PHP syntax errors in code
- Insufficient disk space
- Port 8080 already in use

### PHP errors not visible

Enable debug mode in `.env`:
```
APP_DEBUG=true
LOG_CHANNEL=stack
LOG_LEVEL=debug
```

Check logs:
```bash
docker compose logs -f backend
```

### Slow performance

**Enable OPcache** (for testing):
```yaml
environment:
  PHP_OPCACHE_ENABLE: "1"
```

**Note**: Disable OPcache during active development as it may cache file changes.

### Permission denied errors

Mount volumes with correct permissions:
```bash
# Ensure host files are readable by www-data (uid 33)
chmod -R a+r backend/
chmod -R a+w backend/storage backend/bootstrap/cache
```

## Installation of Additional Extensions

If you need extensions beyond the "full" variant, create a custom Dockerfile:

```dockerfile
FROM serversideup/php:8.3-fpm-apache-bookworm-full

USER root
RUN apt-get update && apt-get install -y \
    libintl-dev \
    && docker-php-ext-install intl bcmath
USER www-data
```

Then in `docker-compose.yml`:
```yaml
backend:
  build:
    context: .
    dockerfile: Dockerfile
```

## Database Connection

Default configuration in `docker-compose.yml`:

```yaml
environment:
  DB_HOST: database
  DB_PORT: 3306
  DB_DATABASE: ruderbar
  DB_USERNAME: ruderbar
  DB_PASSWORD: ruderbar
```

From container perspective:
- `database` resolves to the MariaDB container
- Connections use internal Docker network (secure)

## API Endpoints

All API endpoints are prefixed with `/api`:

```bash
# Health check
curl http://localhost:8080/api/health

# Sync endpoints
curl http://localhost:8080/api/sync/members
curl http://localhost:8080/api/sync/categories
curl http://localhost:8080/api/sync/products

# See api/terminal.yaml for full OpenAPI specification
```

## References

- **GitHub**: https://github.com/serversideup/docker-php
- **Documentation**: https://serversideup.net/open-source/docker-php/docs
- **Environment Variables**: https://serversideup.net/open-source/docker-php/docs/reference/environment-variable-specification
- **Laravel Guide**: https://serversideup.net/open-source/docker-php/docs/framework-guides/laravel
- **Docker Hub**: https://hub.docker.com/r/serversideup/php/

## Key Gotchas

1. **Port 8080**: Container cannot use ports < 1024 (non-root user). Always use 8080, not 80.

2. **OPcache in Development**: Disabled by default because it caches file changes. Enable for production only.

3. **Autorun Migrations**: If `AUTORUN_ENABLED=true` and database is unreachable, container won't start. Use `AUTORUN_DEBUG=true` to troubleshoot.

4. **Extensions**: The "full" variant includes most common extensions. Adding new ones requires a custom Dockerfile.

5. **SSL Certificates**: Self-signed certs from `SSL_MODE=full` are for development. Use real certificates in production.

---

**Last Updated**: 2026-01-24
**Image Version**: serversideup/php:8.3-fpm-apache-bookworm-full
**Documentation Version**: 1.0
