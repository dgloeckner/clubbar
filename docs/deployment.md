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
3. Nothing to `chmod`. The ZIP already carries the modes it should be installed with — `0700` on `backend/storage/` and `backend/logs/`, `0755` on the document root — and the installer narrows anything your extraction widened (see [File Permissions](#file-permissions)). Only if extraction left those two directories unwritable by PHP:
   ```bash
   chmod 700 backend/storage backend/logs   # 0770 or 0777 only if your host runs PHP as another user
   ```
4. Open your domain in a browser — you will be redirected to the **Installation Wizard** (`install.php`)

### Installation Wizard

The installer guides you through five steps:

1. **Prerequisites Check** — verifies PHP version, required extensions, and writable directories; reports where your data will be placed (see below); and fetches a file out of `backend/storage/` over HTTP to confirm your host actually refuses it. All checks marked ✗ must pass before proceeding. A **!** is a warning — the install will work, but a protection this host does not offer is being recorded rather than hidden.
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

### Checking It Actually Applied

Everything in this section is something a host can decline to do. `.htaccess` is honoured at the host's discretion, `.user.ini` may never be read, and either can change under a running installation after a tariff migration or a server swap — with no error, nothing in the log, and `/api/health` still answering `{"status":"ok"}`.

So Club Bar measures instead of assuming ([ADR-0031](../adr/0031-production-hardening-on-shared-hosting.md) decision 3). The same set of checks runs in three places:

| Where | When | Answers |
|---|---|---|
| The installer's **prerequisites** screen | Before you enter the database credentials | "Is this host safe to install on?" |
| **Settings → Security** in the admin panel | Whenever you open it | "Did a host change break something since?" |
| The package smoke test in CI | Every build | "Did we regress the package?" |

Every row reports what was *observed* — `ini_get()` for the PHP settings, `stat()` for file permissions, and for the two that matter most a canary file written where a scanned mandate lives and fetched back over HTTP. Reading the rows:

| | Meaning |
|---|---|
| **✓** | The effective state was observed to be the intended one |
| **!** | Weaker than intended, and the installation still works — usually something this host does not offer |
| **✕** | Credentials or member documents are reachable. The row says what is exposed and what to do |
| **?** | Could not be measured from here. Never counted as a pass, and the row says what to check by hand |

A **!** or a **?** is not a failure to fix at any cost: "your host does not allow this" is an accepted outcome, and the remedy text says so where that is the case. What the report exists to prevent is not knowing.

The panel's report is admin-authenticated, because it is a map of the installation's weak points and names the directories the mandates are kept in.

### Where Your Data Is Kept

Three things must never be downloadable: `config.php` (database password and TOTP encryption key), the scanned SEPA mandates (a name, an IBAN and a handwritten signature each) and the application logs. The mandate filenames are the member UUIDs the admin API already hands to the browser, so they are enumerable, not secret.

The installer therefore **looks for a writable directory above your document root** and, if it finds one, puts all three there ([ADR-0031](../adr/0031-production-hardening-on-shared-hosting.md) decision 2):

| Layout | When | Where things go | What protects them |
|---|---|---|---|
| **Relocated** (preferred) | The directory above your document root is writable — the usual case on IONOS and most mass hosting | `<parent>/clubbar-data/{config.php,storage/,logs/}`, named by `data-path.php` in the document root | Not reachable by any URL. Nothing to misconfigure |
| **In the document root** (fallback) | No writable parent directory | `config.php` next to `index.php`; `backend/storage/`, `backend/logs/` | The `.htaccess` rules shipped with Club Bar — which your host may stop honouring after a tariff or server change |

The installer tells you which one you got, on the last screen. The fallback is fully supported — a hosting account with no writable parent must stay installable — it is just the *degraded* one, and it says so.

**`data-path.php` matters.** It is one line naming your data directory. Delete it and the application looks for its config next to `index.php`, finds none, and sends you back to the installer. It is a `.php` file on purpose: a host that ignored `.htaccess` would print a `.txt` path file and execute this one.

**Moving an existing installation.** `upgrade.php` offers the move as **step 4**, after migrations — a button naming the destination, never something it does on its own. Files are copied first and the originals removed only once the copies are in place, so a failed move leaves the installation running exactly where it was. The same screen offers the move back.

To place the data somewhere specific instead, create the directory yourself, move `config.php`, `storage/` and `logs/` into it, and write the pointer:

```php
<?php  // data-path.php, in the document root
return '/home/youraccount/clubbar-data';
```

**Verify:**

```bash
# The mandate store must never answer with a document
curl -sI https://your-domain.com/backend/storage/mandates/ | head -1   # expect 403 or 404

# In the relocated layout, there is nothing under the document root to serve
ls -la /home/youraccount/clubbar-data     # config.php, storage/, logs/
```

### File Permissions

On mass hosting the neighbours are the threat model. Tenants are separated by uid, so a file that is *world*-readable is readable by every other customer whose account lands on the same machine — and by anything already running as another user on it, such as a compromised neighbour or a shared backup agent. Club Bar therefore ships and maintains the narrowest modes it can ([ADR-0031](../adr/0031-production-hardening-on-shared-hosting.md) decision 4):

| Path | Mode | Why not wider |
|---|---|---|
| `config.php` | `0600` | The database password and the key that encrypts every admin's second factor |
| `storage/` | `0700` | The scanned SEPA mandates: per member a name, an IBAN and a signature |
| `logs/` | `0700` | Request context, including identifiers of the members involved |
| The document root | `0755` | It is served, so it stays readable — but a *writable* document root lets anything with a foothold drop in a `.php` file the webserver will execute |

Three things happen without you doing anything:

- **The release ZIP carries these modes**, and the build fails rather than publishing an archive with a world-writable path in it.
- **The installer narrows what it finds**, because a control-panel extractor or an FTP client may apply its own modes to everything it writes.
- **`upgrade.php` narrows an existing installation** the same way, which is how an install unpacked from an older package — those shipped `0777` on `storage/`, `logs/` and the document root — gets fixed.

Every one of those steps *verifies* rather than assumes. A narrower mode is applied, the path is then used — a file read back, a directory written to — and a mode that broke it is put back rather than left behind. This matters on hosts where PHP does not run as the user that owns the files: there, `0700` on `logs/` would mean an application that can no longer write its own log. The ladder tried is `0700 → 0770 → 0777` for directories and `0600 → 0640 → 0644` for `config.php`, narrowest first, and the mode that survives is whatever the host tolerated.

Which is why the result is *reported* rather than promised: **Settings → Security** shows the mode of each of these four paths as `stat()` sees it. A row that is not green is your host declining, and it says so.

**Verify:**

```bash
ls -la <data-directory>            # config.php -rw-------, storage/ and logs/ drwx------
stat -c '%a %n' .                  # 755, in the document root — never 777
```

### PHP Runtime Settings

**The application configures these itself, on every request, before anything else runs.** There is no `php.ini` to edit on mass hosting, and a `.user.ini` is honoured at the host's discretion — so the settings the deployment depends on live in code (`backend/src/Shared/Security/RuntimeHardening.php`, [ADR-0031](../adr/0031-production-hardening-on-shared-hosting.md) decision 1). Nothing you need to do, and nothing your host can silently drop:

| Setting | Value | Why |
|---|---|---|
| `display_errors`, `display_startup_errors` | off (on with `app.debug`) | A database outage happens before the API's error handler exists. On a host that prints errors by default, the stack trace — with the connection arguments — goes to the browser |
| `log_errors` | on | The same failure still has to be findable afterwards |
| `zend.exception_ignore_args` | on (off with `app.debug`) | Keeps the database password out of the trace that *is* recorded |
| `session.use_strict_mode` | on | PHP's default is off, which means it accepts a session ID a visitor made up — the half of session fixation that regenerating the ID at login cannot cover |
| `session.use_only_cookies`, `session.use_trans_sid` | on / off | A session ID may never travel in a URL, where it leaks through `Referer` headers and access logs |
| `session.save_path` | `storage/sessions` in the data directory | By default PHP writes session files into a directory shared with the host's other accounts, where a readable session file is an admin login |
| `X-Powered-By` | removed | `expose_php` is `PHP_INI_SYSTEM` and out of reach on shared hosting; removing the header at runtime is the only lever available |

Two consequences worth knowing about:

- **Session files move on upgrade.** Everyone signed in at the moment of the upgrade is signed out once, because PHP looks for their session in the new directory. Nothing else is affected.
- **`session.save_path` is applied only if the directory is writable.** An unwritable path would mean nobody can log in at all, so the app keeps the host default instead and records a `WARNING` in the day's log file. If you see one, fix the permissions on the data directory's `storage/` and restart.

Sessions follow the data directory, so an installation whose data was placed above the document root keeps its sessions there too. To put them somewhere else entirely, set `save_path` in `config.php`:

```php
'session' => [
    'max_age' => 7200,
    'regeneration_interval' => 900,
    'save_path' => '/home/youraccount/clubbar-data/sessions',  // must be writable by the web server
],
```

**Verify:**

```bash
curl -I https://your-domain.com/api/health
# X-Powered-By must be absent

# Nothing but session files, readable by nobody else:
ls -la <data-directory>/storage/sessions/   # drwx------, files -rw-------
```

### HTTPS & TLS

HTTPS is mandatory in production. See [ADR-0016](../adr/0016-transport-security.md) for full security requirements.

The package `.htaccess` already includes HTTPS redirect (skipped on localhost for dev/CI), HSTS, and security headers (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`). GZIP compression is also pre-configured.

**Setup:**
1. Enable SSL in your hosting panel (most providers offer free Let's Encrypt certificates)
2. Make sure the app knows it is HTTPS-facing, so the `_session` cookie is marked `Secure` and never travels in clear text: set `app.url` in `config.php` (or `APP_URL` in `.env`) to the `https://` address. The installer writes it from the scheme it was reached on, so installing over HTTPS already does the right thing. `SESSION_COOKIE_SECURE` / `session.cookie_secure` forces the flag on or off if the derivation is wrong for your setup.

**Verify:**

```bash
curl -I https://your-domain.com/api/health
# Check for: Strict-Transport-Security, X-Content-Type-Options headers

# Log in to the admin panel, then check the login response in the browser's
# network tab: the _session Set-Cookie must carry Secure; HttpOnly; SameSite=Lax
```

### Application Security

- **Protect `config.php`** — best done by having it outside the document root entirely (see *Where Your Data Is Kept* above). If your host forced the fallback layout, verify it is not downloadable by requesting `https://your-domain.com/config.php` in a browser: you must get an empty page or an error, never the file's text
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

## Automated Production Deployment

The production site is deployed by the **Deploy to Production** workflow
(`.github/workflows/deploy-production.yaml`), run manually from the Actions tab.

**To deploy:** pick the workflow, click *Run workflow*, and fill in two fields —
the release tag (or `latest`) and the production hostname as a confirmation. The
run then waits for an approval on the `ionos-production` environment before it
touches anything.

What it does, in order:

1. Validates the confirmation and resolves the tag, failing before any upload if
   the release does not exist or does not carry exactly one package ZIP
2. Downloads that ZIP **from the release** — the exact bytes CI smoke-tested,
   not a rebuild
3. Uploads three files over SFTP: `upgrade.php`, the ZIP, and a one-time secret
4. Calls `upgrade.php?action=extract` to unpack it server-side
5. Calls `upgrade.php` to run migrations; the script then deletes itself
6. Deletes `install.php`, which every extract re-creates
7. Asserts `/api/health` reports `status: ok` **and** the version equals the
   deployed tag

**Deploying an older tag fails.** `upgrade.php` compares the package version
against the installed one and answers `409`, so a mistaken tag cannot silently
roll the code back underneath a newer database schema. Recovery from a bad
release is a restore, not a redeploy.

**Back up the database first.** Shared hosting gives the workflow no shell, so
no pre-migration dump is taken automatically. The run prints a reminder in its
summary. If the release contains a migration that drops or alters a column, take
a backup before approving it.

**The upgrade secret is generated per run** and is not stored as a GitHub
secret. Nothing on the server pre-shares it: the workflow uploads
`.upgrade-secret`, and `upgrade.php` compares the request key against whatever
that file holds. A successful run deletes both.

**Nothing of the installation is overwritten.** `config.php`, `data-path.php`,
`backend/storage/` and `backend/logs/` are excluded from extraction; everything
else is replaced, and files the new package does not ship are swept away.

> The integration site deploys automatically from `main` via the
> `deploy-integration` job in `build.yaml`, using the same mechanism against a
> separate SFTP account.

### Reading a Failed Deploy

Both workflows call `upgrade.php` through `scripts/deploy-request.sh`, which
turns the server's answer into a named cause rather than a parse error. What the
job log says, and what each one means:

| Message | What happened |
|---------|---------------|
| `served HTML, not JSON` | The request reached the site but not `upgrade.php`. `.htaccess` hands any path that is not a file on disk to `index.php`, which answers with the SPA shell at HTTP **200** — so this is what a missing `upgrade.php` looks like. Either the upload did not land, or the SFTP account's login directory is not the document root the site URL serves. The **List the deploy target** step prints `pwd`, the login directory and a two-level `find`, which settles which of the two it is — and names the directory holding `index.php` |
| `answered HTTP 3xx (redirect to …)` | The request never reached PHP. Usually the forced-HTTPS rule in `.htaccess` answering an `http://` site URL |
| `failed (HTTP 403): Invalid upgrade key.` | `.upgrade-secret` on the server does not match the key in the request |
| `refused a downgrade` | The package is older than what is installed (production only; integration passes `force=1`) |
| `could not be reached` | DNS, TLS or connectivity — the response never arrived |

Uploads fail loudly: both workflows set `cmd:fail-exit yes`, without which lftp
prints a failed `put`, carries on and exits `0` — an upload step that goes green
having transferred nothing.

**Where integration uploads to.** The integration account logs in at
`/clubbar-integration` and the site is served one level below that, from its
`root/` — so the upload `cd`s into `root` before it `put`s anything. The path is
kept **relative** to the login directory on purpose, so it holds whether the
account is chrooted at `/clubbar-integration` (what it is today) or lands in the
webspace directory above it.

This is what caused the three-day outage in August 2026: the workflow was
written when login directory and document root were the same, kept uploading
into the login directory after they diverged, and every extract was answered by
the SPA shell because `upgrade.php` was never in the directory the site serves.

Set the **`IONOS_SFTP_PATH`** variable on the `ionos-integration` environment to
override the directory without a commit, should the site move again. The symptom
to watch for is the listing showing the three uploaded files with no
`index.php`, `spa.html` or `.htaccess` beside them.

Run the same request by hand with the same diagnosis:

```bash
scripts/deploy-request.sh "Extract" \
  "https://your-site.example/upgrade.php?key=<secret>&action=extract&force=1"
```

---

## Upgrading

> Manual steps, for a self-hosted installation. The project's own production
> site uses the automated workflow above.

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
