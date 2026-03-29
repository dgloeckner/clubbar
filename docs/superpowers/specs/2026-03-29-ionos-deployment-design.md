# IONOS Integration Deployment — Design Spec

**Date**: 2026-03-29
**Status**: Approved

---

## Overview

Automate deployment of the Club Bar backend and admin frontend to IONOS shared hosting (integration environment). Deployment triggers automatically when a push to `main` passes all CI tests.

---

## Constraints

- IONOS shared hosting: SFTP-only (rssh restriction, no shell execution)
- Authentication: password-based SFTP (`IONOS_PASSWORD` GitHub Secret)
- Migrations cannot be run via SSH CLI — must be triggered via HTTP
- `config.php` (DB credentials, app config) must never be overwritten by CI
- `backend/storage/` (mandate PDFs, uploads) and `backend/logs/` must be preserved
- Initial installation remains manual (one-time `install.php` wizard)

---

## Target Environment

| Property | Value |
|----------|-------|
| Host | `home277820902.1and1-data.host` |
| Port | 22 (SFTP) |
| User | `acc1206117549` |
| SFTP root | `/` (= document root) |
| Site URL | `${{ secrets.IONOS_SITE_URL }}` |

---

## Architecture

```
push to main
     │
     ▼
CI pipeline (existing build.yaml)
  lint → test-backend → build-frontend → test-e2e → test-package
                                                           │
                                              (only if main branch)
                                                           ▼
                                               deploy-integration
                                              ┌──────────────────┐
                                              │ 1. download ZIP  │
                                              │    artifact      │
                                              │ 2. write         │
                                              │    .deploy-secret│
                                              │ 3. lftp mirror   │
                                              │    → IONOS /     │
                                              │ 4. GET           │
                                              │    /deploy.php   │
                                              │    ?key=<secret> │
                                              └──────────────────┘
```

---

## Deliverables

### 1. `package/deploy.php` (new)

Self-destructing migration runner uploaded with every package release.

**Request**: `GET /deploy.php?key=<IONOS_DEPLOY_SECRET>`

**Behaviour**:
1. Read `.deploy-secret` from document root; validate `key` param with `hash_equals()`
2. Load `config.php` for DB credentials (already on server, never uploaded by CI)
3. Instantiate PDO, run `MigrationRunner::migrate()` against `backend/db/migrations/`
4. Return JSON `{"ok": true, "results": [...]}` or `{"ok": false, "error": "..."}` with appropriate HTTP status
5. `register_shutdown_function` deletes `.deploy-secret` and `deploy.php` itself — runs on both success and failure

**Security**:
- `hash_equals()` prevents timing attacks on key comparison
- Returns 403 immediately if `.deploy-secret` is missing or key is wrong
- Self-destructs unconditionally — no persistent attack surface between deploys
- `.deploy-secret` blocked from HTTP access via `.htaccess`

---

### 2. `package/.htaccess` (modified)

Add alongside the existing `.installer-data` block:

```apache
<Files ".deploy-secret">
    Require all denied
</Files>
```

---

### 3. `.github/workflows/build.yaml` (modified)

**Change 1** — Add job-level output to `test-package` so the deploy job can reference the artifact name:

```yaml
test-package:
  outputs:
    artifact: ${{ steps.package.outputs.artifact }}
```

**Change 2** — New `deploy-integration` job:

```yaml
deploy-integration:
  name: Deploy to Integration
  needs: [test-package]
  if: github.ref == 'refs/heads/main'
  runs-on: ubuntu-24.04

  steps:
    - name: Download package artifact
      uses: actions/download-artifact@v4
      with:
        name: ${{ needs.test-package.outputs.artifact }}

    - name: Unzip package
      run: unzip "${{ needs.test-package.outputs.artifact }}" -d dist/package/

    - name: Write deploy secret
      run: echo -n "${{ secrets.IONOS_DEPLOY_SECRET }}" > dist/package/.deploy-secret

    - name: Install lftp
      run: sudo apt-get install -y lftp

    - name: Upload via SFTP
      run: |
        lftp -u "acc1206117549,${{ secrets.IONOS_PASSWORD }}" \
          sftp://home277820902.1and1-data.host:22 <<'EOF'
        set ssl:verify-certificate no
        set sftp:auto-confirm yes
        mirror --reverse --delete --verbose \
          --exclude config.php \
          --exclude .installer-data \
          --exclude-glob backend/storage/* \
          --exclude-glob backend/logs/* \
          dist/package/ /
        bye
        EOF

    - name: Run migrations
      run: |
        RESPONSE=$(curl -sf "${{ secrets.IONOS_SITE_URL }}/deploy.php?key=${{ secrets.IONOS_DEPLOY_SECRET }}")
        echo "$RESPONSE" | jq .
        [ "$(echo "$RESPONSE" | jq -r '.ok')" = "true" ]
```

---

## GitHub Secrets Used

| Secret | Purpose |
|--------|---------|
| `IONOS_PASSWORD` | SFTP authentication |
| `IONOS_DEPLOY_SECRET` | Authenticate the migration HTTP call |
| `IONOS_SITE_URL` | Base URL for the integration site |

The DB secrets (`IONOS_DB_*`) are **not used** — `deploy.php` reads `config.php` already present on the server.

---

## File Sync Rules

| Path | Action |
|------|--------|
| `config.php` | **Never uploaded** — contains live DB credentials |
| `.installer-data` | **Never uploaded** — one-time installer state |
| `backend/storage/*` | **Never deleted** — mandate PDFs and uploads |
| `backend/logs/*` | **Never deleted** — application logs |
| Everything else | Uploaded; stale files deleted (`--delete`) |

---

## Initial Setup (Manual, Once)

1. Build package: `./scripts/build-package.sh`
2. Upload ZIP to server via FTP/SFTP
3. Open `https://<site>/install.php` in browser
4. Complete 5-step wizard: prerequisites → DB credentials → migrations → admin account → done

After this, all subsequent deployments are automated.

---

## Limitations

- **No atomic swap**: files are uploaded one by one over SFTP; brief inconsistency window possible during upload. Acceptable for an integration environment.
- **No automatic rollback**: if migrations fail, `deploy.php` returns a non-zero exit and the CI job fails, but files are already uploaded. Manual intervention required.
- **No DB backup before migration**: shared hosting without shell makes pre-migration backups from CI impractical. Back up manually before running migrations that drop or alter columns.
