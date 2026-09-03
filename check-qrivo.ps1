<#
    QRIVO — one-line health check for each moving part.

        powershell -ExecutionPolicy Bypass -File .\check-qrivo.ps1

    Prints one line per component: MySQL, API, teacher panel, public tunnel.
    Anything not UP comes with the exact thing to do about it.
    Exit code 0 = everything up, 1 = something is down.
#>

$MYSQLD     = 'C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqld.exe'
$TAILSCALE  = 'C:\Program Files\Tailscale\tailscale.exe'
$PUBLIC_URL = 'https://qrivo.tailbf9d6c.ts.net'

$failed = 0

function Report($name, $up, $detail, $fix) {
    if ($up) {
        Write-Host ("  {0,-14} UP     {1}" -f $name, $detail) -ForegroundColor Green
    } else {
        Write-Host ("  {0,-14} DOWN   {1}" -f $name, $detail) -ForegroundColor Red
        Write-Host ("  {0,-14}        -> {1}" -f "", $fix) -ForegroundColor Yellow
        $script:failed++
    }
}

function TryUrl($url, $timeout = 10) {
    try {
        $sw = [Diagnostics.Stopwatch]::StartNew()
        $r = Invoke-WebRequest $url -UseBasicParsing -TimeoutSec $timeout
        $sw.Stop()
        return @{ ok = ($r.StatusCode -eq 200); ms = [math]::Round($sw.Elapsed.TotalMilliseconds) }
    } catch {
        return @{ ok = $false; ms = -1; err = $_.Exception.Message.Split([Environment]::NewLine)[0] }
    }
}

Write-Host ""
Write-Host "QRIVO status" -ForegroundColor Cyan
Write-Host "------------" -ForegroundColor Cyan

# 1 — MySQL
$my = Get-Process mysqld -ErrorAction SilentlyContinue
Report "MySQL" ([bool]$my) `
    $(if ($my) { "pid $($my[0].Id)" } else { "not running" }) `
    "run .\start-qrivo.ps1  (or start MySQL from the Laragon window)"

# 2 — API (served by Apache, NOT php -S)
$httpd = Get-Process httpd -ErrorAction SilentlyContinue
$api = TryUrl "http://127.0.0.1:8000/api/v1/health"
Report "API" $api.ok `
    $(if ($api.ok) { "$($api.ms) ms  (Apache, $(@($httpd).Count) processes)" } else { "no answer on port 8000 - $($api.err)" }) `
    "run .\start-qrivo.ps1 ; if it still fails see deploy\windows\logs\apache-error.log"

# 3 — Teacher panel
$panel = TryUrl "http://127.0.0.1:8080"
Report "Teacher panel" $panel.ok `
    $(if ($panel.ok) { "$($panel.ms) ms" } else { "no answer on port 8080 - $($panel.err)" }) `
    "run .\start-qrivo.ps1"

# 4 — Public tunnel
$tsUp = $false
$tsDetail = "Tailscale not installed"
if (Test-Path $TAILSCALE) {
    $state = (& $TAILSCALE status --json 2>$null | ConvertFrom-Json).BackendState
    if ($state -eq 'Running') {
        $pub = TryUrl "$PUBLIC_URL/api/v1/health" 25
        $tsUp = $pub.ok
        $tsDetail = if ($pub.ok) { "$($pub.ms) ms  $PUBLIC_URL" } else { "tailnet up but public URL failed - $($pub.err)" }
    } else {
        $tsDetail = "Tailscale backend is '$state'"
    }
}
Report "Public tunnel" $tsUp $tsDetail `
    "start the Tailscale tray app, then run .\start-qrivo.ps1 ; check with: tailscale funnel status"

Write-Host ""
if ($failed -eq 0) {
    Write-Host "  All four are up. Phone URL: $PUBLIC_URL" -ForegroundColor Green
    Write-Host ""
    exit 0
} else {
    Write-Host "  $failed component(s) down - see the arrows above." -ForegroundColor Red
    Write-Host ""
    exit 1
}
