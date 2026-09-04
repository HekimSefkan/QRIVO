<#
    QRIVO — start the public tunnel and publish its address.

    Cloudflare quick tunnels get a fresh random hostname every time they start.
    Rather than rebuild the APK after every restart, the app ships a fixed
    CONFIG URL and learns the current address from it. This script is the
    publisher: it starts the tunnel, captures the new hostname, and force-pushes
    it to the `endpoint` branch of this repository, which GitHub serves at

        https://raw.githubusercontent.com/HekimSefkan/QRIVO/endpoint/endpoint.json

    WHY A BRANCH AND NOT A GIST
    A Gist needs a token with gist scope. Pushing to a branch of a repository
    that git already has credentials for needs nothing new -- no token is
    created, stored or committed. The branch is an ORPHAN, force-pushed each
    time, so restart churn never touches main's history.

    WHAT IS PUBLISHED
    An address and nothing else. No secrets, no policy, no credentials. The
    server remains the sole authority for every security decision, and the app
    pins the host shape (https + *.trycloudflare.com) so this file cannot point
    it at an arbitrary host.

    Called by start-qrivo.ps1. Safe to run on its own.
#>

param([switch]$Quiet)

# PowerShell 5.1 wraps a native command's stderr in ErrorRecords when 2>&1 is
# used, and with 'Stop' that turns an ordinary git warning into a terminating
# error -- or, as observed here, a deadlock at `git worktree add`. Native calls
# below therefore never redirect stderr, and this stays 'Continue'.
$ErrorActionPreference = 'Continue'

$REPO      = 'C:\Projects\QRIVO'
$WORKTREE  = 'C:\Projects\QRIVO-endpoint'
$CLOUDFLARED = 'C:\Program Files (x86)\cloudflared\cloudflared.exe'
$LOGDIR    = "$REPO\deploy\windows\logs"
$TUNLOG    = "$LOGDIR\cloudflared.log"

function Say($m, $c = 'Gray') { if (-not $Quiet) { Write-Host $m -ForegroundColor $c } }
function Ok($m)   { Say "  [OK]   $m" Green }
function Warn($m) { Say "  [WARN] $m" Yellow }
function Bad($m)  { Say "  [FAIL] $m" Red }

New-Item -ItemType Directory -Force -Path $LOGDIR | Out-Null

# ── 1. Start the tunnel ─────────────────────────────────────────────────────
if (Get-Process cloudflared -ErrorAction SilentlyContinue) {
    Ok "cloudflared already running"
} else {
    if (-not (Test-Path $CLOUDFLARED)) { Bad "cloudflared not found at $CLOUDFLARED"; exit 1 }
    Remove-Item $TUNLOG -ErrorAction SilentlyContinue
    Start-Process -FilePath $CLOUDFLARED `
        -ArgumentList 'tunnel','--no-autoupdate','--url','http://127.0.0.1:8000' `
        -RedirectStandardError $TUNLOG -RedirectStandardOutput "$LOGDIR\cloudflared.out.log" `
        -WindowStyle Hidden
    Ok "cloudflared started"
}

# ── 2. Capture the public hostname ──────────────────────────────────────────
$publicUrl = $null
for ($i = 0; $i -lt 40; $i++) {
    Start-Sleep -Milliseconds 750
    foreach ($f in @($TUNLOG, "$LOGDIR\cloudflared.out.log")) {
        if (Test-Path $f) {
            $m = Select-String -Path $f -Pattern 'https://[a-z0-9-]+\.trycloudflare\.com' -AllMatches -ErrorAction SilentlyContinue |
                 Select-Object -Last 1
            if ($m) { $publicUrl = $m.Matches[-1].Value; break }
        }
    }
    if ($publicUrl) { break }
}

if (-not $publicUrl) {
    Bad "could not capture a tunnel URL - see $TUNLOG"
    exit 1
}
Ok "tunnel URL: $publicUrl"

# ── 3. Confirm it actually serves before publishing it ──────────────────────
# Publishing an address that does not work is worse than publishing nothing:
# the app would adopt it and every request would fail.
$serving = $false
for ($i = 0; $i -lt 20; $i++) {
    try {
        $r = Invoke-WebRequest "$publicUrl/api/v1/health" -UseBasicParsing -TimeoutSec 8
        if ($r.StatusCode -eq 200) { $serving = $true; break }
    } catch { Start-Sleep -Seconds 2 }
}
if (-not $serving) {
    Bad "the tunnel is up but $publicUrl/api/v1/health does not answer."
    Warn "Not publishing a dead address. Is Apache running on :8000?"
    exit 1
}
Ok "tunnel is serving the API"

# ── 4. Publish ──────────────────────────────────────────────────────────────
if (-not (Test-Path "$WORKTREE\.git")) {
    Say "  preparing the publication worktree (first run only)..."
    & git -C $REPO worktree prune | Out-Null
    & git -C $REPO worktree add -f --checkout $WORKTREE endpoint | Out-Null
    if (-not (Test-Path "$WORKTREE\.git")) { Bad "could not create the worktree at $WORKTREE"; exit 1 }
    Ok "worktree ready at $WORKTREE"
}

$payload = [ordered]@{
    api_base_url = $publicUrl
    generated_at = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
    note         = 'Published by start-qrivo.ps1. Carries an address and nothing else.'
} | ConvertTo-Json

# ASCII, no BOM: a BOM would make the leading byte non-JSON for strict parsers.
[System.IO.File]::WriteAllText("$WORKTREE\endpoint.json", $payload, (New-Object System.Text.UTF8Encoding($false)))

Push-Location $WORKTREE
try {
    & git add endpoint.json | Out-Null
    & git -c user.name="QRIVO" -c user.email="noreply@qrivo.local" commit -q -m "chore: publish tunnel address $(Get-Date -Format 'yyyy-MM-dd HH:mm')" | Out-Null
    & git push -q -f origin endpoint | Out-Null
    if ($LASTEXITCODE -eq 0) { Ok "published to the endpoint branch" }
    else { Warn "git push failed - the app will keep using its cached address" }
} finally { Pop-Location }

# ── 5. Prove the published document is really live ──────────────────────────
Start-Sleep -Seconds 3
try {
    $live = Invoke-RestMethod "https://raw.githubusercontent.com/HekimSefkan/QRIVO/endpoint/endpoint.json?t=$([DateTimeOffset]::Now.ToUnixTimeSeconds())" -TimeoutSec 20
    if ($live.api_base_url -eq $publicUrl) { Ok "config document is live and matches" }
    else { Warn "config is live but still shows $($live.api_base_url) (CDN cache; usually clears within a minute)" }
} catch { Warn "could not read back the config document: $($_.Exception.Message.Split([Environment]::NewLine)[0])" }

if (-not $Quiet) {
    Write-Host ""
    Write-Host "  Public API : $publicUrl" -ForegroundColor Cyan
    Write-Host ""
}

# Emit the URL so a caller can capture it.
$publicUrl
