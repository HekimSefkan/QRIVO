<#
    QRIVO — start everything.

    Brings up MySQL, the API (:8000) and the teacher panel (:8080), then waits
    until each one actually answers before saying READY -- so if it prints READY
    it really is.

    MySQL and Apache are installed as Windows SERVICES (see
    deploy\windows\install-autostart.ps1) and start at boot on their own, so
    normally this script only confirms them. It starts them as loose processes
    ONLY when the services are not installed at all. It will never start a loose
    mysqld alongside a stopped service: two mysqld processes cannot share one
    data directory, and the second failure is far more confusing than the first.

    The public tunnel is NOT started. It is not needed for the demo, which runs
    over the laptop hotspot with no internet at all. Pass -Tunnel if you
    genuinely want remote access.

    Run it by right-clicking -> "Run with PowerShell", or from a terminal:
        powershell -ExecutionPolicy Bypass -File .\start-qrivo.ps1

    Stop everything again with .\stop-qrivo.ps1
#>

param([switch]$Tunnel)

$ErrorActionPreference = 'Stop'

# ── Paths (edit only if you move Laragon or the project) ────────────────────
$PHP        = 'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe'
$MYSQLD     = 'C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqld.exe'
$MYSQLDATA  = 'C:\laragon\data\mysql-8.4'
$TAILSCALE  = 'C:\Program Files\Tailscale\tailscale.exe'
$REPO       = 'C:\Projects\QRIVO'
$HTTPD      = 'C:/laragon/bin/apache/httpd-2.4.68-260617-Win64-VS18/bin/httpd.exe'
$APACHECONF = 'C:/Projects/QRIVO/deploy/windows/qrivo-apache.conf'
$PUBLIC_URL  = ""   # discovered at runtime; the tunnel hostname changes each start

function Say($msg, $colour = 'Gray') { Write-Host $msg -ForegroundColor $colour }
function Ok($msg)   { Write-Host "  [OK]   $msg" -ForegroundColor Green }
function Warn($msg) { Write-Host "  [WARN] $msg" -ForegroundColor Yellow }
function Bad($msg)  { Write-Host "  [FAIL] $msg" -ForegroundColor Red }
function Info($msg) { Write-Host "  [ .. ] $msg" -ForegroundColor DarkGray }

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
$svc = Get-Service QRIVOMySQL -ErrorAction SilentlyContinue
if (Get-Process mysqld -ErrorAction SilentlyContinue) {
    if ($svc -and $svc.Status -ne 'Running') {
        Warn "mysqld is running, but NOT as the QRIVOMySQL service."
        Warn "It works, but it dies with this login and will block the service"
        Warn "on next boot. To hand it back to the service, in an ADMIN shell:"
        Warn "    Stop-Process -Name mysqld -Force; Start-Service QRIVOMySQL"
    } else {
        Ok "already running (service)"
    }
} elseif ($svc) {
    # The service exists, so it -- and only it -- owns the data directory.
    # Starting a loose mysqld here would take that directory and make the
    # service fail at next boot with a message that does not name the cause.
    try {
        Start-Service QRIVOMySQL -ErrorAction Stop
        Ok "service QRIVOMySQL started"
    } catch {
        Bad "QRIVOMySQL is installed but stopped, and this shell cannot start it."
        Bad "Run this in an ADMINISTRATOR PowerShell:  Start-Service QRIVOMySQL"
        Bad "(Deliberately NOT starting a loose mysqld: it would hold the data"
        Bad " directory and break the service at next boot.)"
        exit 1
    }
} else {
    Warn "no QRIVOMySQL service installed - starting a loose mysqld"
    Warn "install it properly with deploy\windows\install-autostart.ps1 (as admin)"
    Start-Process -FilePath $MYSQLD -ArgumentList "--datadir=$MYSQLDATA" -WindowStyle Hidden
    $up = $false
    for ($i = 0; $i -lt 30; $i++) {
        Start-Sleep -Seconds 1
        if (Get-Process mysqld -ErrorAction SilentlyContinue) { $up = $true; break }
    }
    if ($up) { Ok "started (loose process)" } else { Bad "MySQL did not start - check $MYSQLDATA"; exit 1 }
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
$asvc = Get-Service QRIVOApache -ErrorAction SilentlyContinue
if (Get-Process httpd -ErrorAction SilentlyContinue) {
    Ok "Apache already running"
} elseif ($asvc) {
    try { Start-Service QRIVOApache -ErrorAction Stop; Ok "service QRIVOApache started" }
    catch { Bad "QRIVOApache is stopped and this shell cannot start it."; Bad "Run in an ADMINISTRATOR PowerShell:  Start-Service QRIVOApache"; exit 1 }
} else {
    Warn "no QRIVOApache service installed - starting a loose httpd"
    New-Item -ItemType Directory -Force -Path "$REPO\deploy\windows\logs","$REPO\deploy\windows\run" | Out-Null
    & $HTTPD -f $APACHECONF -t 2>&1 | Out-Null
    Start-Process -FilePath $HTTPD -ArgumentList "-f",$APACHECONF -WindowStyle Hidden
    if (Wait-Url "http://127.0.0.1:8000/api/v1/health") { Ok "API answering on :8000" }
    else { Bad "API did not answer - see $REPO\deploy\windows\logs\apache-error.log"; exit 1 }
    if (Wait-Url "http://127.0.0.1:8080") { Ok "teacher panel answering on :8080" }
    else { Bad "Teacher panel did not answer on :8080"; exit 1 }
}


# ── 3/3. Public tunnel — OPT-IN ONLY ────────────────────────────────────────
#
# Off by default. The demo runs over the laptop hotspot and needs no internet,
# and a Cloudflare quick tunnel is not dependable enough to stand in front of a
# jury with: measured 2026-09-08, roughly one attempt in three never becomes
# reachable at all. Starting it also used to be what popped console windows
# over the screen every five minutes.
Say ""
if ($Tunnel) {
    Say "3/3  Public tunnel + address publication (-Tunnel was passed)"
    $published = & powershell -NoProfile -ExecutionPolicy Bypass -File "$REPO\deploy\windows\publish-endpoint.ps1"
    $PUBLIC_URL = ($published | Select-String 'https://[a-z0-9-]+\.trycloudflare\.com' | Select-Object -Last 1).Matches.Value
    if (-not $PUBLIC_URL) { Warn "no address was published - the phone will keep using its cached one" }
} else {
    Say "3/3  Public tunnel"
    Info "skipped - not needed for the demo. Pass -Tunnel if you want remote access."
}
# ── Hotspot (the demo-day path) ─────────────────────────────────────────────
# Apache already listens on 0.0.0.0, so nothing needs starting for this -- it
# is purely a report on whether the offline path is available right now.
Say ""
Say "Hotspot (offline demo path)"
$hs = Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
      Where-Object { $_.IPAddress -eq '192.168.137.1' }
if ($hs) {
    if (Wait-Url "http://192.168.137.1:8000/api/v1/health" 5) {
        Ok "hotspot is up and the API answers on 192.168.137.1:8000"
    } else {
        Warn "192.168.137.1 exists but the API did not answer - check the firewall rule"
    }
} else {
    Warn "hotspot is OFF. Turn on Windows Mobile Hotspot (Win+A -> Mobile hotspot)"
    Warn "for the demo path that needs no internet at all."
}

# ── Ready ───────────────────────────────────────────────────────────────────
Say ""
Say "======================================================" Green
Say " QRIVO IS READY" Green
Say "======================================================" Green
Say ""
Say "  Teacher panel :  http://127.0.0.1:8000/panel/   (open this on THIS laptop)"
Say "                   ^ port 8000, NOT 8080: same origin as the API, so no CORS."
if ($PUBLIC_URL) {
    Say "  Public API    :  $PUBLIC_URL"
} else {
    Say "  Phone uses    :  http://192.168.137.1:8000  (over the laptop hotspot)"
}
Say ""
Say "  Teacher       :  teacher1@qrivo.local  /  Test1234!"
Say "  Student       :  student01@qrivo.local /  Test1234!"
Say ""
Say "  Stop everything with:  .\stop-qrivo.ps1"
Say ""
