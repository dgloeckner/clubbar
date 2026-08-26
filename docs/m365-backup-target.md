# Provisioning the Microsoft 365 backup target

**Scope**: setting up and maintaining the Microsoft 365 destination that the
`msgraph://` backup transport pushes archives to.

**Audience**: whoever holds IT for the club — and, more importantly, whoever
holds it *next*. Write the values from §2 down somewhere a successor will find
them.

**Companion documents**: [ADR-0049](../adr/0049-encrypted-offsite-backups-on-shared-hosting.md)
(why backups look the way they do), [`deployment.md`](./deployment.md) (the
install this plugs into), [`procedures.md`](./procedures.md) (the restore
drill, which is the only thing that makes any of this a backup).

**Script**: [`scripts/setup-msgraph-backup.ps1`](../scripts/setup-msgraph-backup.ps1)

> **This is optional.** A club with no Microsoft 365 tenant leaves `backup.dsn`
> empty and gets sealed archives on its webspace, pruned on a schedule, plus the
> periodic manual copy described in `procedures.md`. That covers *"undo a
> mistake an hour ago"* and none of *"the hosting account is gone"* — which is
> the honest summary, and the reason this page exists.

---

## 1. What this is, and which parts must not be "simplified"

The transport writes archives to a SharePoint document library using **app-only
authentication**: no user signs in, the application itself is the identity.

Three choices below look like they could be relaxed by a later maintainer
wanting a shorter setup. Each is load-bearing.

**A SharePoint site, not a OneDrive.** A personal OneDrive is deleted 30 days
after its account is removed. Board members rotate; a site outlives them. A
backup that disappears with a volunteer is not a backup.

**`Sites.Selected`, not `Files.ReadWrite.All`.** `Sites.Selected` grants the app
*nothing* when it is consented; access is then granted one site at a time. The
app can write to the backup site and is blind to every other site, library and
OneDrive in the tenant. The broad permission would turn a leaked client secret —
a secret that sits in a file on shared hosting — from a lost backup into a
tenant-wide data breach.

**A dedicated site, not the root site.** `Sites.Selected` is granted per site
*collection*. Granting it on the root site would hand the app write access to
the club intranet and every loose library under it.

### The gap this does not close

**There is no add-only app role on Microsoft 365.** `Sites.Selected` restricts
*which* site; the per-site role is a fixed `read`/`write` enum, and `write`
includes delete. So the credential sitting on the webspace can delete what it
wrote. ADR-0049 states this rather than hiding it, and names the two mitigations:

- **Library retention**, which makes a delete *recoverable* — not impossible.
  Set it up if your tenant allows it; it is not an install precondition, because
  most clubs are on Business Basic and blocking the install would help nobody.
- **The periodic manual copy**, which is the only copy no credential can reach.

`s3://` with object lock is what closes this properly, and it is the next
transport ADR-0049 names.

---

## 2. What to write down

Fill this in from the script's output and keep it with the club's other
handover material. **None of these values is a secret** — the client secret is
the one that is, and it belongs in `config.php` and nowhere else.

| Object | Value | Where it comes from |
|---|---|---|
| Tenant ID | | script output |
| App registration | `clubbar-backup` | Entra → App registrations |
| Application (client) ID | | script output |
| Service principal object ID | | Entra → Enterprise applications |
| Site | `https://<tenant>.sharepoint.com/sites/Backups` | §4.1 — a Kommunikationswebsite, no M365 group |
| Library | `Backups` | created by the script |
| Folder / DSN prefix | `clubbar` | created by the script |
| `driveId` | | script output |
| Client secret expires | | script output — **see §7** |

The resulting `config.php`:

```php
'backup' => [
    'recipient_public_keys'    => ['admin:…', 'vorstand:…'],
    'dsn'                      => 'msgraph://<tenant-id>/<client-id>@drive/<driveId>/clubbar',
    'client_secret'            => '…',
    'client_secret_expires_at' => '2027-08-25',
],
```

The tenant id and client id ride in the DSN because they are not secrets. The
client secret does not, for the same reason the SMTP password is kept out of a
URL: **a DSN is the value that gets pasted into support threads, issue reports
and screenshots**, and a credential travelling with it leaks by ordinary
helpfulness rather than by attack. A DSN that looks like it carries one is
refused outright.

> `backup.dsn` alone does not start anything. Configuring
> `backup.recipient_public_keys` is what switches the nightly job on — there is
> no separate enabled flag, so the two can never disagree (ADR-0049 decision 2).

---

## 3. Prerequisites

- **Global Administrator** in the target tenant. Application Administrator +
  SharePoint Administrator also works.
- macOS or Linux with PowerShell 7:

```bash
brew install --formula powershell
pwsh -c 'Install-Module Microsoft.Graph.Authentication -Scope CurrentUser -Force'
```

**Not `brew install --cask powershell`.** Homebrew moved PowerShell from cask to
formula at 7.6.0 and dropped the cask; that spelling fails with *"No Cask with
this name exists"*. Do not accept Homebrew's suggestion of `powershell@preview`
either — it is deprecated for failing the macOS Gatekeeper check. The `.pkg`
download from GitHub fails Gatekeeper for the same reason. The formula is the
only clean path.

---

## 4. Procedure

### 4.1 Create the site (one-time, manual)

SharePoint admin center → **Sites → Active sites → + Create** → **Communication
site**. Despite the name this creates no Microsoft 365 group, so no mailbox, no
calendar and no Teams surface come with it.

- **Name it ASCII-only.** SharePoint transliterates umlauts into the URL slug
  unpredictably, and the mangled slug is what the script needs.
- **Confirm the URL in the address bar.** If the name was taken, SharePoint
  silently appends a suffix (`/sites/Backups2`).
- While you are in the admin center: select the site → **Settings → Storage
  limit** and cap it. This is the safety net that makes a runaway backup fail on
  this one site instead of draining the tenant's pooled storage into Exchange
  and everyone's OneDrive.

### 4.2 Run the setup script

```bash
pwsh ./scripts/setup-msgraph-backup.ps1 \
  -SiteHostname contoso.sharepoint.com \
  -SitePath /sites/Backups
```

It is idempotent — an existing app, service principal, role assignment, library
or folder is reused rather than duplicated — and it:

1. Resolves the `Sites.Selected` app role id from Graph at runtime, so there is
   no hardcoded GUID that can silently drift
2. Creates the app registration, single-tenant
3. Creates the service principal and admin-consents the role
4. Resolves the site and grants the app `write` **on that site only**
5. Creates the `Backups` library and the `clubbar` folder
6. Mints a client secret (12 months by default)
7. Acquires an app-only token and performs a real upload-then-delete probe

**Three things happen that look wrong and are not:**

- **The consent prompt names *Microsoft Graph Command Line Tools*, not
  `clubbar-backup`.** That is correct: it is the client the admin session runs
  through. Tick *"consent on behalf of your organization"*.
- **Two visible pauses**, with `token not ready` / `site grant not live yet`.
  Entra replication takes up to about two minutes and 403s inside that window
  are expected; the retry loops handle them. Only a failure after the full
  three-minute deadline is real.
- **The secret prints last, exactly once.** It cannot be retrieved afterwards by
  anyone, including Microsoft. Capture it before closing the terminal.

### 4.3 Revoke the CLI helper's consent

The setup needed delegated `Sites.FullControl.All` on *Microsoft Graph Command
Line Tools*. That grant has no ongoing purpose and should not survive the
session. **`Disconnect-MgGraph` does not remove it** — see §8.

Portal route, which is the one to use:

> entra.microsoft.com → **Enterprise applications → All applications →
> Microsoft Graph Command Line Tools → Properties → Delete**

Deleting the service principal removes every grant hanging off it at once. The
next `Connect-MgGraph` anywhere in the tenant recreates it from scratch with
fresh consent prompts. Nothing breaks.

Then clear the local token cache, which the SDK often fails to clear itself:

```bash
rm -rf ~/.graph
```

**This does not touch `clubbar-backup`.** That is a separate service principal
whose role assignment and site grant are independent of the CLI helper.

---

## 5. Post-setup hardening

Neither of these blocks the first backup, and both are far easier now than after
a few hundred gigabytes have accumulated.

**Cap version history on the library.** Re-uploading a file of the same name
stacks a full second copy against the site quota. The transport writes a random
suffix into every archive name, so this only bites when a night is retried — but
when it bites, it doubles. Graph v1.0 does not expose list versioning settings,
so this needs PnP:

```powershell
Connect-PnPOnline -Url https://contoso.sharepoint.com/sites/Backups -Interactive
Set-PnPList -Identity Backups -EnableVersioning $true -MajorVersions 2
```

**Size the quota for roughly 2× the retention window.** Deleted items land in
the site recycle bin for 93 days and keep consuming quota there. Graph v1.0 has
no permanent-delete for driveItems; second-stage emptying needs SharePoint
PowerShell. So remote retention *reduces* what the library shows long before it
reduces what the library costs.

---

## 6. Verifying it works

Verify through the **app-only** path — the exact credential the nightly job
uses — and never through Graph PowerShell. §8 explains why the obvious
verification is worse than useless.

```bash
TOKEN=$(curl -s -X POST \
  "https://login.microsoftonline.com/$TENANT_ID/oauth2/v2.0/token" \
  -d "client_id=$CLIENT_ID" \
  -d "client_secret=$CLIENT_SECRET" \
  -d "scope=https://graph.microsoft.com/.default" \
  -d "grant_type=client_credentials" | jq -r .access_token)

curl -s -H "Authorization: Bearer $TOKEN" \
  "https://graph.microsoft.com/v1.0/drives/$DRIVE_ID/root/children" | jq '.value[].name'
```

If that lists the folder, everything downstream is transport code.

Then run the job itself:

```bash
php backend/bin/backup.php --force
```

It prints what it wrote and what it pushed. To confirm the app still holds only
the one permission:

```powershell
(Invoke-MgGraphRequest -OutputType PSObject `
  -Uri "https://graph.microsoft.com/v1.0/servicePrincipals/$SP_OBJECT_ID/appRoleAssignments").value |
  Select-Object appRoleId, resourceDisplayName
```

Expect **exactly one row** against Microsoft Graph. Anything else — in
particular `Sites.ReadWrite.All` or any `Files.*` — means somebody widened the
permissions, and the blast-radius argument in §1 no longer holds.

### 6.1 What a run against a real tenant actually did (2026-08-26)

`MsGraphTransport` was driven against a live tenant and library, through the
same code path the nightly job takes. Recorded here as **observed**, because
until this run every one of these behaviours was proved only against
`FakeHttpClient` — and the whole reason #691 asked for a live run is that a fake
agrees with whatever the code believes.

| | Observed |
|---|---|
| Upload, 9,442,329 bytes (2.9 fragments) | `uploaded` in 7.1s through a real resumable session |
| Listing | The archive appears with a byte size exactly equal to the file written |
| Delete, by the app credential | Returns true; the item leaves the listing |
| Resume after a 1-second budget | `partial` after **exactly** 3,276,800 bytes — one `CHUNK_BYTES` fragment, not a byte more |
| The next run | `uploaded`, sending 9,312,743 bytes |
| The two runs summed | 3,276,800 + 9,312,743 = 12,589,543 — **exactly** the archive's size |
| Final size on the remote | Equal to the local size |
| Sidecar | Written on the partial run (`uploadUrl`, `uploaded`), cleared on success |

Three of those rows are worth reading twice.

**The byte counts sum to the file size exactly.** No range was re-sent and none
was skipped, which is the property that makes resuming cheaper than restarting
rather than merely different from it.

**The remote size equals the local size.** That is the observable form of §8's
warning that the final fragment must not be padded to a 320 KiB multiple: a
padded upload would list as larger than the file, and the corruption would then
be discoverable only by attempting a restore.

**`Sites.Selected` `write` does permit delete** — now confirmed twice, and the
second time through the application's own path rather than the setup script's.
This is the premise of the gap the epic names, and it is measured, not inferred.

**What this run could not observe: whether that delete is recoverable.** Graph
v1.0 exposes no read of the site recycle bin for `driveItems`, so the 93-day
retention that makes a delete recoverable rather than final cannot be confirmed
through the API the application uses. Confirming it needs the SharePoint UI or
PowerShell, and it stays an *unverified* claim here until somebody does that.
The quarterly offline copy in [`procedures.md`](./procedures.md) is the mitigation
that does not depend on the answer.

The DSN handed over for this run was missing its first two segments — it began
`msgraph://drive/…` rather than
`msgraph://<tenant-id>/<client-id>@drive/…`. `BackupDsn` refused it by name,
which is the parser behaving as §2 says it should: the error named the missing
part rather than failing at the first request.

---

## 7. The recurring task: rotating the secret

**This is the single most likely cause of a silent backup failure.**

Client secrets last at most 24 months. **Entra sends no notification when one
expires**, and the failure surfaces only when the thing depending on it stops
working — which, for an unattended nightly job, can be months of no backups
before anyone notices.

What the application does about it:

- `backup.client_secret_expires_at` is read by the backup run, which warns as
  the date approaches
- The transport reports `AADSTS7000222` as *"the client secret has expired"*
  specifically, not as a generic authentication failure

**Put a reminder in the shared board calendar 60 days before the date.** The
code warning only helps somebody who is reading the backup output; the calendar
entry survives a volunteer who is not.

Rotation is non-disruptive — the old secret keeps working until you delete it:

```bash
pwsh ./scripts/setup-msgraph-backup.ps1 \
  -SiteHostname contoso.sharepoint.com -SitePath /sites/Backups -RotateSecretOnly
```

1. Deploy the new `client_secret` and `client_secret_expires_at`
2. **Verify one successful backup run**
3. *Then* delete the old secret in Entra → App registrations → `clubbar-backup`
   → Certificates & secrets
4. Update §2 and the calendar reminder

**The better long-term fix** is to replace the shared string with a certificate
credential (a JWT assertion), or federated credentials if the backup ever moves
to a CI runner — which store no credential at all. Both are drop-in swaps at the
token-acquisition layer.

---

## 8. Known dead ends

Approaches that look reasonable and are not. Each of these cost real time.

**`az` CLI cannot do the site grant.** `az rest` calls Graph with the Azure
CLI's own first-party token, whose delegated scopes do not include
`Sites.FullControl.All`; `POST /sites/{id}/permissions` returns 403 with no
obvious remedy. That is why the script is PowerShell. Also relevant: a nonprofit
tenant often has no Azure subscription at all, which makes `az login` awkward
before you have even started.

**Verifying with `Connect-MgGraph` re-consents the thing you just revoked.**
`Application.Read.All` is admin-consent-only, so the verification command
creates a fresh `oauth2PermissionGrant` on the CLI app in order to run. Verify
via the app-only `curl` in §6, or accept that the revoke must always be the last
action of the session.

**`Disconnect-MgGraph` revokes nothing.** It drops the local token cache;
tenant-side consent survives. It also frequently fails to clear even the local
cache (*"The authority … must be in a well-formed URI format"*), which means a
later `Connect-MgGraph` can sign in silently from cache and look like it
re-consented when it did not.

**The scope list printed by `Disconnect-MgGraph` is not evidence of a live
grant.** It is the context object being torn down, echoed on the way out.

**Consenting `Sites.Selected` grants no site.** If `/sites/{id}/drives` returns
an empty array, or a path returns 403, the per-site grant (§4.2 step 4) is
missing. This looks like a bug and is by design. **Do not "fix" it by widening
the permission** — that is the one change that turns a leaked secret into a
tenant-wide breach.

**Do not pad the final upload fragment.** Answers circulating on Microsoft Q&A
suggest padding the last chunk with bytes from the start of the file to reach a
320 KiB multiple. That corrupts the archive, silently, in a way only a restore
would discover. Every fragment *except the last* must be a multiple of 320 KiB;
the last is simply the remainder.

**Do not send an `Authorization` header to the upload session URL.** It is
pre-authenticated and the request *fails* with the header attached. HTTP clients
with default-header middleware do this to you silently.

---

## 9. Do not

- **Do not delete the `clubbar-backup` service principal.** The app registration
  is only a blueprint; the service principal is the identity that exists in the
  tenant. Deleting it breaks the backup with `AADSTS700016`.
- **Do not widen `Sites.Selected`** to `Sites.ReadWrite.All` or `Files.*` to fix
  a 403. The correct fix is almost always the missing per-site grant.
- **Do not point the DSN at the root site, or at a Teams-backed site.** Team
  membership changes without anyone considering what is stored there.
- **Do not put the client secret in this document, in the repository, or in a
  ticket.** It lives in `config.php`.

---

## 10. Checklist

Copy this into the club's handover notes and tick it off.

- [ ] `driveId` and secret expiry date written into §2
- [ ] Calendar reminder set, 60 days before the secret expires
- [ ] Version history capped on the `Backups` library (§5)
- [ ] Storage limit set on the site (§4.1)
- [ ] CLI helper service principal deleted and `~/.graph` cleared (§4.3)
- [ ] `backup.recipient_public_keys` configured — **without it nothing is
      written at all**, with or without a DSN (ADR-0049 decision 2)
- [ ] **A restore has been performed at least once.** A backup whose restore
      path has never been exercised is not a backup; retention that prunes
      archives nobody has ever restored is automated deletion with extra steps.
      The drill is in [`procedures.md`](./procedures.md).
- [ ] Decided whether Microsoft 365 is the *only* off-site copy. If it is, note
      that one compromised Global Admin loses both the club's live data and its
      backups — a second destination on unrelated credentials is worth having.
