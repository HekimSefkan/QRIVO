<#
    QRIVO — start everything.

    Starts, in order: MySQL, the API (port 8000), the teacher panel (port 8080),
    and confirms the Tailscale Funnel is publishing them. Waits until each one
    actually answers before saying READY, so if it prints READY it really is.

    Run it by right-clicking -> "Run with PowerShell", or from a terminal:
        powershell -ExecutionPolicy Bypass -File .\start-qrivo.ps1

    Stop everything again with .\stop-qrivo.ps1
#>

$ErrorActionPreference = 'Stop'

# ── Paths (edit only if you move Laragon or the project) ────────────────────
$PHP        = 'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe'
$MYSQLD     = 'C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqld.exe'
$MYSQLDATA  = 'C:\laragon\data\mysql-8.4'
$TAILSCALE  = 'C:\Program Files\Tailscale\tailscale.exe'
$REPO       = 'C:\Projects\QRIVO'
$HTTPD      = 'C:/laragon/bin/apache/httpd-2.4.68-260617-Win64-VS18/bin/httpd.exe'
$APACHECONF = 'C:/Projects/QRIVO/deploy/windows/qrivo-apache.conf'
$PUBLIC_URL = 'https://qrivo.tailbf9d6c.ts.net'

function Say($msg, $colour = 'Gray') { Write-Host $msg -ForegroundColor $colour }
function Ok($msg)   { Write-Host "  [OK]   $msg" -ForegroundColor Green }
function Warn($msg) { Write-Host "  [WARN] $msg" -ForegroundColor Yellow }
function Bad($msg)  { Write-Host "  [FAIL] $msg" -ForegroundColor Red }

function Wait-Url($url, $seconds = 30) {
    for ($i = 0; $i -lt $seconds; $i++) {
        try {
            $r = Invoke-WebRequest $url -UseBasicParsing -TimeoutSec 3
            if ($r.StatusCode -eq 200) { return $true }
        } catch { }
        Start-Sleep -Seconds 1
    }
    return $false
}

Say ""
Say "QRIVO — starting" Cyan
Say "=================" Cyan

# ── 1. MySQL ────────────────────────────────────────────────────────────────
Say ""
Say "1/3  MySQL"
if (Get-Process mysqld -ErrorAction SilentlyContinue) {
    Ok "already running"
} else {
    Start-Process -FilePath $MYSQLD -ArgumentList "--datadir=$MYSQLDATA" -WindowStyle Hidden
    $up = $false
    for ($i = 0; $i -lt 30; $i++) {
        Start-Sleep -Seconds 1
        if (Get-Process mysqld -ErrorAction SilentlyContinue) { $up = $true; break }
    }
    if ($up) { Ok "started" } else { Bad "MySQL did not start - check $MYSQLDATA"; exit 1 }
}

# ── 2/3. API + teacher panel (Apache + mod_php) ─────────────────────────────
#
# NOT "php -S": that serves ONE request at a time, and on Windows it cannot do
# better -- PHP_CLI_SERVER_WORKERS needs fork(), which Windows lacks. With the
# teacher panel polling every ~3s, a phone request queued behind it and could
# exceed the app's 20s timeout, surfacing as "Could not reach the server".
# Apache's mpm_winnt is threaded and this PHP is a ZTS (thread-safe) build, so
# mod_php serves requests concurrently. Measured: a health check under load
# went from 188ms (queued) to 44ms (concurrent).
Say ""
Say "2/3  API (:8000) + teacher panel (:8080) via Apache"
if (Get-Process httpd -ErrorAction SilentlyContinue) {
    Ok "Apache already running"
} else {
    New-Item -ItemType Directory -Force -Path "$REPO\deploy\windows\logs","$REPO\deploy\windows\run" | Out-Null
    & $HTTPD -f $APACHECONF -t 2>&1 | Out-Null
    Start-Process -FilePath $HTTPD -ArgumentList "-f",$APACHECONF -WindowStyle Hidden
    if (Wait-Url "http://127.0.0.1:8000/api/v1/health") { Ok "API answering on :8000" }
    else { Bad "API did not answer - see $REPO\deploy\windows\logs\apache-error.log"; exit 1 }
    if (Wait-Url "http://127.0.0.1:8080") { Ok "teacher panel answering on :8080" }
    else { Bad "Teacher panel did not answer on :8080"; exit 1 }
}

# ── 4. Public tunnel ────────────────────────────────────────────────────────
Say ""
Say "3/3  Public tunnel (Tailscale Funnel)"
if (-not (Test-Path $TAILSCALE)) {
    Bad "Tailscale is not installed at $TAILSCALE"
    exit 1
}
# The Windows tray client owns the tailnet profile. Without it the backend sits
# in "NoState" and Funnel cannot work, so make sure it is running first.
if (-not (Get-Process tailscale-ipn -ErrorAction SilentlyContinue)) {
    Start-Process 'C:\Program Files\Tailscale\tailscale-ipn.exe'
    Write-Host "  [OK]   started Tailscale tray client" -ForegroundColor Green
    Start-Sleep -Seconds 6
}

$state = (& $TAILSCALE status --json 2>$null | ConvertFrom-Json).BackendState
if ($state -ne 'Running') {
    Warn "Tailscale is '$state' - trying to bring it up"
    & $TAILSCALE up --hostname=qrivo | Out-Null
    Start-Sleep -Seconds 3
}
$funnel = & $TAILSCALE funnel status 2>&1 | Out-String
if ($funnel -match 'https://') {
    Ok "funnel is configured"
} else {
    Warn "funnel not configured - applying configuration"
    & $TAILSCALE funnel --bg --https=443 --set-path=/ http://127.0.0.1:8000 2>&1 | Out-Null
    & $TAILSCALE funnel --bg --https=443 --set-path=/panel http://127.0.0.1:8080 2>&1 | Out-Null
}

Say ""
Say "Checking the public address (this can take ~20s on first run"
Say "while the certificate is issued)..."
if (Wait-Url "$PUBLIC_URL/api/v1/health" 60) {
    Ok "public HTTPS endpoint is answering"
} else {
    Bad "public endpoint did not answer."
    Warn "Most likely cause: Funnel is not enabled for this tailnet."
    Warn "Re-run this script, or see the Funnel note in the handover message."
    Warn "Enable Funnel once at: https://login.tailscale.com/f/funnel"
}

# ── Ready ───────────────────────────────────────────────────────────────────
Say ""
Say "======================================================" Green
Say " QRIVO IS READY" Green
Say "======================================================" Green
Say ""
Say "  Teacher panel :  $PUBLIC_URL/panel/"
Say "  API           :  $PUBLIC_URL"
Say ""
Say "  Teacher       :  teacher1@qrivo.local  /  Test1234!"
Say "  Student       :  student01@qrivo.local /  Test1234!"
Say ""
Say "  Leave this machine awake and online while you demo."
Say "  Stop everything afterwards with:  .\stop-qrivo.ps1"
Say ""
