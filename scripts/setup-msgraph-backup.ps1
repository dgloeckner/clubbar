#!/usr/bin/env pwsh
<#
.SYNOPSIS
  Provisions the Entra app registration, Sites.Selected grant, SharePoint
  document library and backup folder for the msgraph:// backup transport,
  then proves the whole chain works with an app-only write test.

.DESCRIPTION
  Idempotent. Safe to re-run: existing app registration, service principal,
  app role assignment, library and folder are reused rather than duplicated.
  Every run mints a NEW client secret (old ones are left in place so you can
  overlap rotation) unless -NoSecret is passed.

  Uses raw Graph REST calls via Invoke-MgGraphRequest rather than the typed
  Mg* cmdlets, so it doesn't break when the SDK reshapes its parameters.

  The full procedure, including everything that looks reasonable and is not,
  is in docs/m365-backup-target.md. Read that before running this the first
  time -- several of the steps below produce output that reads like a failure
  and is not.

.EXAMPLE
  ./setup-msgraph-backup.ps1 -SiteHostname contoso.sharepoint.com -SitePath /sites/Backups

.EXAMPLE
  # Rotate the secret only, months before it expires:
  ./setup-msgraph-backup.ps1 -SiteHostname contoso.sharepoint.com -SitePath /sites/Backups -RotateSecretOnly

.NOTES
  Requires: pwsh 7+, Microsoft.Graph.Authentication module.
    brew install --formula powershell
    pwsh -c 'Install-Module Microsoft.Graph.Authentication -Scope CurrentUser'

  NOT --cask. Homebrew moved PowerShell from cask to formula at 7.6.0 and
  dropped the cask, so that spelling fails with "No Cask with this name
  exists". Do not take Homebrew's suggestion of powershell@preview either --
  it is deprecated for failing the macOS Gatekeeper check. The .pkg from
  GitHub fails Gatekeeper for the same reason. The formula is the clean path.

  The signed-in account must be Global Administrator (or Application
  Administrator + SharePoint Administrator) for the first run.
#>

[CmdletBinding()]
param(
    # e.g. contoso.sharepoint.com
    [Parameter(Mandatory)][string]$SiteHostname,

    # Server-relative path of the site, e.g. /sites/EDV
    [Parameter(Mandatory)][string]$SitePath,

    # Document library to create/use. A dedicated one keeps backup blobs out
    # of the default library, whose display name is localised ("Dokumente").
    [string]$LibraryName = 'Backups',

    # Folder inside the library that becomes the DSN prefix.
    [string]$FolderPath = 'clubbar',

    [string]$AppDisplayName = 'clubbar-backup',

    # Microsoft recommends < 12 months. Hard ceiling is 24.
    [ValidateRange(1, 24)][int]$SecretMonths = 12,

    # 'write' is enough to upload and to delete for retention.
    [ValidateSet('read', 'write', 'owner', 'fullcontrol')][string]$SiteRole = 'write',

    [switch]$RotateSecretOnly,
    [switch]$NoSecret
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$GraphAppId = '00000003-0000-0000-c000-000000000000'
$PermissionName = 'Sites.Selected'

function Write-Step { param([string]$m) Write-Host "`n==> $m" -ForegroundColor Cyan }
function Write-Ok { param([string]$m) Write-Host "    OK  $m" -ForegroundColor Green }
function Write-Info { param([string]$m) Write-Host "    --  $m" -ForegroundColor DarkGray }

function Graph {
    param(
        [string]$Method = 'GET',
        [Parameter(Mandatory)][string]$Uri,
        $Body
    )
    $splat = @{ Method = $Method; Uri = $Uri; OutputType = 'PSObject' }
    if ($null -ne $Body) {
        $splat.Body = ($Body | ConvertTo-Json -Depth 10 -Compress)
        $splat.ContentType = 'application/json'
    }
    Invoke-MgGraphRequest @splat
}

# ---------------------------------------------------------------------------
# 0. Connect
# ---------------------------------------------------------------------------
Write-Step 'Signing in to Microsoft Graph'
Write-Info 'First run shows a consent prompt for "Microsoft Graph Command Line Tools".'
Write-Info 'Tick "Consent on behalf of your organization". You can revoke it afterwards'
Write-Info 'under Entra > Enterprise applications > Microsoft Graph Command Line Tools.'

Import-Module Microsoft.Graph.Authentication -ErrorAction Stop

$scopes = @(
    'Application.ReadWrite.All'       # create the app registration
    'AppRoleAssignment.ReadWrite.All' # admin-consent Sites.Selected
    'Sites.FullControl.All'           # grant the app access to ONE site
)
Connect-MgGraph -Scopes $scopes -NoWelcome

$ctx = Get-MgContext
$TenantId = $ctx.TenantId
Write-Ok "tenant $TenantId as $($ctx.Account)"

# ---------------------------------------------------------------------------
# 1. App registration
# ---------------------------------------------------------------------------
Write-Step "App registration '$AppDisplayName'"

$graphSp = (Graph -Uri "https://graph.microsoft.com/v1.0/servicePrincipals(appId='$GraphAppId')")

# Resolve the app role id at runtime instead of hardcoding a GUID.
$role = $graphSp.appRoles | Where-Object { $_.value -eq $PermissionName -and $_.allowedMemberTypes -contains 'Application' }
if (-not $role) { throw "Could not resolve the $PermissionName application role on Microsoft Graph." }
Write-Info "$PermissionName role id $($role.id)"

$filter = [uri]::EscapeDataString("displayName eq '$AppDisplayName'")
$existing = (Graph -Uri "https://graph.microsoft.com/v1.0/applications?`$filter=$filter").value

if ($existing -and $existing.Count -gt 0) {
    $app = $existing[0]
    Write-Ok "reusing existing app, appId $($app.appId)"
}
else {
    if ($RotateSecretOnly) { throw "No app named '$AppDisplayName' exists — nothing to rotate." }
    $app = Graph -Method POST -Uri 'https://graph.microsoft.com/v1.0/applications' -Body @{
        displayName            = $AppDisplayName
        signInAudience         = 'AzureADMyOrg'   # single tenant
        requiredResourceAccess = @(
            @{
                resourceAppId  = $GraphAppId
                resourceAccess = @(@{ id = $role.id; type = 'Role' })
            }
        )
    }
    Write-Ok "created app, appId $($app.appId)"
    Start-Sleep -Seconds 10   # directory replication
}

# ---------------------------------------------------------------------------
# 1b. Rotate-only shortcut
# ---------------------------------------------------------------------------
if ($RotateSecretOnly) {
    Write-Step 'Rotating client secret'
    $endDate = (Get-Date).ToUniversalTime().AddMonths($SecretMonths)
    $pw = Graph -Method POST -Uri "https://graph.microsoft.com/v1.0/applications/$($app.id)/addPassword" -Body @{
        passwordCredential = @{
            displayName = "backup $(Get-Date -Format 'yyyy-MM-dd')"
            endDateTime = $endDate.ToString('o')
        }
    }
    Write-Ok "new secret expires $($endDate.ToString('yyyy-MM-dd'))"
    Write-Host ""
    Write-Host "In config.php, under 'backup':"
    Write-Host "    'client_secret'            => '$($pw.secretText)',"
    Write-Host "    'client_secret_expires_at' => '$($endDate.ToString('yyyy-MM-dd'))',"
    Write-Host ""
    Write-Info 'Deploy this, verify one backup run, then delete the old secret in Entra.'
    Write-Info 'The old secret keeps working until you delete it, so rotation is not disruptive.'
    return
}

# ---------------------------------------------------------------------------
# 2. Service principal + admin consent
# ---------------------------------------------------------------------------
Write-Step 'Service principal and admin consent'

$spFilter = [uri]::EscapeDataString("appId eq '$($app.appId)'")
$sp = (Graph -Uri "https://graph.microsoft.com/v1.0/servicePrincipals?`$filter=$spFilter").value | Select-Object -First 1

if (-not $sp) {
    $sp = Graph -Method POST -Uri 'https://graph.microsoft.com/v1.0/servicePrincipals' -Body @{ appId = $app.appId }
    Write-Ok 'created service principal'
    Start-Sleep -Seconds 10
}
else {
    Write-Ok 'service principal exists'
}

$assignments = (Graph -Uri "https://graph.microsoft.com/v1.0/servicePrincipals/$($sp.id)/appRoleAssignments").value
$already = $assignments | Where-Object { $_.appRoleId -eq $role.id -and $_.resourceId -eq $graphSp.id }

if ($already) {
    Write-Ok "$PermissionName already consented"
}
else {
    Graph -Method POST -Uri "https://graph.microsoft.com/v1.0/servicePrincipals/$($sp.id)/appRoleAssignments" -Body @{
        principalId = $sp.id
        resourceId  = $graphSp.id
        appRoleId   = $role.id
    } | Out-Null
    Write-Ok "$PermissionName consented (tenant-wide right to be granted sites — still zero sites)"
}

# ---------------------------------------------------------------------------
# 3. Resolve the site
# ---------------------------------------------------------------------------
Write-Step "Resolving site $SiteHostname$SitePath"

$sitePathClean = '/' + $SitePath.Trim('/')
$site = Graph -Uri "https://graph.microsoft.com/v1.0/sites/$($SiteHostname):$($sitePathClean)"
Write-Ok "$($site.displayName) — $($site.id)"

# ---------------------------------------------------------------------------
# 4. Grant this app access to THIS site only
# ---------------------------------------------------------------------------
Write-Step "Granting '$SiteRole' on the site"

$perms = (Graph -Uri "https://graph.microsoft.com/v1.0/sites/$($site.id)/permissions").value
$mine = $perms | Where-Object {
    $_.PSObject.Properties.Name -contains 'grantedToIdentities' -and
    ($_.grantedToIdentities | Where-Object { $_.application.id -eq $app.appId })
}

if ($mine) {
    Write-Ok "already granted: $($mine.roles -join ', ')"
    if ($mine.roles -notcontains $SiteRole) {
        Graph -Method PATCH -Uri "https://graph.microsoft.com/v1.0/sites/$($site.id)/permissions/$($mine.id)" -Body @{
            roles = @($SiteRole)
        } | Out-Null
        Write-Ok "updated role to '$SiteRole'"
    }
}
else {
    Graph -Method POST -Uri "https://graph.microsoft.com/v1.0/sites/$($site.id)/permissions" -Body @{
        roles                = @($SiteRole)
        grantedToIdentities  = @(
            @{ application = @{ id = $app.appId; displayName = $AppDisplayName } }
        )
    } | Out-Null
    Write-Ok "granted '$SiteRole' on this site only"
}

# ---------------------------------------------------------------------------
# 5. Document library
# ---------------------------------------------------------------------------
Write-Step "Document library '$LibraryName'"

$lists = (Graph -Uri "https://graph.microsoft.com/v1.0/sites/$($site.id)/lists?`$select=id,displayName,name,list").value
$list = $lists | Where-Object { $_.displayName -eq $LibraryName -or $_.name -eq $LibraryName } | Select-Object -First 1

if (-not $list) {
    $list = Graph -Method POST -Uri "https://graph.microsoft.com/v1.0/sites/$($site.id)/lists" -Body @{
        displayName = $LibraryName
        list        = @{ template = 'documentLibrary' }
    }
    Write-Ok "created library"
    Start-Sleep -Seconds 5
}
else {
    Write-Ok 'library exists'
}

$drive = Graph -Uri "https://graph.microsoft.com/v1.0/sites/$($site.id)/lists/$($list.id)/drive"
$driveId = $drive.id
Write-Ok "driveId $driveId"

# ---------------------------------------------------------------------------
# 6. Backup folder
# ---------------------------------------------------------------------------
Write-Step "Folder '$FolderPath'"

$folderClean = $FolderPath.Trim('/')
try {
    Graph -Uri "https://graph.microsoft.com/v1.0/drives/$driveId/root:/$($folderClean)" | Out-Null
    Write-Ok 'folder exists'
}
catch {
    Graph -Method POST -Uri "https://graph.microsoft.com/v1.0/drives/$driveId/root/children" -Body @{
        name                                = $folderClean
        folder                              = @{}
        '@microsoft.graph.conflictBehavior' = 'fail'
    } | Out-Null
    Write-Ok 'folder created'
}

# ---------------------------------------------------------------------------
# 7. Client secret
# ---------------------------------------------------------------------------
$secretText = $null
$secretEnd = $null

if (-not $NoSecret) {
    Write-Step 'Client secret'
    $secretEnd = (Get-Date).ToUniversalTime().AddMonths($SecretMonths)
    $pw = Graph -Method POST -Uri "https://graph.microsoft.com/v1.0/applications/$($app.id)/addPassword" -Body @{
        passwordCredential = @{
            displayName = "backup $(Get-Date -Format 'yyyy-MM-dd')"
            endDateTime = $secretEnd.ToString('o')
        }
    }
    $secretText = $pw.secretText
    Write-Ok "expires $($secretEnd.ToString('yyyy-MM-dd')) — Entra will NOT warn you"
}

# ---------------------------------------------------------------------------
# 8. Smoke test with an app-only token
# ---------------------------------------------------------------------------
if ($secretText) {
    Write-Step 'End-to-end write test with the app-only credential'
    Write-Info 'Role assignments and site grants take up to ~2 min to propagate.'

    $token = $null
    $deadline = (Get-Date).AddMinutes(3)

    while (-not $token -and (Get-Date) -lt $deadline) {
        try {
            $resp = Invoke-RestMethod -Method POST `
                -Uri "https://login.microsoftonline.com/$TenantId/oauth2/v2.0/token" `
                -Body @{
                    client_id     = $app.appId
                    client_secret = $secretText
                    scope         = 'https://graph.microsoft.com/.default'
                    grant_type    = 'client_credentials'
                }
            $token = $resp.access_token
        }
        catch {
            Write-Info 'token not ready, retrying in 15s...'
            Start-Sleep -Seconds 15
        }
    }
    if (-not $token) { throw 'Could not acquire an app-only token within 3 minutes.' }

    # Confirm the roles claim actually carries Sites.Selected.
    $payload = $token.Split('.')[1].Replace('-', '+').Replace('_', '/')
    while ($payload.Length % 4) { $payload += '=' }
    $claims = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($payload)) | ConvertFrom-Json
    Write-Ok "token roles: $($claims.roles -join ', ')"

    $hdr = @{ Authorization = "Bearer $token" }
    $probe = "https://graph.microsoft.com/v1.0/drives/$driveId/root:/$folderClean/.setup-write-test.txt:/content"

    $wrote = $false
    $deadline = (Get-Date).AddMinutes(3)
    while (-not $wrote -and (Get-Date) -lt $deadline) {
        try {
            $item = Invoke-RestMethod -Method PUT -Uri $probe -Headers $hdr `
                -ContentType 'text/plain' -Body "setup ok $(Get-Date -Format o)"
            $wrote = $true
            Write-Ok 'upload succeeded'
            Invoke-RestMethod -Method DELETE -Headers $hdr `
                -Uri "https://graph.microsoft.com/v1.0/drives/$driveId/items/$($item.id)" | Out-Null
            Write-Ok 'delete succeeded (retention will work)'
        }
        catch {
            Write-Info "site grant not live yet ($($_.Exception.Response.StatusCode)), retrying in 15s..."
            Start-Sleep -Seconds 15
        }
    }
    if (-not $wrote) { throw 'App-only write failed. Check the site permission grant in step 4.' }
}

# ---------------------------------------------------------------------------
# 9. Output
# ---------------------------------------------------------------------------
Write-Host ""
Write-Host "──────── config.php, under 'backup' ────────" -ForegroundColor Yellow
Write-Host "    'dsn' => 'msgraph://$TenantId/$($app.appId)@drive/$driveId/$folderClean',"
if ($secretText) {
    Write-Host "    'client_secret'            => '$secretText',"
    Write-Host "    'client_secret_expires_at' => '$($secretEnd.ToString('yyyy-MM-dd'))',"
}
Write-Host "────────────────────────────────────────────" -ForegroundColor Yellow
Write-Host ""
Write-Info 'The tenant id and client id are not secrets and live in the DSN; the secret'
Write-Info 'does not, because a DSN gets pasted into support threads and screenshots.'
Write-Info 'The secret is shown once and cannot be retrieved afterwards by anyone,'
Write-Info 'including Microsoft. Store it now.'
Write-Info "Rotate before $($secretEnd ? $secretEnd.ToString('yyyy-MM-dd') : 'expiry') with -RotateSecretOnly."
Write-Info 'Backups do not start until backup.recipient_public_keys is also set --'
Write-Info 'configuring a recipient key is what switches the nightly job on (ADR-0049).'
Write-Host ""
Write-Info 'Next: docs/m365-backup-target.md section 4.3 -- revoke the CLI helper consent.'

Disconnect-MgGraph | Out-Null
