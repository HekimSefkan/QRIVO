<#
    QRIVO - stop everything started by start-qrivo.ps1.

        powershell -ExecutionPolicy Bypass -File .\stop-qrivo.ps1

    By default this leaves MySQL running (Laragon and other projects may be
    using it) and leaves the Tailscale Funnel configured (so the next start is
    instant). Use the switches to go further:

        -StopMySql    also stop MySQL
        -StopTunnel   also tear down the public Funnel URL
#>

param(
    [switch]$StopMySql,
    [switch]$StopTunnel
)

$TAILSCALE = 'C:\Program Files\Tailscale\tailscale.exe'

function Ok($m)   { Write-Host "  [OK]   $m" -ForegroundColor Green }
function Skip($m) { Write-Host "  [--]   $m" -ForegroundColor DarkGray }

Write-Host ""
Write-Host "QRIVO - stopping" -ForegroundColor Cyan
Write-Host "================" -ForegroundColor Cyan
Write-Host ""

# API + teacher panel are both served by one Apache instance.
$httpd = Get-Process httpd -ErrorAction SilentlyContinue
if ($httpd) {
    $n = @($httpd).Count
    $httpd | Stop-Process -Force -ErrorAction SilentlyContinue
    Ok "stopped Apache ($n process(es)) - API and teacher panel are down"
} else {
    Skip "Apache was not running"
}

# Any stray `php -S` left over from the old single-threaded setup.
$php = Get-CimInstance Win32_Process -Filter "Name='php.exe'" |
       Where-Object { $_.CommandLine -match '-S ' }
if ($php) {
    foreach ($p in $php) {
        Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue
        Ok "stopped stray php -S (pid $($p.ProcessId))"
    }
}

# Tunnel
if ($StopTunnel) {
    if (Test-Path $TAILSCALE) {
        & $TAILSCALE funnel reset 2>&1 | Out-Null
        Ok "public tunnel torn down (the URL stops working)"
    }
} else {
    Skip "tunnel left configured (use -StopTunnel to remove it)"
}

# MySQL
if ($StopMySql) {
    $my = Get-Process mysqld -ErrorAction SilentlyContinue
    if ($my) { $my | Stop-Process -Force; Ok "MySQL stopped" } else { Skip "MySQL was not running" }
} else {
    Skip "MySQL left running (use -StopMySql to stop it)"
}

Write-Host ""
Write-Host "Done." -ForegroundColor Green
Write-Host ""
