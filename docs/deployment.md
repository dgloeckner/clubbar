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

**A dump is no longer the whole secret, but it is still personal data
([ADR-0036](../adr/0036-iban-encryption-sealed-box.md)).** Every IBAN in it is
now a libsodium sealed box the dump alone cannot open — but names, emails,
addresses and the last four digits of every IBAN are still in there in the
clear. Encrypt the dump at rest the same as you would have before; the
commands below pipe through `gpg -c` (a passphrase, not a keypair — the
libsodium keypair discussed further down is a separate secret with a separate
job).

### Automated Daily Backups (Cron)

Create a backup script at `/opt/clubbar/backup.sh`:

```bash
#!/bin/bash
BACKUP_DIR="/opt/clubbar/backups"
RETENTION_DAYS=30
DATE=$(date +%Y-%m-%d_%H%M%S)
# Store this in a file the script reads (0600), not inline in the script —
# the same rule as any other credential on this host (ADR-0031 decision 4).
GPG_PASSPHRASE_FILE="/opt/clubbar/.backup-passphrase"

mkdir -p "$BACKUP_DIR"

mysqldump -u clubbar_prod -p'PASSWORD' clubbar \
  | gzip \
  | gpg --batch --yes --passphrase-file "$GPG_PASSPHRASE_FILE" -c \
  > "$BACKUP_DIR/clubbar_$DATE.sql.gz.gpg"

# Remove backups older than retention period
find "$BACKUP_DIR" -name "clubbar_*.sql.gz.gpg" -mtime +$RETENTION_DAYS -delete

echo "Backup complete: clubbar_$DATE.sql.gz.gpg"
```

Add to crontab (`crontab -e`):
```
0 3 * * * /opt/clubbar/backup.sh >> /var/log/clubbar-backup.log 2>&1
```

### Manual Backup

```bash
mysqldump -u clubbar_prod -p clubbar | gpg -c > backup.sql.gpg
```

### Restore from Backup

```bash
gpg -d backup.sql.gz.gpg | gunzip | mysql -u clubbar_prod -p clubbar
```

Restoring an older dump does not touch IBAN recoverability either way: every
`mandates` row it contains is still sealed under whichever `encryption_keys`
row it names, and that row is never deleted (`RESTRICT`, not `CASCADE` —
see the ERM's [Data Integrity Rules](./erm-master.md#data-integrity-rules)).
The only way a restored row becomes unreadable is if the private key it was
sealed under is *also* gone — which is a key-archive problem, not a database
problem, and is exactly why the private key gets its own backup story below
rather than piggybacking on this one.

### Pre-Upgrade Backup

Always create a backup before upgrading:
```bash
# 1. Backup database
mysqldump -u clubbar_prod -p clubbar | gpg -c > pre-upgrade-backup.sql.gpg

# 2. Backup config file
cp config.php config.php.pre-upgrade

# 3. Proceed with upgrade (see Upgrading section)
```

**First deploy of an IBAN-encryption release specifically:** the server
cannot store an IBAN until an admin has registered *and activated* a
libsodium key ([ADR-0036](../adr/0036-iban-encryption-sealed-box.md)) — there
is no legacy plaintext column left to migrate (nothing had shipped when
[migration `020`](../backend/db/migrations/020_drop_legacy_plaintext_iban.sql)
removed it, so there was never a batch-encryption step to run). The order is:

1. Backup (above)
2. Upgrade (below)
3. Generate a keypair offline with `tools/keypair-generator.html` (never
   uploaded anywhere; the private half never touches the server)
4. Register the public half and activate it, from Settings → Security &
   Credentials — every member create/edit that submits an IBAN is a `409`
   until this step is done

There is no step 5. Nothing needs re-encrypting because nothing was ever
stored unencrypted on a shipped release — nothing to run a converse of
"register key → batch-encrypt" against. A **rotation** (swapping the
operational key while mandates already exist) does have a batch step; see
below.

### The Private Key Is the Other Half of This Backup Story

The database backup above is necessary but not sufficient: it is ciphertext,
and this system deliberately keeps no private key anywhere the database
backup — or the server itself — could hand over. **Losing the last valid
private key makes every IBAN sealed under it permanently unrecoverable, no
matter how many database backups exist.** That risk is inherent to sealed
boxes, not a gap to close; it is the tradeoff that keeps a stolen dump or a
compromised server from ever being enough to read a bank account number on
its own.

What this means operationally:

- **Generation is offline, on purpose.** `tools/keypair-generator.html` runs
  entirely in the browser (vendored libsodium.js, no network calls) so the
  private key is created on a machine this system never touches. Do not
  paste it into a chat client, a password manager's "notes" field synced to
  a phone, or anything else with its own backup/sync story you have not
  vetted.
- **Archive it like the safe key, not like a password.** At minimum: one
  copy with the treasurer, one copy offsite (a second board member, a safe
  deposit box). The DB backup and the key archive should never be the *only*
  two copies of anything sitting in the same building.
- **Test the archive, don't just trust it.** A private key file that was
  corrupted on write, saved with the wrong encoding, or is quietly the
  *previous* rotation's key is indistinguishable from a good one until the
  day it is needed — which is the worst possible day to find out.
  Periodically (annually is reasonable), pull a copy of the archived key
  material and confirm it still validates against the *currently ACTIVE*
  key's fingerprint shown on Settings → Security & Credentials. That page's
  full-IBAN view (step-up + the archived key, on one member you don't mind
  looking up) is the practical way to do this without writing a standalone
  script.
- **A compromised key is revoked immediately, regardless of remaining
  lifetime.** Do not wait for the 365-day cryptoperiod to run out — mark it
  compromised (or merely revoked, if the concern is procedural rather than
  "someone may have this private key") as soon as it is suspected, then
  register and activate a replacement and run the rotation batch below
  against it. A revoked or compromised key never quietly becomes `retired`
  once its rows are moved off it the way an ordinary `retiring` key does —
  it keeps saying what happened to it, permanently, which is what makes "was
  this key ever compromised" answerable by looking at the key list instead
  of having to reconstruct it from the audit log. Every state transition is
  audited regardless (`key_registered`, `key_activated`, `key_rotation_*`,
  `key_retired`, `key_revoked`, `key_marked_compromised`).

### Key Rotation (Annual, or on Suspected Compromise)

Unlike the initial-deploy case above, a rotation runs against a database that
already holds sealed mandates, so it has a real batch step. All of it happens
from Settings → Security & Credentials, behind a fresh TOTP step-up:

1. **Register** the new public key (generated the same offline way as the
   first one) — status `PENDING`.
2. **Activate** it. The new key becomes `ACTIVE`. If the old key was still
   `ACTIVE` (the routine annual case), it moves to `RETIRING` automatically;
   if it had already been marked `revoked` or `compromised` (the incident
   case — do that *before* this step, without waiting for a replacement),
   it keeps that status. From this instant, every new or edited IBAN is
   sealed under the new key — the old key's mandates are simply not caught
   up yet.
3. **Run rotation batches** against the old key — `retiring`, `revoked` and
   `compromised` are all rotatable statuses — supplying its private key each
   time; the key is never stored server-side, only held in memory for the
   request. Each batch re-encrypts up to 100 rows with an optimistic
   `UPDATE … WHERE encryption_key_id = :old`, so a member edit landing mid-batch
   loses the race safely rather than corrupting anything. Repeat until the
   outstanding count reaches zero.
4. **Complete the rotation.** This is refused server-side while any row still
   references the old key, so a batch that was skipped or interrupted cannot
   be waved through by mistake. A `retiring` key moves to `RETIRED` on
   success; a `revoked`/`compromised` key keeps that status forever instead —
   what happened to it stays visible in the key list, not just recoverable
   from the audit log.
5. **Archive the old key's private material** the same way as an active one,
   for as long as any Beleg-bearing mandate sealed under it is still within
   its retention window (see the ERM's
   [Retention Periods](./erm-master.md#retention-periods)) — moving off a
   key for new writes is not the same as being done needing to read what it
   already sealed.

---

## Outgoing Mail and the Scheduler

Every SEPA collection has to be announced by email at least seven days before
the due date (Nutzungsordnung § 7 Abs. 3). The application queues those
announcements inside the settlement's own transaction and **sends nothing
itself** — a scheduled drain is the only sending path (ADR-0038). Two
consequences follow, and both are operational rather than technical:

- **No scheduler, no mail.** Direct-debit settlements are refused until a run
  has been observed, with a banner in the admin panel until then.
- **A scheduler that dies later is silent.** Nothing in the application errors;
  announcements simply accumulate. That is what the heartbeat below is for.

### Scheduling the drain

The CLI entrypoint is preferred: no gateway timeout, and no secret in a URL.

```
php /path/to/htdocs/backend/bin/cron.php
```

The admin panel prints this line with the absolute path for *this* installation
(Settings → the scheduler banner, and the installer's prerequisite step). Paste
it into the hosting panel's cron form.

**Interval.** Every 15 minutes is the recommendation; the practical minimum is
tariff-dependent and hourly is common. Declare what the host actually offers
under Settings → Mail (`cron_interval`) — the retry ladder and the stall
thresholds are measured in ticks of it, and the self-check reports when the
declaration and the observed gap between runs disagree.

**Weekly is refused.** An announcement queued shortly after a weekly tick can
leave six days later and land on the collection date itself, taking the § 7
Abs. 3 distance to nothing. A host that can only schedule weekly needs an
external scheduler driving the URL trigger instead (ADR-0039 decision 5).

**No CLI cron?** Set `cron.secret` in `config.php` and schedule a fetch:

```bash
curl -sS -H 'X-Cron-Secret: <secret>' https://your-domain.com/api/cron/drain
```

The header form is the supported one. The query-string variant works and is
degraded: the secret lands verbatim in the webserver's access log. Without
`cron.secret` the route is not mounted at all.

**Rotating the secret without file access.** Settings → Mail in the admin
panel can generate and rotate this secret too (#473), for exactly the
situation above: an external scheduler that only offers a bare URL field
forces the degraded query-string form, and if that secret leaks into an
access log, rotating it from `config.php` needs file access the "relocated
data directory" layout (decision 2 below) may not expose to anything
web-reachable. A panel-rotated secret supersedes `config.php`'s entirely —
the old value stops working the moment a new one is generated, rather than
staying valid alongside it — and it is shown exactly once; only its hash is
stored, the same way terminal API tokens are kept.

### IONOS specifically

IONOS is this project's reference host ([ADR-0031](../adr/0031-production-hardening-on-shared-hosting.md)), and its **Cronjobs** tool (IONOS account → Hosting → Cronjobs) is a webcron: the form takes a URL in an "HTTP GET" field and fetches it on schedule, with no field for a shell command or a PHP path. On a standard IONOS webhosting contract the CLI entrypoint above has nowhere to go — use the URL trigger instead. (This is specific to that panel tool; an IONOS VPS/Cloud Server with SSH access has a real crontab, where the CLI entrypoint applies as documented above.)

Two more points where the wizard doesn't line up with the rest of this section, both already accounted for on our side:

- **Interval.** The wizard's top-level choice is monthly/weekly/daily — there is no hourly or every-N-minutes option. Declare **daily** under Settings → Mail. Never weekly: the wizard offers it, this application refuses it (see above), and it would erode the 7-day announcement distance.
- **Execution time limit.** IONOS aborts a cron call after 60 seconds. `DrainService`'s default run budget is 50 seconds for exactly this reason — a killed run just leaves its remaining rows for the next tick — so there is nothing to configure.

The URL field is a bare URL with no documented way to attach a custom header, so the `X-Cron-Secret` header form above is likely unreachable from the wizard. Use the degraded query-string form instead — `https://your-domain.com/api/cron/drain?secret=<secret>` — and expect the access-log exposure noted above. Confirm against your own contract's form before relying on this: verify whether a header option exists, and rotate `cron.secret` if you conclude it does not.

**An external HTTP scheduler avoids both limits at once.** This is the third option ADR-0038 names precisely so the supported hosting set does not narrow to whatever a given panel's wizard offers. cron-job.org (there are others — Cronitor, EasyCron) supports per-minute intervals and a genuine custom-header field: point it at `/api/cron/drain` every 15 minutes with `X-Cron-Secret: <secret>` as a custom header, and neither IONOS limitation above applies — no daily-only interval, no secret in the URL. Use HTTP Basic Auth only if you also change `CronController` to accept it; today it checks the `X-Cron-Secret` header (or the query-string fallback) and nothing else, so a request authenticated only via `Authorization: Basic …` gets a 401.

Two things worth knowing before relying on this: cron-job.org's own default request timeout (30s at the time of writing) is shorter than `DrainService`'s 50s run budget, so its job history may show occasional "timeouts" even on runs that complete normally server-side — the queue's claim staleness and retry ladder already tolerate a killed run, so this is cosmetic, not a correctness problem. And it is not a substitute for the `cron.heartbeat_url` monitor below: cron-job.org can only tell you the HTTP call didn't respond, not that the mail queue is stalled or SMTP is broken, which is what ADR-0038 rule 6 actually asks for. Declare `hourly`, not `daily`, under Settings → Mail once the actual cadence is 15 minutes — `CronInterval` has no 15-minute value, and the self-check only flags a cadence *slower* than declared, never faster, so declaring the coarser bucket is safe.

### The heartbeat check

Configure `cron.heartbeat_url` in `config.php` with a push monitor's check URL —
healthchecks.io is the reference; Uptime Kuma, Cronitor and Better Stack take
the same shape and self-hosting one is fine. The alarm must live outside this
installation: a report that "SMTP is dead", delivered over the dead SMTP, never
arrives.

| Event | What is pinged |
|---|---|
| A run starts | `<url>/start` — a start with no finish is a hung run |
| A run finishes with a usable transport | `<url>`, with `pending`/`failed`/`sent` counts in the body |
| No usable transport, or the run died | `<url>/fail` |
| A message has been **due** for three ticks and nothing took it | `<url>/fail` |

A single rejected address is deliberately **not** an alarm — it is a `failed`
row the Kassenwart can see and act on. An alarm that fires on every typo'd
address is one that gets switched off within weeks, and then the real outage is
silent too.

**Recommended check settings: period 1 day, grace 1–2 hours.** The announcement
is queued at finalize and the collection is at least seven days out, so a
one-day alarm still leaves six days to react — while a tighter period would fire
on every single missed tick.

The ping body carries counts only. No address and no name ever leaves the host
through the monitor.

### Diagnosing it

The security self-check (admin panel) carries a **delivery** section with five
measured rows: the transport and sender, the last observed run, declared versus
observed interval, anything overdue in the queue, and how many messages have
been given up on. That is the page to open when the heartbeat fires — an alarm
with no diagnosis just produces a phone call.

Running the drain by hand from a shell is the fastest way to see a transport
error directly:

```bash
php backend/bin/cron.php          # add --quiet for the cron entry itself
```

It prints one summary line per run. A run that stopped on an unexpected error
prints `ABORTED` and its reason instead of the counters, on stderr as well —
its counters would otherwise read `sent=0`, which is what an idle tick prints,
and the two mean very different things about the queue.

### Trying it locally

The dev stack runs [Mailpit](https://mailpit.axllent.org) as the `mailpit`
service: an SMTP sink on `1025` with a UI and an HTTP API on
<http://localhost:8025>. Nothing points at it by default — `MAIL_DSN` is empty
so the stack behaves like an installation that has not configured mail — so
start it with the DSN when you want announcements to actually leave:

```bash
MAIL_DSN=smtp://mailpit:1025 docker compose up -d
docker compose exec -T backend php /app/bin/cron.php
```

Finalize a direct debit, run that drain, and the announcement is in the UI
exactly as a member would receive it, both parts.

The `mail-chain` E2E project (`cd e2etests && npx playwright test
--project=mail-chain`) drives the same path and asserts on what arrives —
creditor id, mandate reference, amount, masked account, itemised statement, the
seven-day distance, and that a second run delivers nothing a second time. The
`mail-statement` project does the same for the Deckelauszug below.

### The Deckelauszug (periodic tab statement)

A second kind of mail rides the same queue and the same cron: a **Deckelauszug**
— a member's tab as it stood on the first day of the period, itemised, sent
whatever it says. It announces nothing and collects nothing, and it is not a
Vorabankündigung; the two must not be confused (see `CONTEXT.md`). Its job is
concrete: the terminal refuses a checkout past the €100 credit limit, offline
and in front of the queue, and without a statement the first a member hears of
it is being turned away.

**Turning it on.** Settings → Mail → *Deckelauszug*: `off | monthly |
quarterly`. Upgrades land on `off` — a migration must not start mailing a live
membership before anyone has read a release note — so this is a decision
somebody has to make. There is deliberately **no per-member opt-out**: this
system has no member login, so the club-wide switch is the only off-ramp
(ADR-0039 decision 3).

**Who gets one.** Every member with an address, whatever they owe — including a
zero balance and a credit. An inactive member who still owes gets one too;
deactivating somebody does not cancel their tab. A member with no address is
skipped silently (in practice an anonymised one).

**Nothing extra to schedule.** `bin/cron.php` queues whatever period has become
due before it drains, so the statements go out on the tick that queued them.
Almost every tick finds nothing due, which is the intended shape. `--period
2026-08` names a period explicitly rather than deriving it from today; a period
that is no longer the current one is refused rather than mailed late.

### Deliverability

- **SPF**: publish a record for the sending domain that authorises the host's
  outbound mail servers. Most panels offer this as a checkbox; without it, a
  large share of announcements land in spam.
- **DKIM**: enable signing in the hosting panel for the sending domain.
- **The envelope sender must be a mailbox on a domain hosted there.** Sending
  `From:` an address the host does not own is the single most common reason a
  shared host silently drops outbound mail.
- **Reply-to is the Kassenwart** (Settings → Mail). A pre-notification invites
  a reply — Beanstandungen within six weeks — so it has to reach a person.
- **Bounces land in that mailbox and are not parsed.** Nothing in the
  application reads them; somebody has to.
- **Send limits are per-tariff.** A settlement for a club of a few hundred
  members is a few hundred messages in one tick. If the relay throttles,
  lower `drain_batch_size` under Settings → Mail — the rest simply goes out on
  the following ticks.

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

**Migration `023_drop_mandate_documents.php` (ADR-0037) deletes files, not just
rows.** It permanently removes every scanned mandate document under
`storage/mandates/` along with the `mandate_documents` table. A database backup
does not cover this — the files live outside MariaDB. Before approving a
release that carries this migration, download and archive any stored scans (or
confirm the treasurer already holds the paper originals); there is no
migration-side undo once it has run.

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

   **Destructive migration warning (ADR-0037):** a release containing
   migration `023_drop_mandate_documents.php` permanently deletes every
   scanned mandate document still stored under `storage/mandates/` (and the
   `mandate_documents` table). This is not covered by the database backup
   above — the files live on disk, not in MariaDB. If this installation has
   any stored scans, download and archive them (or their paper originals)
   **before** running this migration; there is no way to recover them
   afterwards. `install.php?action=status` lists pending migrations by name
   if you need to check whether `023_drop_mandate_documents.php` is one of
   them before proceeding. After the migration runs, the Security &
   Credentials self-check page reports a finding if any files could not be
   deleted (e.g. a permission mismatch) — resolve that manually.
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
