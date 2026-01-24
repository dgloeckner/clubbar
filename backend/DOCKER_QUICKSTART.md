# Backend Docker Quick Start

## Setup (First Time Only)

```bash
# 1. Install PHP dependencies on host machine
cd backend && composer install && cd ..

# 2. Copy environment file (if needed)
# cp backend/.env.example backend/.env
# The .env file should already be generated, but here's how to create one:
# APP_KEY can be generated with: cd backend && php artisan key:generate --show
```

## Start Development Environment

**IMPORTANT**: Always pass host user UID/GID to match permissions with container

```bash
# Start all containers with correct UID/GID (fixes file permission issues)
UID=$(id -u) GID=$(id -g) docker compose up -d

# Verify backend is running
curl http://localhost:8080/api/health

# Should respond with:
# {"status":"ok","timestamp":"2026-01-24T12:30:45Z"}
```

**Why the UID/GID?**
The development Dockerfile.dev uses build arguments to set container user permissions to match your host user. This prevents "Permission denied" errors when accessing mounted files. See `FILE_PERMISSIONS.md` for details.

## Common Tasks

### View Logs

```bash
# All containers
docker compose logs -f

# Backend only
docker compose logs -f backend

# Database only
docker compose logs -f database
```

### Run Artisan Commands

```bash
# Inside container
docker compose exec backend php artisan migrate
docker compose exec backend php artisan tinker
docker compose exec backend composer require package/name

# Or locally (if dependencies installed)
cd backend && php artisan migrate && cd ..
```

### Stop Containers

```bash
# Stop without removing
docker compose stop

# Stop and remove (data persists in volumes)
docker compose down

# Stop, remove, and clear database volume
docker compose down -v
```

### Restart Backend

```bash
docker compose restart backend
```

### Access Container Shell

```bash
docker compose exec backend bash
```

## Accessing the API

**Base URL**: `http://localhost:8080`

```bash
# Health check
curl http://localhost:8080/api/health

# Get members
curl http://localhost:8080/api/sync/members

# Get products
curl http://localhost:8080/api/sync/products

# See api/terminal.yaml for full OpenAPI spec
```

## Docker Setup Details

For comprehensive documentation, see **DOCKER_SETUP.md**

- Image: `serversideup/php:8.3-fpm-apache-bookworm-full`
- Port: 8080
- Document Root: `/var/www/html/public`
- User: www-data (non-root)

## Troubleshooting

### "Port 8080 already in use"

```bash
# Kill the container
docker compose down

# Or use a different port in docker-compose.yml:
# ports:
#   - "8081:80"
```

### "Permission denied" errors

```bash
# Fix permissions on host
chmod -R 777 backend/storage backend/bootstrap/cache

# Or inside container
docker compose exec backend chmod -R 775 storage bootstrap/cache
```

### Container won't start

```bash
# Check logs
docker compose logs backend

# Common causes:
# - .env file missing
# - Insufficient disk space
# - Corrupted volumes (try: docker compose down -v)
```

### "No such file" errors

```bash
# Ensure all mounted files exist on host
ls backend/vendor/autoload.php      # Composer installed?
ls backend/.env                      # .env file exists?
ls backend/public/index.php          # Laravel installed?
```

## Quick Reference

| Task | Command |
|------|---------|
| Start containers | `docker compose up -d` |
| Stop containers | `docker compose down` |
| View logs | `docker compose logs -f backend` |
| Run migration | `docker compose exec backend php artisan migrate` |
| Install package | `docker compose exec backend composer require pkg/name` |
| Restart backend | `docker compose restart backend` |
| Enter container | `docker compose exec backend bash` |
| Check health | `curl http://localhost:8080/api/health` |

---

**Note**: For advanced configuration, see **DOCKER_SETUP.md**
