<#
    QRIVO — one-line health check for each moving part.

        powershell -ExecutionPolicy Bypass -File .\check-qrivo.ps1

    Prints one line per component: MySQL, API, teacher panel, public tunnel.
    Anything not UP comes with the exact thing to do about it.
    Exit code 0 = everything up, 1 = something is down.
#>

$MYSQLD     = 'C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqld.exe'
$TAILSCALE  = 'C:\Program Files\Tailscale\tailscale.exe'
$PUBLIC_URL  = 'https://qrivo.tailbf9d6c.ts.net'
$PUBLIC_HOST = 'qrivo.tailbf9d6c.ts.net'

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
#
# IMPORTANT: this machine is INSIDE the tailnet, and the Tailscale Windows
# client intercepts *.ts.net lookups at the OS level. A plain
# Invoke-WebRequest to the public URL therefore resolves over MagicDNS to the
# 100.x tailnet address and NEVER touches the public Funnel path. An earlier
# version of this script did exactly that and reported a green "UP" while the
# phone could not connect at all.
#
# So we do two things this machine cannot fake:
#   1. resolve the name via Google DNS-over-HTTPS, which leaves this machine
#      and cannot be answered by MagicDNS, and
#   2. reject the answer if it is a CGNAT (100.64.0.0/10) address, because that
#      is the tailnet address and is not routable from the internet.
#
# Even then we only claim "DNS is published correctly". Whether a phone on a
# mobile network can complete a TLS handshake to those ingress IPs cannot be
# proven from here, and this script says so rather than guessing.
$tunnelOk = $false
$tunnelDetail = "not checked"
$tunnelFix = "start the Tailscale tray app, then run .\start-qrivo.ps1"

if (-not (Test-Path $TAILSCALE)) {
    $tunnelDetail = "Tailscale not installed"
} else {
    $state = (& $TAILSCALE status --json 2>$null | ConvertFrom-Json).BackendState
    if ($state -ne 'Running') {
        $tunnelDetail = "Tailscale backend is '$state' (start the tray app)"
    } else {
        $funnelOn = ((& $TAILSCALE funnel status 2>&1 | Out-String) -match 'Funnel on')
        if (-not $funnelOn) {
            $tunnelDetail = "Funnel is not configured on this node"
            $tunnelFix = "run .\start-qrivo.ps1, or: tailscale funnel --bg --https=443 --set-path=/ http://127.0.0.1:8000"
        } else {
            try {
                $doh = Invoke-RestMethod "https://dns.google/resolve?name=$PUBLIC_HOST&type=A" -TimeoutSec 15
                $ips = @($doh.Answer | Where-Object { $_.type -eq 1 } | Select-Object -ExpandProperty data)
                if (-not $ips) {
                    $tunnelDetail = "public DNS has no A record for $PUBLIC_HOST"
                    $tunnelFix = "re-enable Funnel: https://login.tailscale.com/admin/settings/general"
                } else {
                    $cgnat = @($ips | Where-Object { $_ -match '^100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.' })
                    if ($cgnat.Count -eq $ips.Count) {
                        $tunnelDetail = "public DNS returns only the tailnet address ($($ips -join ', ')) - NOT public"
                        $tunnelFix = "Funnel is not published. Enable it at https://login.tailscale.com/admin/settings/general"
                    } else {
                        $tunnelOk = $true
                        $tunnelDetail = "published publicly -> $($ips -join ', ')"
                    }
                }
            } catch {
                $tunnelDetail = "could not verify externally: $($_.Exception.Message.Split([Environment]::NewLine)[0])"
                $tunnelFix = "check this machine has internet access, then re-run"
            }
        }
    }
}
Report "Public DNS" $tunnelOk $tunnelDetail $tunnelFix

Write-Host ""
Write-Host "  NOTE: this machine is inside the tailnet, so it CANNOT prove that a" -ForegroundColor DarkGray
Write-Host "        phone on mobile data can reach the API. The only real test is:" -ForegroundColor DarkGray
Write-Host "        turn Wi-Fi OFF on the phone and open" -ForegroundColor DarkGray
Write-Host "        https://$PUBLIC_HOST/panel/probe.txt" -ForegroundColor DarkGray
Write-Host ""
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
