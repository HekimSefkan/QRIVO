<#
    QRIVO — one-line health check for each moving part.

        powershell -ExecutionPolicy Bypass -File .\check-qrivo.ps1

    Prints one line per component: MySQL, API, teacher panel, public tunnel.
    Anything not UP comes with the exact thing to do about it.
    Exit code 0 = everything up, 1 = something is down.
#>

$MYSQLD     = 'C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqld.exe'
$PUBLIC_HOST = 'fanatic-blitz-eastbound.ngrok-free.dev'
$PUBLIC_URL  = "https://$PUBLIC_HOST"
$REPO        = 'C:\Projects\QRIVO'

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

# 4 — Public tunnel (ngrok)
#
# HONESTY RULE FOR THIS BLOCK.
# An earlier version fetched the public URL from this laptop and printed a green
# "Public tunnel UP" while the phone could not connect at all. That was a false
# positive: the machine was inside the Tailscale tailnet and resolved the name
# locally. The lesson generalises beyond Tailscale -- a request issued from the
# machine that HOSTS the service can succeed for reasons a phone on mobile data
# does not share.
#
# So this block does two separate things and never conflates them:
#   (a) local: is the ngrok agent even running?
#   (b) external: ask an INDEPENDENT third-party service to fetch a nonce we
#       just wrote. Only that can turn the line green.
# If the external check cannot be performed, it says so instead of guessing.

$ngrokRunning = [bool](Get-Process ngrok -ErrorAction SilentlyContinue)
Report "ngrok agent" $ngrokRunning `
    $(if ($ngrokRunning) { "running" } else { "not running" }) `
    "run .\start-qrivo.ps1 (if it dies instantly, Defender is blocking it - see docs/DEMO_DAY.md)"

$publicOk     = $false
$publicDetail = "not checked"
$publicFix    = "run .\start-qrivo.ps1, then re-check"

if ($ngrokRunning) {
    try {
        $nonce = "qrivo-" + [guid]::NewGuid().ToString('N').Substring(0,12)
        $nonce | Set-Content "$REPO\backend\public\probe.txt" -Encoding ascii -NoNewline
        $seen = Invoke-RestMethod "https://api.allorigins.win/raw?url=https://$PUBLIC_HOST/probe.txt" -TimeoutSec 25
        if ("$seen".Trim() -eq $nonce) {
            $publicOk = $true
            $publicDetail = "an external checker fetched our nonce - genuinely public"
        } else {
            $publicDetail = "external checker answered, but not with our nonce (stale cache or interstitial)"
            $publicFix    = "check https://$PUBLIC_HOST/probe.txt in a browser"
        }
    } catch {
        $publicDetail = "COULD NOT VERIFY externally: $($_.Exception.Message.Split([Environment]::NewLine)[0])"
        $publicFix    = "this is not proof it is down - test from your phone on mobile data"
    }
} else {
    $publicDetail = "skipped - the agent is not running"
}
Report "Public URL" $publicOk $publicDetail $publicFix

Write-Host ""
Write-Host "  The Public URL line above is green ONLY when an independent" -ForegroundColor DarkGray
Write-Host "  third-party service fetched a nonce written seconds earlier." -ForegroundColor DarkGray
Write-Host "  A request from this laptop is never accepted as proof." -ForegroundColor DarkGray
if ($failed -eq 0) {
    Write-Host "  Local stack is up and public DNS is published." -ForegroundColor Green
    Write-Host "  This is NOT proof the phone can connect - use the probe URL above." -ForegroundColor Yellow
    Write-Host ""
    exit 0
} else {
    Write-Host "  $failed component(s) down - see the arrows above." -ForegroundColor Red
    Write-Host ""
    exit 1
}
