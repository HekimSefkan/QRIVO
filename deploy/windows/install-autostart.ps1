<#
    QRIVO — install autostart. RUN ONCE, AS ADMINISTRATOR.

        Right-click PowerShell -> "Run as administrator", then:
        powershell -ExecutionPolicy Bypass -File C:\Projects\QRIVO\deploy\windows\install-autostart.ps1

    WHY THIS EXISTS
    Nothing in the QRIVO stack was ever configured to start. Verified on
    2026-09-04: `Get-CimInstance Win32_Service` matched nothing for
    mysql/apache/httpd/ngrok/laragon, there were no scheduled tasks, and every
    shutdown in the System event log was a clean user-initiated power-off
    (Event 1074, "on behalf of user"). Nothing was crashing -- MySQL and Apache
    were running only because Laragon's GUI had launched them as ordinary
    child processes, so a normal shutdown ended them and a reboot brought
    nothing back.

    WHAT THIS INSTALLS
      QRIVOMySQL    Windows service   MySQL 8.4 on the QRIVO data directory
      QRIVOApache   Windows service   Apache + mod_php serving :8000 and :8080
      QRIVO-Ngrok   Scheduled task    the public tunnel, at logon

    WHY SERVICES FOR TWO AND A TASK FOR THE THIRD
    MySQL and Apache both ship native service installers, which gives real
    crash recovery: this script configures them to restart automatically after
    a failure. ngrok has no service mode on the free plan, and its authtoken
    lives in the PER-USER config (%LOCALAPPDATA%\ngrok\ngrok.yml), so running it
    as SYSTEM would not find the token. It therefore runs as a logon task, as
    you, with the config path passed explicitly.

    CONSEQUENCE, STATED PLAINLY: MySQL and Apache come up at BOOT, before login.
    The tunnel comes up when you LOG IN. On a laptop you log into anyway that is
    the same thing in practice, but it is not the same thing in principle, and
    you should know which is which.

    Laragon's own Apache on :443 is NOT touched.
#>

param(
    # Opt in DELIBERATELY to a Windows Defender exclusion for ngrok.
    # Read the warning printed by the script before using this.
    [switch]$AllowNgrok,
    [switch]$Uninstall
)

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
$NGROK      = 'C:\Tools\ngrok\ngrok.exe'
$NGROKCONF  = "$env:LOCALAPPDATA\ngrok\ngrok.yml"
$DOMAIN     = 'fanatic-blitz-eastbound.ngrok-free.dev'
$MYINI      = "$REPO\deploy\windows\my.ini"

function Ok($m)   { Write-Host "  [OK]   $m" -ForegroundColor Green }
function Info($m) { Write-Host "  [--]   $m" -ForegroundColor DarkGray }
function Warn($m) { Write-Host "  [WARN] $m" -ForegroundColor Yellow }
function Bad($m)  { Write-Host "  [FAIL] $m" -ForegroundColor Red }

Write-Host ""
Write-Host "QRIVO autostart installer" -ForegroundColor Cyan
Write-Host "=========================" -ForegroundColor Cyan
Write-Host ""

# ── Uninstall path ──────────────────────────────────────────────────────────
if ($Uninstall) {
    Write-Host "Removing QRIVO autostart..." -ForegroundColor Yellow
    foreach ($svc in @('QRIVOApache','QRIVOMySQL')) {
        if (Get-Service $svc -ErrorAction SilentlyContinue) {
            Stop-Service $svc -Force -ErrorAction SilentlyContinue
            & sc.exe delete $svc | Out-Null
            Ok "removed service $svc"
        } else { Info "service $svc not present" }
    }
    if (Get-ScheduledTask -TaskName 'QRIVO-Ngrok' -ErrorAction SilentlyContinue) {
        Unregister-ScheduledTask -TaskName 'QRIVO-Ngrok' -Confirm:$false
        Ok "removed scheduled task QRIVO-Ngrok"
    } else { Info "task QRIVO-Ngrok not present" }
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
    # Stop the hand-started instance so the service owns the ports.
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

# ── 3. ngrok logon task ─────────────────────────────────────────────────────
Write-Host ""
Write-Host "3/4  ngrok tunnel (scheduled task, at logon)"
if (-not (Test-Path $NGROK)) {
    Warn "ngrok not found at $NGROK - skipping the task"
} elseif (-not (Test-Path $NGROKCONF)) {
    Warn "no ngrok config at $NGROKCONF - run 'ngrok config add-authtoken ...' first"
} else {
    $action = New-ScheduledTaskAction -Execute $NGROK `
        -Argument "http --domain=$DOMAIN --config=`"$NGROKCONF`" --log=`"$REPO\deploy\windows\logs\ngrok.log`" --log-format=json 8000"
    $trigger = New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME
    # RestartCount/RestartInterval is what recovers the tunnel after the network
    # drops or the machine wakes from sleep with a new route.
    $settings = New-ScheduledTaskSettingsSet `
        -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable `
        -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) `
        -ExecutionTimeLimit (New-TimeSpan -Seconds 0)
    Register-ScheduledTask -TaskName 'QRIVO-Ngrok' -Action $action -Trigger $trigger `
        -Settings $settings -RunLevel Limited -Force | Out-Null
    Ok "registered QRIVO-Ngrok (restarts itself every minute if it stops)"
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

# ── Optional: Defender exclusion for ngrok ──────────────────────────────────
Write-Host ""
Write-Host "Windows Defender and ngrok" -ForegroundColor Cyan
if ($AllowNgrok) {
    Add-MpPreference -ExclusionPath $NGROK
    Ok "added a Defender exclusion for $NGROK"
    Warn "this exempts that one file from scanning. Remove it with:"
    Write-Host "         Remove-MpPreference -ExclusionPath '$NGROK'" -ForegroundColor DarkGray
} else {
    Warn "NOT added. Defender currently blocks ngrok as Trojan:Win32/Kepavll!rfn."
    Write-Host "         The same detection fires on the binary downloaded directly from" -ForegroundColor DarkGray
    Write-Host "         ngrok's official CDN, and the !rfn suffix marks it as a machine-" -ForegroundColor DarkGray
    Write-Host "         learning heuristic rather than a signature match - the usual" -ForegroundColor DarkGray
    Write-Host "         shape of a false positive on tunnelling tools. That is evidence," -ForegroundColor DarkGray
    Write-Host "         not proof. If you accept the risk, re-run this script with:" -ForegroundColor DarkGray
    Write-Host "             -AllowNgrok" -ForegroundColor Yellow
}

# ── Start everything ────────────────────────────────────────────────────────
Write-Host ""
Write-Host "Starting services..." -ForegroundColor Cyan
foreach ($svc in @('QRIVOMySQL','QRIVOApache')) {
    if (Get-Service $svc -ErrorAction SilentlyContinue) {
        try { Start-Service $svc; Ok "$svc started" } catch { Bad "$svc failed to start: $($_.Exception.Message)" }
    }
}
Start-Sleep -Seconds 5
foreach ($u in @('http://127.0.0.1:8000/api/v1/health','http://127.0.0.1:8080')) {
    try {
        $r = Invoke-WebRequest $u -UseBasicParsing -TimeoutSec 10
        Ok "$u -> HTTP $($r.StatusCode)"
    } catch { Bad "$u did not answer" }
}

Write-Host ""
Write-Host "Done. Reboot to confirm everything comes back on its own." -ForegroundColor Green
Write-Host "Undo everything with:  -Uninstall" -ForegroundColor DarkGray
Write-Host ""
