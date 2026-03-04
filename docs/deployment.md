# Production Deployment Guide

This guide covers deploying Club Bar on standard PHP shared hosting (e.g., Hetzner, IONOS, Strato).

---

## Requirements

- PHP 8.3 or higher
- MySQL 5.7+ or MariaDB 10.5+
- Apache with mod_rewrite enabled
- PHP extensions: `pdo_mysql`, `json`, `mbstring`

## Installation

1. Download the latest release ZIP from [GitHub Releases](https://github.com/dgloeckner/clubbar/releases)
2. Extract and upload all files to your web hosting document root (e.g., `public_html/`)
3. Ensure `backend/storage/` and `backend/logs/` directories are writable by the web server:
   ```bash
   chmod 755 backend/storage backend/logs
   ```
4. Open your domain in a browser — you will be redirected to the **Installation Wizard** (`install.php`)

### Installation Wizard

The installer guides you through five steps:

1. **Prerequisites Check** — verifies PHP version, required extensions, and writable directories. All checks must pass before proceeding.
2. **Database Configuration** — enter your database host, port, name, username, and password. Use the **"Test Connection"** button to verify credentials before continuing. The installer writes `config.php` automatically.
3. **Run Migrations** — click **"Run Migrations"** to create the database tables.
4. **Create Admin User** — enter email and password for the first admin account.
5. **Done** — installation complete. Follow the link to open the Admin Panel.

> **Security:** After installation, delete or rename `install.php` to prevent unauthorized access:
> ```bash
> rm install.php
> ```

---

## Security Hardening

### HTTPS

Always use HTTPS in production. Enable SSL in your hosting panel (most providers offer free Let's Encrypt certificates).

### Application Security

- **Protect `config.php`** — ensure it is not publicly downloadable (the `.htaccess` file should already block this; verify by requesting `https://your-domain.com/config.php` in a browser)
- **Delete `install.php`** after setup — restore it from the release ZIP only when upgrading

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

mysqldump -u clubbar_prod -p'PASSWORD' clubbar | gzip > "$BACKUP_DIR/clubbar_$DATE.sql.gz"

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
mysqldump -u clubbar_prod -p clubbar > backup.sql
```

### Restore from Backup

```bash
gunzip < backup.sql.gz | mysql -u clubbar_prod -p clubbar
```

### Pre-Upgrade Backup

Always create a backup before upgrading:
```bash
# 1. Backup database
mysqldump -u clubbar_prod -p clubbar > pre-upgrade-backup.sql

# 2. Backup config file
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

1. Create a pre-upgrade backup (see above)
2. Download the new release ZIP
3. Upload and overwrite all files — `config.php` is preserved since it is not in the ZIP
4. If `install.php` was deleted, restore it from the new release
5. Open `https://your-domain.com/install.php?update=1` in a browser and run pending migrations (step 3 of the wizard). The admin user step is skipped in update mode.
6. Verify the Admin Panel loads and the health endpoint responds
7. Delete `install.php` again

---

## Rollback

If an upgrade causes issues, restore the previous version:

1. Re-upload the previous release ZIP files
2. Restore the database:
   ```bash
   mysql -u clubbar_prod -p clubbar < pre-upgrade-backup.sql
   ```
3. Verify the health endpoint responds correctly

---

## Terminal App Deployment

The terminal is a Flutter desktop app that runs on Raspberry Pi, Linux, or macOS. It connects to the backend API for syncing members, products, and transactions.

For full terminal installation instructions (hardware setup, autostart, screen blanking, RFID configuration), see:

**[Terminal Installation Guide](../terminal-frontend/INSTALL.md)**

Key points:
- Terminal requires `apiUrl` and `apiToken` in its `config.json` pointing to your production backend
- Generate the API token in the Admin Panel under *Terminals*
- The terminal operates offline and syncs periodically when connected
