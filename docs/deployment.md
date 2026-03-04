# Production Deployment Guide

This guide covers deploying Club Bar in a production environment. Choose one of two deployment options depending on your infrastructure.

---

## Deployment Options

### Option A: Self-Hosted Package (Shared Hosting)

Best for clubs with standard PHP shared hosting (e.g., Hetzner, IONOS, Strato).

**Requirements:**
- PHP 8.3 or higher
- MySQL 5.7+ or MariaDB 10.5+
- Apache with mod_rewrite enabled
- PHP extensions: `pdo_mysql`, `json`, `mbstring`

**Steps:**

1. Download the latest release ZIP from [GitHub Releases](https://github.com/dgloeckner/clubbar/releases)
2. Extract and upload all files to your web hosting document root (e.g., `public_html/`)
3. Ensure `backend/storage/` and `backend/logs/` directories are writable by the web server:
   ```bash
   chmod 755 backend/storage backend/logs
   ```
4. Open your domain in a browser -- you will be redirected to `/install.php`
5. Follow the installation wizard:
   - **Step 1**: Prerequisites check (PHP version, extensions, writable dirs)
   - **Step 2**: Enter MySQL/MariaDB database credentials (test connection before saving)
   - **Step 3**: Run database migrations
   - **Step 4**: Create your first admin account
   - **Step 5**: Done -- log in to the Admin Panel

The installer writes a `config.php` file in the document root with your database credentials and application settings (see `config.sample.php` for reference).

### Option B: Docker Compose

Best for self-hosted servers (VPS, dedicated, or on-premises).

**Requirements:**
- Docker Engine 20+ and Docker Compose v2
- 1 GB RAM minimum
- Ports 80/443 available

**Steps:**

1. Clone the repository:
   ```bash
   git clone https://github.com/dgloeckner/clubbar.git
   cd clubbar
   ```

2. Install backend dependencies:
   ```bash
   cd backend && composer install && cd ..
   ```

3. Create a production `.env` file for the backend:
   ```bash
   cp backend/.env.example backend/.env
   ```
   Edit `backend/.env` with production values:
   ```env
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://your-domain.com

   DB_HOST=database
   DB_PORT=3306
   DB_NAME=clubbar
   DB_USER=clubbar_prod
   DB_PASS=<strong-random-password>

   SESSION_MAX_AGE=7200
   SESSION_REGEN_INTERVAL=900
   API_TOKEN_TTL_DAYS=90

   CORS_ORIGINS=https://your-domain.com
   INSTALL_KEY=<random-secret-for-install-endpoint>
   ```

4. Update `docker-compose.yml` environment variables to match:
   - Set `MYSQL_PASSWORD` and `DB_PASS` to your chosen database password
   - Set `MYSQL_ROOT_PASSWORD` to a different strong password
   - Set `APP_ENV=production`, `APP_DEBUG=false`
   - Set `CORS_ORIGINS` to your domain (not `*`)
   - Set `PHP_OPCACHE_ENABLE=1` for production performance

5. Start services:
   ```bash
   docker compose up -d
   ```

6. Run database migrations:
   ```bash
   docker compose exec -T backend sh -c "cd /app && php artisan migrate"
   ```

7. Verify the health endpoint:
   ```bash
   curl http://localhost:8080/api/health
   ```

---

## Security Hardening

### HTTPS with Let's Encrypt

Always use HTTPS in production. For shared hosting, enable SSL in your hosting panel. For Docker, add a reverse proxy:

```bash
# Example: Caddy as reverse proxy (auto-HTTPS with Let's Encrypt)
# Caddyfile
your-domain.com {
    reverse_proxy localhost:8080
}
```

Alternatives: nginx with certbot, or Traefik.

### Application Security

- **Set `APP_ENV=production`** -- disables debug output and stack traces
- **Set `APP_DEBUG=false`** -- prevents detailed error messages from leaking to users
- **Restrict CORS** -- set `CORS_ORIGINS` to your exact domain, never use `*` in production
- **Delete or rename `install.php`** after initial setup (self-hosted package):
  ```bash
  mv install.php install.php.bak
  ```
  For updates, temporarily rename it back and visit `?update=1`
- **Protect `config.php`** -- ensure it is not publicly downloadable (Apache `.htaccess` should already block this; verify by requesting `https://your-domain.com/config.php` in a browser)
- **Set a strong `INSTALL_KEY`** (Docker) -- the backend `install.php` endpoint requires this key via `X-Install-Key` header or `?key=` parameter

### Database Security

- Create a dedicated database user with minimal privileges:
  ```sql
  CREATE USER 'clubbar_prod'@'localhost' IDENTIFIED BY '<strong-password>';
  GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP
    ON clubbar.* TO 'clubbar_prod'@'localhost';
  FLUSH PRIVILEGES;
  ```
- Disable remote root access
- Use a different password for the database root account

### Firewall

Expose only the ports you need:
- **80** (HTTP, redirect to HTTPS)
- **443** (HTTPS)
- **3306** should NOT be exposed publicly (Docker: remove the `ports: - "3306:3306"` mapping in production)

---

## Database Backup & Restore

### Automated Daily Backups (Cron)

Create a backup script at `/opt/clubbar/backup.sh`:

```bash
#!/bin/bash
BACKUP_DIR="/opt/clubbar/backups"
RETENTION_DAYS=30
DATE=$(date +%Y-%m-%d_%H%M%S)

mkdir -p "$BACKUP_DIR"

# Docker deployment
docker compose exec -T database mysqldump -u clubbar -pCLUBBAR_PASSWORD clubbar \
  | gzip > "$BACKUP_DIR/clubbar_$DATE.sql.gz"

# Shared hosting (direct mysqldump)
# mysqldump -u clubbar_prod -p'PASSWORD' clubbar | gzip > "$BACKUP_DIR/clubbar_$DATE.sql.gz"

# Remove backups older than retention period
find "$BACKUP_DIR" -name "clubbar_*.sql.gz" -mtime +$RETENTION_DAYS -delete

echo "Backup complete: clubbar_$DATE.sql.gz"
```

Add to crontab (`crontab -e`):
```
0 3 * * * /opt/clubbar/backup.sh >> /var/log/clubbar-backup.log 2>&1
```

### Manual Backup

```bash
# Docker
docker compose exec -T database mysqldump -u clubbar -pPASSWORD clubbar > backup.sql

# Shared hosting
mysqldump -u clubbar_prod -p clubbar > backup.sql
```

### Restore from Backup

```bash
# Docker
gunzip < backup.sql.gz | docker compose exec -T database mysql -u clubbar -pPASSWORD clubbar

# Shared hosting
gunzip < backup.sql.gz | mysql -u clubbar_prod -p clubbar
```

### Pre-Upgrade Backup

Always create a backup before upgrading:
```bash
# 1. Backup database
docker compose exec -T database mysqldump -u clubbar -pPASSWORD clubbar > pre-upgrade-backup.sql

# 2. Backup config file (self-hosted package)
cp config.php config.php.pre-upgrade

# 3. Proceed with upgrade (see Upgrading section)
```

---

## Monitoring

### Health Endpoint

Poll the health endpoint to verify the backend is running:

```bash
curl -s https://your-domain.com/api/health | jq .
```

Set up a cron job or external monitoring service (e.g., UptimeRobot) to check every 5 minutes.

### Application Logs

The backend writes JSON-formatted logs to daily files:

```bash
# Docker
docker compose exec backend tail -50 /app/logs/$(date +%Y-%m-%d).log | jq .

# Shared hosting
tail -50 backend/logs/$(date +%Y-%m-%d).log | jq .
```

Log format: `{ts, level, channel, msg, ctx}`. Look for `level: "ERROR"` and `level: "CRITICAL"` entries.

### Slow Query Monitoring

Enable the MariaDB slow query log in production:

```sql
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 2;
SET GLOBAL slow_query_log_file = '/var/log/mysql/slow-query.log';
```

---

## Upgrading

### Self-Hosted Package

1. Create a pre-upgrade backup (see above)
2. Download the new release ZIP
3. Upload and overwrite all files -- `config.php` is preserved since it is not in the ZIP
4. If `install.php` was deleted, restore it temporarily
5. Visit `https://your-domain.com/install.php?update=1` to run pending migrations
6. Verify the Admin Panel loads and the health endpoint responds
7. Delete `install.php` again

### Docker Compose

1. Create a pre-upgrade backup (see above)
2. Pull the latest code:
   ```bash
   git pull origin main
   ```
3. Update dependencies:
   ```bash
   cd backend && composer install --no-dev --optimize-autoloader && cd ..
   ```
4. Run database migrations:
   ```bash
   docker compose exec -T backend sh -c "cd /app && php artisan migrate"
   ```
5. Restart PHP to pick up code changes:
   ```bash
   docker compose exec backend supervisorctl restart php-fpm:php-fpmd
   ```
6. Verify the health endpoint:
   ```bash
   curl -s https://your-domain.com/api/health | jq .
   ```

---

## Rollback

If an upgrade causes issues, restore the previous version:

1. **Restore code**:
   - Self-hosted: Re-upload the previous release ZIP files
   - Docker: `git checkout <previous-tag>`
2. **Restore database**:
   ```bash
   # Docker
   cat pre-upgrade-backup.sql | docker compose exec -T database mysql -u clubbar -pPASSWORD clubbar

   # Shared hosting
   mysql -u clubbar_prod -p clubbar < pre-upgrade-backup.sql
   ```
3. Restart services:
   ```bash
   docker compose exec backend supervisorctl restart php-fpm:php-fpmd
   ```
4. Verify the health endpoint responds correctly

---

## Terminal App Deployment

The terminal is a Flutter desktop app that runs on Raspberry Pi, Linux, or macOS. It connects to the backend API for syncing members, products, and transactions.

For full terminal installation instructions (hardware setup, autostart, screen blanking, RFID configuration), see:

**[Terminal Installation Guide](../terminal-frontend/INSTALL.md)**

Key points:
- Terminal requires `apiUrl` and `apiToken` in its `config.json` pointing to your production backend
- Generate the API token in the Admin Panel under *Terminals*
- The terminal operates offline and syncs periodically when connected
