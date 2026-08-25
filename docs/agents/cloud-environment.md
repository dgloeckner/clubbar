# The Claude Code cloud environment

Applies to Claude Code cloud sessions (`CLAUDE_CODE_REMOTE=true`) — the sessions
started from claude.ai/code, the mobile app or a GitHub Action, which run in an
ephemeral container rather than on a developer's machine. On a local machine
none of this applies.

The environment itself (network access level, environment variables, setup
script) is configured in a dialog at claude.ai/code and lives nowhere in git.
That is the problem this page exists to solve: the setup script is the one part
of the agent setup that cannot otherwise be diffed, reviewed or drift-checked —
the same failure class as a scheduled workflow nobody watches.

## The setup script is version-controlled here

[`.claude/cloud-setup.sh`](../../.claude/cloud-setup.sh) is the **canonical
copy**. The live copy is pasted into the environment dialog.

**After editing `.claude/cloud-setup.sh`, re-paste it into the dialog and save.**
Nothing does this for you, and nothing detects the drift; a change committed but
not pasted has no effect on any session.

Saving triggers a cache rebuild (below), so the next session picks it up.

Three constraints the script has to honour, all of them silent when broken:

| Constraint | What happens otherwise |
|------------|------------------------|
| Always exit 0 | A non-zero exit fails the whole session start |
| Finish in roughly five minutes | The cache does not build, and every session pays the cost again |
| Runs as root, before Claude Code launches | `$CLAUDE_PROJECT_DIR` may not be set yet, so the checkout is located rather than assumed |

## The environment cache

The environment is snapshotted after the setup script runs, and sessions start
from that snapshot. It rebuilds when the setup script or the allowed-hosts list
changes, and otherwise expires after roughly seven days.

Two consequences worth remembering:

- **The snapshot is a filesystem, not a process list.** Nothing that was
  *running* survives it — which is why `dockerd` is not up at session start and
  why `scripts/ensure-docker.sh` exists as a SessionStart hook rather than as a
  line in the setup script.
- **Setup steps are skipped once the cache exists.** A fix that has to apply to
  every session belongs in the repo (a hook, or `scripts/dev-setup.sh`), not in
  the setup script.

## PHP: why the suite runs on `php8.3`, not `php`

The image ships **PHP 8.4** from the ondrej/sury PPA. `Validator.php` calls
`bcmod()` for the IBAN checksum, and **bcmath is not installed**, so the backend
suite errors out in every SEPA path.

It cannot be fixed for 8.4. `php8.4-bcmath` exists only in that same PPA, and
the egress policy answers **403** for `ppa.launchpadcontent.net`:

```
E: Failed to fetch https://ppa.launchpadcontent.net/ondrej/php/ubuntu/dists/noble/InRelease  403  Forbidden
```

PHP **8.3** is a different matter, and is what the project targets anyway
(`backend/composer.json` pins the platform to 8.3.30). `php8.3-cli` and
`php8.3-bcmath` are both in `archive.ubuntu.com`, which is allowed:

```
php8.3-bcmath:
  Candidate: 8.3.30-3+…+deb.sury.org+1        ← the PPA; 403
     8.3.6-0ubuntu0.24.04.10                  ← archive.ubuntu.com; fine
```

Installing them naively still fails — every `php8.3-*` dependency resolves to
the PPA's 8.3.30 and apt gives up:

```
php8.3-bcmath : Depends: php8.3-common (= 8.3.6-0ubuntu0.24.04.10)
                but 8.3.30-…+deb.sury.org+1 is to be installed
E: Unable to correct problems, you have held broken packages.
```

The setup script pins the unreachable PPA to `Pin-Priority: -1`, which removes it
from consideration and lets the archive versions resolve. Nothing is lost: those
packages could not be fetched anyway. The already-installed 8.4 stays exactly
where it is, and `php` still means 8.4.

**So: run backend tests with `php8.3`.**

```bash
cd backend && php8.3 vendor/bin/phpunit -c phpunit.xml --testsuite Unit
```

This replaces "run them in the container" as the quickest path — no stack, no
Docker Hub, ~12 seconds for 2202 unit tests. The container remains correct and
is still what you want for anything needing the database.

## Composer works; do not try to fix it

`composer install` in `backend/` completes in about **three seconds**, entirely
from dist. If you find a document claiming otherwise (issue #696 item 3 did, and
so did earlier drafts of this page), it does not reproduce:

```
$ cd backend && composer install --prefer-dist
  - Installing symfony/mailer (v7.4.15): Extracting archive
  …
real  0m3.1s
```

The GitHub proxy is repository-scoped, but that scope applies to **API
metadata**, not to release archives. Composer's dist URLs redirect to codeload
and download fine:

| Request | Result |
|---------|--------|
| `api.github.com/repos/symfony/mailer` (metadata, unattached repo) | **403** |
| `api.github.com/repos/symfony/mailer/zipball/v7.1.0` | 302 → codeload → **200** |
| `codeload.github.com/symfony/mailer/legacy.zip/v7.1.0` | **200** |
| `repo.packagist.org/p2/symfony/mailer.json` | **200** |

Concretely, do not:

- add `codeload.github.com` or the `githubusercontent.com` hosts to the egress
  allowlist — they are already on the default Trusted list;
- set `GITHUB_TOKEN` or `COMPOSER_AUTH` — the 403 above is a scope decision, and
  a token gets the same answer;
- raise network access to `Full` — GitHub traffic does not go through the domain
  allowlist at all;
- ship a `vendor/` bundle as a release asset — that is permanent CI machinery for
  a problem that is not there.

## Two error messages that point away from their cause

**`Could not authenticate against github.com` during `composer install`** is not
an authentication problem and adding a token will not fix it. It is the session's
GitHub proxy refusing a request for a repository outside the session's scope. If
it happens, check the table above before changing any credential.

**`access denied by the git proxy: <someone-else>/<repo> is not in this session's
authorized repository set`** on `git push` reads as a permissions problem and is
almost always a **clobbered remote**. A composer run that fell back to
`--prefer-source` — especially one accidentally started from the repo root
instead of `backend/` — can leave the checkout's `origin` pointing at a
dependency:

```
$ git remote -v
origin  git@github.com:sebastianbergmann/phpunit.git (fetch)
```

History, branches and remote-tracking refs are untouched; only the URLs. Repair:

```bash
git remote set-url origin https://github.com/dgloeckner/clubbar
git remote remove composer   # if a stray one was added
```

## Egress and the container registry

Image pulls and outbound HTTPS go through the environment's allowlist, which is
configured in the environment settings and **not in this repo**. A 403 or 407 is
fixed by an admin there — never worked around in code with a registry rewrite,
an insecure-registry entry or a vendored binary.

To confirm a failure is a policy denial rather than a network fault, ask the
proxy, which names the blocked host:

```bash
curl -sS "$HTTPS_PROXY/__agentproxy/status"   # see recentRelayFailures
```

Already on the default Trusted allowlist, so never worth "adding":
`codeload.github.com`, `objects.githubusercontent.com`,
`release-assets.githubusercontent.com`, `raw.githubusercontent.com`,
`packagist.org`, `repo.packagist.org`, `archive.ubuntu.com`,
`security.ubuntu.com`.

Non-default hosts this project needs, and the one that is denied, are listed in
[CLAUDE.md](../../CLAUDE.md) under *Container registry and egress allowlist*.

### `graph.microsoft.com` is open — and that was never the whole blocker

It was denied when #691 was written, and it is allowed as of **2026-08-25**:

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://graph.microsoft.com/v1.0/   # 200
```

The 200 is the service document, answered with Microsoft's own `request-id` and
`x-ms-ags-diagnostic` headers, and `recentRelayFailures` in the proxy status is
empty — so this is a real reach, not a proxy-shaped 200. `login.microsoftonline.com`
was already allowed, so both halves of the transport are now reachable.

**A session older than the change still gets a 403, and that is not a
contradiction.** The policy is bound when the session starts, so a container
created before the allowlist was edited keeps the old one for its whole life. On
2026-08-25 the two observations were minutes apart and both correct: a session
started after the edit saw `200`, while one started before it saw

```
kind:   connect_rejected
detail: gateway answered 403 to CONNECT (policy denial or upstream failure)
host:   graph.microsoft.com:443
```

recorded in that session's own `recentRelayFailures`. So a 403 here means **start
a new session**, not "the allowlist is wrong" and never "retry until it works".
The proxy's status endpoint is the arbiter for the session you are actually in:

```bash
curl -sS "$HTTPS_PROXY/__agentproxy/status"   # recentRelayFailures names the host
```

This generalises past this one host: every entry in the table below describes the
policy as edited, not necessarily the policy your session is running under.

**What still blocks #691's last task is credentials, not egress.** The
verification asks a *real Microsoft 365 tenant* whether `Sites.Selected` `write`
permits delete, and whether library retention makes that delete recoverable.
Answering it needs a tenant, an app registration consented by a Global
Administrator (`scripts/setup-msgraph-backup.ps1` performs it) and the resulting
client id, client secret and site id. None of those exist in a session, and none
should: a credential that could write to the club's backup library is exactly the
kind of secret that must not live in an agent environment. It is a task for
somebody holding the tenant, and its result is written down in
`docs/m365-backup-target.md` as *observed*, not as the docs imply.

Everything else about the transport is tested against a fake
(`backend/tests/Support/FakeHttpClient.php`); ADR-0038's rule that **no test opens
a socket** means CI never wanted this host and never will.

## Where the rest lives

| Concern | File |
|---------|------|
| Starting `dockerd` at session start | `scripts/ensure-docker.sh`, wired in `.claude/settings.json` |
| Bringing the stack up, migrating, seeding, browsers | `scripts/dev-setup.sh` |
| Compose lifecycle and health-waiting | `scripts/dev-stack.sh` |
| GHCR mirror for Docker Hub rate limits | `scripts/pull-images.sh`, `scripts/mirror-images.sh` |

Official documentation:
<https://code.claude.com/docs/en/claude-code-on-the-web>.
