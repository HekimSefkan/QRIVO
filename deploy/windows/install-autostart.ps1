<#
    QRIVO — install autostart. RUN ONCE, AS ADMINISTRATOR.

        Right-click PowerShell -> "Run as administrator", then:
        powershell -ExecutionPolicy Bypass -File C:\Projects\QRIVO\deploy\windows\install-autostart.ps1

    WHY THIS EXISTS
    Nothing in the QRIVO stack was ever configured to start. Verified on
    2026-09-04: `Get-CimInstance Win32_Service` matched nothing for
    mysql/apache/httpd/laragon, there were no scheduled tasks, and every
    shutdown in the System event log was a clean user-initiated power-off
    (Event 1074, "on behalf of user"). Nothing was crashing -- MySQL and Apache
    were running only because Laragon's GUI had launched them as ordinary child
    processes, so a normal shutdown ended them and a reboot brought nothing back.

    WHAT THIS INSTALLS
      QRIVOMySQL     Windows service   MySQL 8.4 on the QRIVO data directory
      QRIVOApache    Windows service   Apache + mod_php serving :8000 and :8080
      QRIVO-Tunnel   Scheduled task    cloudflared + publish the new address

    WHY SERVICES FOR TWO AND A TASK FOR THE THIRD
    MySQL and Apache ship native service installers, which gives real crash
    recovery: this configures them to restart automatically after a failure.
    The tunnel is different. A Cloudflare quick tunnel gets a NEW hostname every
    time it starts, so starting it is only half the job -- the new address has
    to be published for the phone to find it. That publish step pushes to this
    repository using the git credentials of the logged-in user, which a SYSTEM
    service does not have. It therefore runs as a logon task, as you.

    CONSEQUENCE, STATED PLAINLY: MySQL and Apache come up at BOOT, before login.
    The tunnel comes up when you LOG IN. On a laptop you log into anyway that is
    the same thing in practice, but it is not the same thing in principle.

    Laragon's own Apache on :443 is NOT touched. Nothing is excluded from
    Windows Defender.
#>

param([switch]$Uninstall)

$ErrorActionPreference = 'Stop'

# ── Must be elevated ────────────────────────────────────────────────────────
$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host ""
    Write-Host "  This script must be run as Administrator." -ForegroundColor Red
    Write-Host "  Right-click PowerShell -> Run as administrator, then run it again." -ForegroundColor Yellow
    Write-Host ""
    exit 1
}

$REPO       = 'C:\Projects\QRIVO'
$MYSQLD     = 'C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqld.exe'
$MYSQLDATA  = 'C:\laragon\data\mysql-8.4'
$HTTPD      = 'C:\laragon\bin\apache\httpd-2.4.68-260617-Win64-VS18\bin\httpd.exe'
$APACHECONF = 'C:/Projects/QRIVO/deploy/windows/qrivo-apache.conf'
$MYINI      = "$REPO\deploy\windows\my.ini"
$PUBLISHER  = "$REPO\deploy\windows\publish-endpoint.ps1"

function Ok($m)   { Write-Host "  [OK]   $m" -ForegroundColor Green }
function Info($m) { Write-Host "  [--]   $m" -ForegroundColor DarkGray }
function Warn($m) { Write-Host "  [WARN] $m" -ForegroundColor Yellow }
function Bad($m)  { Write-Host "  [FAIL] $m" -ForegroundColor Red }

Write-Host ""
Write-Host "QRIVO autostart installer" -ForegroundColor Cyan
Write-Host "=========================" -ForegroundColor Cyan
Write-Host ""

# ── Uninstall ───────────────────────────────────────────────────────────────
if ($Uninstall) {
    Write-Host "Removing QRIVO autostart..." -ForegroundColor Yellow
    foreach ($svc in @('QRIVOApache','QRIVOMySQL')) {
        if (Get-Service $svc -ErrorAction SilentlyContinue) {
            Stop-Service $svc -Force -ErrorAction SilentlyContinue
            & sc.exe delete $svc | Out-Null
            Ok "removed service $svc"
        } else { Info "service $svc not present" }
    }
    foreach ($task in @('QRIVO-Tunnel','QRIVO-Ngrok')) {
        if (Get-ScheduledTask -TaskName $task -ErrorAction SilentlyContinue) {
            Unregister-ScheduledTask -TaskName $task -Confirm:$false
            Ok "removed scheduled task $task"
        } else { Info "task $task not present" }
    }
    Write-Host ""
    Write-Host "Done. Laragon is untouched." -ForegroundColor Green
    exit 0
}

# ── 1. MySQL service ────────────────────────────────────────────────────────
Write-Host "1/4  MySQL service"
if (Get-Service QRIVOMySQL -ErrorAction SilentlyContinue) {
    Info "QRIVOMySQL already installed"
} else {
    # An explicit defaults file: without it mysqld uses its compiled-in datadir,
    # which is NOT where the QRIVO database lives.
    @"
[mysqld]
datadir=$($MYSQLDATA -replace '\\','/')
port=3306
bind-address=127.0.0.1
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
default-storage-engine=InnoDB
transaction-isolation=READ-COMMITTED
"@ | Set-Content $MYINI -Encoding ascii

    # Stop any GUI-launched mysqld first; two servers cannot share a data dir.
    Get-Process mysqld -ErrorAction SilentlyContinue | Stop-Process -Force
    Start-Sleep -Seconds 3

    & $MYSQLD --install QRIVOMySQL --defaults-file="$MYINI"
    if ($LASTEXITCODE -eq 0) { Ok "installed QRIVOMySQL" } else { Bad "mysqld --install failed ($LASTEXITCODE)" }
    & sc.exe config QRIVOMySQL start= auto | Out-Null
    & sc.exe failure QRIVOMySQL reset= 86400 actions= restart/5000/restart/10000/restart/30000 | Out-Null
    Ok "auto-start + crash recovery configured"
}

# ── 2. Apache service ───────────────────────────────────────────────────────
Write-Host ""
Write-Host "2/4  Apache service (:8000 API + /panel, :8080 panel)"
if (Get-Service QRIVOApache -ErrorAction SilentlyContinue) {
    Info "QRIVOApache already installed"
} else {
    New-Item -ItemType Directory -Force -Path "$REPO\deploy\windows\logs","$REPO\deploy\windows\run" | Out-Null
    Get-CimInstance Win32_Process -Filter "Name='httpd.exe'" |
        Where-Object { $_.CommandLine -match 'qrivo-apache.conf' } |
        ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
    Start-Sleep -Seconds 2

    & $HTTPD -k install -n "QRIVOApache" -f $APACHECONF
    if ($LASTEXITCODE -eq 0) { Ok "installed QRIVOApache" } else { Bad "httpd -k install failed ($LASTEXITCODE)" }
    & sc.exe config QRIVOApache start= auto | Out-Null
    & sc.exe failure QRIVOApache reset= 86400 actions= restart/5000/restart/10000/restart/30000 | Out-Null
    Ok "auto-start + crash recovery configured"
}

# ── 3. Tunnel + publish, at logon ───────────────────────────────────────────
Write-Host ""
Write-Host "3/4  Tunnel + address publication (scheduled task, at logon)"
if (-not (Test-Path $PUBLISHER)) {
    Warn "publisher not found at $PUBLISHER - skipping"
} else {
    $action = New-ScheduledTaskAction -Execute 'powershell.exe' `
        -Argument "-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File `"$PUBLISHER`" -Quiet"
    # A short delay: the publisher checks the API answers before publishing an
    # address, and Apache needs a moment after boot to be ready.
    $trigger = New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME
    $trigger.Delay = 'PT30S'
    # If it fails (no network yet, Apache still starting), try again. This is
    # also what re-publishes a NEW address after a wake-from-sleep drops the
    # tunnel and cloudflared reconnects with a different hostname.
    $settings = New-ScheduledTaskSettingsSet `
        -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable `
        -RestartCount 10 -RestartInterval (New-TimeSpan -Minutes 1) `
        -ExecutionTimeLimit (New-TimeSpan -Minutes 10)
    Register-ScheduledTask -TaskName 'QRIVO-Tunnel' -Action $action -Trigger $trigger `
        -Settings $settings -RunLevel Limited -Force | Out-Null
    Ok "registered QRIVO-Tunnel (30s after logon, retries up to 10 times)"
}

# ── 4. Network adapter power management ─────────────────────────────────────
Write-Host ""
Write-Host "4/4  Stop Windows powering down the network adapter"
$changed = 0
foreach ($ad in (Get-NetAdapter -Physical -ErrorAction SilentlyContinue | Where-Object Status -eq 'Up')) {
    try {
        $pm = Get-NetAdapterPowerManagement -Name $ad.Name -ErrorAction Stop
        if ($pm.AllowComputerToTurnOffDevice -ne 'Disabled') {
            $pm.AllowComputerToTurnOffDevice = 'Disabled'
            Set-NetAdapterPowerManagement -InputObject $pm
            Ok "$($ad.Name): device power-down disabled"
            $changed++
        } else { Info "$($ad.Name): already disabled" }
    } catch { Info "$($ad.Name): no power-management settings exposed" }
}
if ($changed -eq 0) { Info "nothing needed changing" }

# ── Start ───────────────────────────────────────────────────────────────────
Write-Host ""
Write-Host "Starting services..." -ForegroundColor Cyan
foreach ($svc in @('QRIVOMySQL','QRIVOApache')) {
    if (Get-Service $svc -ErrorAction SilentlyContinue) {
        try { Start-Service $svc; Ok "$svc started" } catch { Bad "$svc failed to start: $($_.Exception.Message)" }
    }
}
Start-Sleep -Seconds 6
foreach ($u in @('http://127.0.0.1:8000/api/v1/health','http://127.0.0.1:8080')) {
    try { $r = Invoke-WebRequest $u -UseBasicParsing -TimeoutSec 10; Ok "$u -> HTTP $($r.StatusCode)" }
    catch { Bad "$u did not answer" }
}

Write-Host ""
Write-Host "Done." -ForegroundColor Green
Write-Host "  Reboot to confirm everything comes back on its own." -ForegroundColor Gray
Write-Host "  Undo everything with:  -Uninstall" -ForegroundColor DarkGray
Write-Host ""
