<#
    QRIVO — start the public tunnel and publish its address.

    Cloudflare quick tunnels get a fresh random hostname every time they start.
    Rather than rebuild the APK after every restart, the app ships a fixed
    CONFIG URL and learns the current address from it. This script is the
    publisher: it starts a tunnel, PROVES it is actually reachable, and only
    then force-pushes the address to the `endpoint` branch of this repository.

    WHY IT RETRIES WITH A FRESH TUNNEL
    Measured on 2026-09-08: roughly ONE IN THREE quick tunnels never becomes
    reachable. cloudflared reports success and prints a hostname, but registers
    only one connection instead of four and the hostname stays NXDOMAIN
    indefinitely. It happens on both quic and http2, and a tunnel that had been
    serving traffic for an hour was withdrawn the same way. Cloudflare's own
    banner says these account-less tunnels "have no uptime guarantee".

    A single attempt therefore fails about a third of the time. Discarding a
    dead tunnel and asking for another turns that into near-certainty within a
    few minutes, with nobody watching. This is a workaround for an unreliable
    free service, not a fix -- see docs/DEMO_DAY.md for the durable options.

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
#>

param([switch]$Quiet)

# PowerShell 5.1 wraps a native command's stderr in ErrorRecords when 2>&1 is
# used, and with 'Stop' that turns an ordinary git warning into a terminating
# error -- or, as observed here, a deadlock at `git worktree add`. Native calls
# below therefore never redirect stderr, and this stays 'Continue'.
$ErrorActionPreference = 'Continue'

$REPO        = 'C:\Projects\QRIVO'
$WORKTREE    = 'C:\Projects\QRIVO-endpoint'
$CLOUDFLARED = 'C:\Program Files (x86)\cloudflared\cloudflared.exe'
$LOGDIR      = "$REPO\deploy\windows\logs"
$TUNLOG      = "$LOGDIR\cloudflared.log"
$API_CONFIG  = 'https://api.github.com/repos/HekimSefkan/QRIVO/contents/endpoint.json?ref=endpoint'

# Attempts to get a WORKING tunnel, and how long to give each one. Four
# attempts at ~100s fits inside the scheduled task's 10-minute limit.
$MAX_ATTEMPTS   = 4
$REACHABLE_WAIT = [TimeSpan]::FromSeconds(100)

function Say($m, $c = 'Gray') { if (-not $Quiet) { Write-Host $m -ForegroundColor $c } }

New-Item -ItemType Directory -Force -Path $LOGDIR | Out-Null

# Log to a file. When this runs as a scheduled task there is no console, and a
# bare exit code says nothing about how far it got.
$TRANSCRIPT = "$LOGDIR\publish-endpoint.log"
function Log($m) { "$(Get-Date -Format 'HH:mm:ss')  $m" | Out-File -Append -Encoding utf8 $TRANSCRIPT }

function Ok($m)   { Say "  [OK]   $m" Green;  Log "OK   $m" }
function Warn($m) { Say "  [WARN] $m" Yellow; Log "WARN $m" }
function Bad($m)  { Say "  [FAIL] $m" Red;    Log "FAIL $m" }

Log "=== run start (session: $(if ($Quiet) { 'scheduled task' } else { 'interactive' })) ==="

if (-not (Test-Path $CLOUDFLARED)) { Bad "cloudflared not found at $CLOUDFLARED"; exit 1 }

function Get-TunnelUrl {
    $m = Select-String -Path $TUNLOG -Pattern 'https://[a-z0-9-]+\.trycloudflare\.com' `
            -AllMatches -ErrorAction SilentlyContinue | Select-Object -Last 1
    if ($m) { return $m.Matches[-1].Value }
    return $null
}

function Test-Reachable($url, [TimeSpan]$budget) {
    $deadline = (Get-Date).Add($budget)
    while ((Get-Date) -lt $deadline) {
        try {
            $r = Invoke-WebRequest "$url/api/v1/health" -UseBasicParsing -TimeoutSec 8
            if ($r.StatusCode -eq 200) { return $true }
        } catch { }
        Start-Sleep -Seconds 4
    }
    return $false
}

# ── 1. Get a tunnel that actually works ─────────────────────────────────────
$publicUrl = $null

# An already-running tunnel is reused if it still serves. That keeps the
# 5-minute reconciler cheap and avoids churning a healthy tunnel.
if (Get-Process cloudflared -ErrorAction SilentlyContinue) {
    $existing = Get-TunnelUrl
    if ($existing -and (Test-Reachable $existing ([TimeSpan]::FromSeconds(12)))) {
        Ok "existing tunnel is healthy: $existing"
        $publicUrl = $existing
    } else {
        Warn "existing tunnel is not serving - replacing it"
        Get-Process cloudflared -ErrorAction SilentlyContinue | Stop-Process -Force
        Start-Sleep -Seconds 3
    }
}

if (-not $publicUrl) {
    for ($attempt = 1; $attempt -le $MAX_ATTEMPTS; $attempt++) {
        Log "attempt $attempt of ${MAX_ATTEMPTS}: requesting a new quick tunnel"
        Get-Process cloudflared -ErrorAction SilentlyContinue | Stop-Process -Force
        Start-Sleep -Seconds 2
        if (Test-Path $TUNLOG) { Remove-Item -LiteralPath $TUNLOG -Force -ErrorAction SilentlyContinue }

        Start-Process -FilePath $CLOUDFLARED `
            -ArgumentList 'tunnel','--no-autoupdate','--url','http://127.0.0.1:8000' `
            -RedirectStandardError $TUNLOG -RedirectStandardOutput "$LOGDIR\cloudflared.out.log" `
            -WindowStyle Hidden

        $candidate = $null
        for ($i = 0; $i -lt 40; $i++) {
            Start-Sleep -Milliseconds 750
            $candidate = Get-TunnelUrl
            if ($candidate) { break }
        }
        if (-not $candidate) { Warn "attempt ${attempt}: cloudflared printed no URL"; continue }

        Log "attempt ${attempt}: got $candidate - waiting for it to become reachable"
        if (Test-Reachable $candidate $REACHABLE_WAIT) {
            Ok "tunnel is serving the API: $candidate"
            $publicUrl = $candidate
            break
        }

        # The signature of the failure mode: one registered connection, not four.
        $conns = (Select-String -Path $TUNLOG -Pattern 'Registered tunnel connection' -ErrorAction SilentlyContinue).Count
        Warn "attempt ${attempt}: $candidate never became reachable (registered $conns connection(s); healthy is 4) - discarding it"
    }
}

if (-not $publicUrl) {
    Bad "no working tunnel after $MAX_ATTEMPTS attempts."
    Warn "Cloudflare quick tunnels are failing right now. The app keeps using its"
    Warn "cached address. See the fallback in docs/DEMO_DAY.md."
    Log "=== run end (no working tunnel) ==="
    exit 1
}

# ── 2. Already correct? Then do nothing ─────────────────────────────────────
try {
    $already = Invoke-RestMethod $API_CONFIG -Headers @{ Accept = 'application/vnd.github.raw' } -TimeoutSec 15
    if ($already.api_base_url -eq $publicUrl) {
        Ok "published address already matches - nothing to do"
        Log "=== run end (no change) ==="
        if (-not $Quiet) { Write-Host ""; Write-Host "  Public API : $publicUrl" -ForegroundColor Cyan; Write-Host "" }
        $publicUrl
        exit 0
    }
} catch { Log "could not read the current published document; publishing anyway" }

# ── 3. Publish ──────────────────────────────────────────────────────────────
if (-not (Test-Path "$WORKTREE\.git")) {
    Log "preparing the publication worktree (first run only)"
    & git -C $REPO worktree prune | Out-Null
    & git -C $REPO worktree add -f --checkout $WORKTREE endpoint | Out-Null
    if (-not (Test-Path "$WORKTREE\.git")) { Bad "could not create the worktree at $WORKTREE"; exit 1 }
}

$payload = [ordered]@{
    api_base_url = $publicUrl
    generated_at = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
    note         = 'Published by publish-endpoint.ps1. Carries an address and nothing else.'
} | ConvertTo-Json

# UTF-8 without a BOM: a BOM would make the leading byte non-JSON for strict parsers.
[System.IO.File]::WriteAllText("$WORKTREE\endpoint.json", $payload, (New-Object System.Text.UTF8Encoding($false)))

Push-Location $WORKTREE
try {
    & git add endpoint.json | Out-Null
    & git -c user.name="QRIVO" -c user.email="noreply@qrivo.local" commit -q -m "chore: publish tunnel address $(Get-Date -Format 'yyyy-MM-dd HH:mm')" | Out-Null
    & git push -q -f origin endpoint | Out-Null
    if ($LASTEXITCODE -eq 0) { Ok "published to the endpoint branch" }
    else { Warn "git push failed - the app will keep using its cached address" }
} finally { Pop-Location }

# ── 4. Prove the published document is really live ──────────────────────────
Start-Sleep -Seconds 2
try {
    $live = Invoke-RestMethod $API_CONFIG -Headers @{ Accept = 'application/vnd.github.raw' } -TimeoutSec 20
    if ($live.api_base_url -eq $publicUrl) { Ok "config document is live and matches" }
    else { Warn "config is live but shows $($live.api_base_url)" }
} catch { Warn "could not read back the config document" }

Log "=== run end (published $publicUrl) ==="

if (-not $Quiet) {
    Write-Host ""
    Write-Host "  Public API : $publicUrl" -ForegroundColor Cyan
    Write-Host ""
}

$publicUrl
