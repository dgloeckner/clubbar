# File Permissions Guide for serversideup/php Image

## The Problem

When mounting Laravel source code from a macOS host into the `serversideup/php` container:

- **Host files** are owned by your user (UID 501 on macOS)
- **Container** runs as `www-data` (UID 33)
- **Result**: Permission mismatches → hangs, "Permission denied" errors

When you run `docker exec backend php index.php`, the command hangs because the PHP process (running as UID 33) cannot read files owned by UID 501.

## Why This Image Doesn't Use PUID/PGID

The `serversideup/php` image **intentionally does NOT support** PUID/PGID environment variables like other PHP images.

**Reason**: Security design philosophy
- Avoids running containers with elevated privileges
- Prevents root-owned files created during initialization (which then become inaccessible)
- Forces explicit configuration of who can run the image

**Solution**: Use build-time configuration via Docker build arguments

---

## Recommended Solution: Multi-Stage Build

Create a `Dockerfile` for development that matches the host user's UID/GID:

**File**: `backend/Dockerfile.dev`

```dockerfile
# Development Dockerfile
# Build with: docker build -f backend/Dockerfile.dev --build-arg USER_ID=$(id -u) --build-arg GROUP_ID=$(id -g) -t ruderbar-backend-dev .

FROM serversideup/php:8.3-fpm-apache-bookworm as development

# Accept build arguments for host user matching
ARG USER_ID=1000
ARG GROUP_ID=1000

# Change www-data to match host user UID/GID
USER root
RUN docker-php-serversideup-set-id www-data ${USER_ID}:${GROUP_ID} && \
    docker-php-serversideup-set-file-permissions --owner ${USER_ID}:${GROUP_ID}

USER www-data

# Production stage (uses default 33:33)
FROM serversideup/php:8.3-fpm-apache-bookworm as production
# (no changes - uses secure defaults)
```

**Update**: `docker-compose.yml`

```yaml
services:
  backend:
    build:
      context: .
      dockerfile: backend/Dockerfile.dev
      args:
        USER_ID: ${UID:-1000}
        GROUP_ID: ${GID:-1000}
      target: development
    # ... rest of config
```

**Setup Script**: Create `.env.local` for macOS

```bash
#!/bin/bash
# File: .env.docker (add to .gitignore)

# macOS: Match host user UID/GID with container
export UID=$(id -u)
export GID=$(id -g)
```

**Usage**:

```bash
# Load environment and start
source .env.docker
docker compose up -d

# Or one-liner
UID=$(id -u) GID=$(id -g) docker compose up -d
```

---

## How It Works

### Development Build

When you run `docker compose up` with the build arguments:

1. Docker builds the image with your UID/GID (e.g., 501:20 on macOS)
2. `docker-php-serversideup-set-id` changes www-data to UID 501:GID 20
3. `docker-php-serversideup-set-file-permissions` updates service files for the new user
4. Container runs as www-data (but with your host's UID/GID)
5. **Result**: All files readable/writable by container, no permission issues

### Production Build

When deploying to production, use `target: production`:

```yaml
# Production docker-compose.yml
services:
  backend:
    image: serversideup/php:8.3-fpm-apache-bookworm  # Uses pre-built image
    # No build needed
```

The production image uses secure defaults (UID 33:GID 33).

---

## What Files Are Affected

The `set-id` and `set-file-permissions` commands update permissions for:

- PHP-FPM process user and configuration
- Apache process user and configuration
- Supervisor process (if used)
- Log directories and files
- Socket file locations

This ensures all services run with the correct UID/GID.

---

## Implementation Steps

### Step 1: Create Development Dockerfile

Create `backend/Dockerfile.dev` (see above)

### Step 2: Update docker-compose.yml

Change the backend service to use the build configuration:

```yaml
backend:
  build:
    context: .
    dockerfile: backend/Dockerfile.dev
    args:
      USER_ID: ${UID:-1000}
      GROUP_ID: ${GID:-1000}
    target: development
  # ... rest stays the same
```

### Step 3: Start Containers

```bash
# macOS / Linux
UID=$(id -u) GID=$(id -g) docker compose up -d

# Or create .env.local
echo 'export UID=$(id -u)' > .env.local
echo 'export GID=$(id -g)' >> .env.local
source .env.local && docker compose up -d
```

### Step 4: Verify

```bash
# Should work now
docker exec backend php /var/www/html/public/index.php

# And this should return JSON
curl http://localhost:8080/api/health
```

---

## Known Issues & Workarounds

### Issue: Hanging on `docker exec php ...`

**Cause**: File ownership mismatch

**Solution**: Rebuild with correct UID/GID
```bash
UID=$(id -u) GID=$(id -g) docker compose up -d --build
```

### Issue: "Permission denied" on storage/ or bootstrap/cache/

**Cause**: Mounted volume permissions wrong

**Solution 1**: Rebuild with correct UID/GID
```bash
UID=$(id -u) GID=$(id -g) docker compose up -d --build
```

**Solution 2**: Manual fix (temporary)
```bash
# Inside container
docker compose exec backend chmod -R 777 storage bootstrap/cache

# Or from host
chmod -R 777 backend/storage backend/bootstrap/cache
```

### Issue: File changes on host not reflected in container

**Cause**: Could be macOS Docker volume caching

**Solution**:
```bash
# Restart containers to refresh mounts
docker compose restart

# Or full rebuild
docker compose down && UID=$(id -u) GID=$(id -g) docker compose up -d
```

### Issue: "/composer directory permissions out of sync" after build

**Known issue** ([#502](https://github.com/serversideup/docker-php/issues/502))

**Workaround**:
```bash
# Run manually after image build completes
docker compose exec -u root backend chown -R www-data:www-data /composer
```

---

## Why This Approach?

| Aspect | Benefit |
|--------|---------|
| **Build Arguments** | Explicit, secure, no privileged containers |
| **Multi-stage** | Development-optimized AND production-secure |
| **No Root** | Container never runs as root (best practice) |
| **Portable** | Same Dockerfile works across dev machines |
| **Tested** | serversideup's recommended approach |

---

## macOS-Specific Notes

**Important**: Docker Desktop on macOS runs a Linux VM. File permissions mapping:
- Host user 501 → appears as 501 in VM mounts
- Container UID 33 → different from VM mapping
- **Result**: Mismatch requires explicit UID/GID matching

**Docker Desktop behavior varies by version** — if you experience issues:
1. Update Docker Desktop to latest
2. Ensure "Use new virtualization framework" is enabled (Settings → Resources)
3. Check `/etc/docker/daemon.json` for any unusual settings

---

## Testing File Permissions

```bash
# Check host UID/GID
id

# Check container UID/GID
docker compose exec backend id

# Check file ownership in container
docker compose exec backend ls -la /var/www/html/storage/

# Should show www-data as owner with host UID (e.g., 501:20)
# Example output:
# drwxr-xr-x 5 www-data www-data  160 Jan 24 12:00 storage/
# But UID shows as 501 (your host user)
```

---

## References

- [serversideup/php - Understanding File Permissions](https://serversideup.net/open-source/docker-php/docs/guide/understanding-file-permissions)
- [serversideup/php - Command Reference](https://serversideup.net/open-source/docker-php/docs/reference/command-reference)
- [GitHub Issue #498 - Storage Permissions](https://github.com/serversideup/docker-php/discussions/498)
- [GitHub Issue #502 - Composer Directory Permissions](https://github.com/serversideup/docker-php/issues/502)
- [Docker Volume Permissions Problem](https://www.joyfulbikeshedding.com/blog/2021-03-15-docker-and-the-host-filesystem-owner-matching-problem.html)

---

**Last Updated**: 2026-01-24
