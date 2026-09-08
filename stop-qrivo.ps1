<#
    QRIVO - stop everything started by start-qrivo.ps1.

        powershell -ExecutionPolicy Bypass -File .\stop-qrivo.ps1

    STOPS SERVICES, NOT PROCESSES. An earlier version force-killed httpd and
    mysqld with Stop-Process. Once these became Windows services that was wrong
    twice over:

      * MySQL never got to shut down. It came back with "Starting XA crash
        recovery" on the next start -- survivable, but it is a crash every time,
        and there is no reason to inflict one.
      * Both services are configured to restart after a failure (sc.exe failure
        ... restart/5000). Killing the process looks like a crash, so the
        service manager started it again five seconds later and the script
        appeared not to work at all.

    Stopping a service needs administrator rights. Without them this says so
    plainly instead of falling back to killing the process, which is the very
    thing that causes the problem above.

    By default MySQL is left running (it is slow to start and nothing else here
    needs it stopped). Use the switches to go further:

        -StopMySql    also stop MySQL
        -StopTunnel   also stop the public tunnel (cloudflared)
#>

param(
    [switch]$StopMySql,
    [switch]$StopTunnel
)

function Ok($m)   { Write-Host "  [OK]   $m" -ForegroundColor Green }
function Skip($m) { Write-Host "  [--]   $m" -ForegroundColor DarkGray }
function Warn($m) { Write-Host "  [WARN] $m" -ForegroundColor Yellow }

# Stop a component by service when one is installed, by process only when it is
# not. Returns nothing; prints what it did.
function Stop-Component {
    param([string]$Service, [string]$Process, [string]$Label)

    $svc = Get-Service $Service -ErrorAction SilentlyContinue
    if ($svc) {
        if ($svc.Status -eq 'Stopped') { Skip "$Label already stopped"; return }
        try {
            Stop-Service $Service -Force -ErrorAction Stop
            Ok "$Label stopped (service $Service)"
        } catch {
            Warn "$Label is a service and this shell cannot stop it."
            Warn "Run in an ADMINISTRATOR PowerShell:  Stop-Service $Service"
            Warn "(Not killing the process instead: the service would just"
            Warn " restart it five seconds later.)"
        }
        return
    }

    $procs = Get-Process $Process -ErrorAction SilentlyContinue
    if ($procs) {
        $n = @($procs).Count
        $procs | Stop-Process -Force -ErrorAction SilentlyContinue
        Ok "$Label stopped ($n loose process(es), no service installed)"
    } else {
        Skip "$Label was not running"
    }
}

Write-Host ""
Write-Host "QRIVO - stopping" -ForegroundColor Cyan
Write-Host "================" -ForegroundColor Cyan
Write-Host ""

# API + teacher panel are both served by one Apache instance.
Stop-Component -Service 'QRIVOApache' -Process 'httpd' -Label 'Apache (API + teacher panel)'

# Any stray `php -S` left over from the old single-threaded setup.
$php = Get-CimInstance Win32_Process -Filter "Name='php.exe'" |
       Where-Object { $_.CommandLine -match '-S ' }
if ($php) {
    foreach ($p in $php) {
        Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue
        Ok "stopped stray php -S (pid $($p.ProcessId))"
    }
}

# Tunnel. Tailscale was abandoned (Funnel never became reachable here) and so
# was ngrok (Defender quarantines the binary), so the only tunnel left is a
# cloudflared quick tunnel, which is an ordinary process with no service.
if ($StopTunnel) {
    $cf = Get-Process cloudflared -ErrorAction SilentlyContinue
    if ($cf) {
        $cf | Stop-Process -Force -ErrorAction SilentlyContinue
        Ok "public tunnel stopped (the published URL stops working)"
    } else {
        Skip "no tunnel was running"
    }
} else {
    Skip "tunnel left alone (use -StopTunnel to stop it)"
}

# MySQL
if ($StopMySql) {
    Stop-Component -Service 'QRIVOMySQL' -Process 'mysqld' -Label 'MySQL'
} else {
    Skip "MySQL left running (use -StopMySql to stop it)"
}

Write-Host ""
Write-Host "Done." -ForegroundColor Green
Write-Host ""
