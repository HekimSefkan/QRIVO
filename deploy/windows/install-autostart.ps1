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

    WHAT THIS DELIBERATELY DOES NOT INSTALL
      The public tunnel. It used to be registered here as a QRIVO-Tunnel logon
      task that re-ran every five minutes -- and every run popped a console
      window over whatever was on screen, which is intolerable during a
      presentation. The demo path is the laptop hotspot and needs no tunnel at
      all, so this installer no longer creates that task, and removes it if an
      earlier version did.

      Remote access is now strictly opt-in: run install-tunnel-task.ps1 by hand
      (no administrator rights needed) and remove it with
      `schtasks /delete /tn "QRIVO-Tunnel" /f`.

    WHY SERVICES
    MySQL and Apache ship native service installers, which gives real crash
    recovery: this configures them to restart automatically after a failure.
    CONSEQUENCE, STATED PLAINLY: MySQL and Apache come up at BOOT, before login,
    and restart themselves after a crash. Nothing else is scheduled to run.

    IT ALSO TURNS OFF WINDOWS FAST STARTUP. With Fast Startup on, "Shut down"
    then power on is a hibernate/resume of session 0, not a boot -- so automatic
    services never auto-start and simply come back in whatever state they were
    hibernated in. That, and not any fault in the service, is why MySQL could
    sit Stopped after what looked like a reboot while Apache appeared fine (it
    was merely still running from before). See step 3.

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
Write-Host "1/5  MySQL service"
if (Get-Service QRIVOMySQL -ErrorAction SilentlyContinue) {
    Info "QRIVOMySQL already installed"
} else {
    # An explicit defaults file: without it mysqld uses its compiled-in datadir,
    # which is NOT where the QRIVO database lives.
    @"
[mysqld]
datadir=$($MYSQLDATA -replace '\','/')
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
}

# Applied EVERY run, not only at creation. The service blocks above skip
# everything when the service already exists, so a fix shipped later would
# never reach a machine that installed an earlier version. Configuration is
# idempotent, so re-applying it costs nothing and is the only way re-running
# this installer can actually repair an existing install.
& sc.exe config QRIVOMySQL start= auto | Out-Null
& sc.exe failure QRIVOMySQL reset= 86400 actions= restart/5000/restart/10000/restart/30000 | Out-Null
Ok "auto-start + crash recovery configured"

# ── 2. Apache service ───────────────────────────────────────────────────────
Write-Host ""
Write-Host "2/5  Apache service (:8000 API + /panel, :8080 panel)"
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
}

& sc.exe config QRIVOApache start= auto | Out-Null
& sc.exe failure QRIVOApache reset= 86400 actions= restart/5000/restart/10000/restart/30000 | Out-Null

# Apache must not answer before the database it needs exists. Without this the
# API serves HTTP 503 for as long as MySQL takes to come up -- correct
# behaviour, but it looks like a broken deployment.
#
# NOTE: `depend=` REPLACES the whole list, it does not append. Apache's own
# installer sets Tcpip and Afd; dropping them to add ours would be a
# regression, so all three are listed. The separator is a forward slash.
& sc.exe config QRIVOApache depend= Tcpip/Afd/QRIVOMySQL | Out-Null
if ($LASTEXITCODE -eq 0) { Ok "auto-start + crash recovery + waits for QRIVOMySQL" }
else { Warn "could not set the QRIVOMySQL dependency ($LASTEXITCODE)" }
# ── 3. Windows Fast Startup ─────────────────────────────────────────────────
#
# THIS IS WHY "IT DOES NOT START AT BOOT" HAPPENS, and it is not a fault in the
# service. Verified on 2026-09-09:
#
#   * QRIVOMySQL was Stopped after what looked like a reboot, and the API served
#     503 because it had no database.
#   * The MySQL error log recorded NO failed start -- no attempt at all. There
#     were no 7000/7023/7024 service-control events either. The service did not
#     fail; it was never asked to start.
#   * LastBootUpTime had not moved, and Apache's httpd processes still held the
#     PIDs they were given two days earlier.
#
# With Fast Startup on (HiberbootEnabled=1), "Shut down" then power on is NOT a
# boot. Windows hibernates session 0 -- services included -- and resumes it.
# Automatic services only auto-start on a real boot, so on resume every service
# comes back in the state it was hibernated in. Apache looked like it "started
# by itself" purely because it had been running; MySQL had not, so it stayed
# stopped forever.
#
# Turning this off makes shutdown mean shutdown. It costs a few seconds of boot
# time. "Restart" always did a real boot and was never affected.
Write-Host ""
Write-Host "3/5  Windows Fast Startup (makes 'shut down' a real shutdown)"
$powerKey = 'HKLM:\SYSTEM\CurrentControlSet\Control\Session Manager\Power'
try {
    $hiber = (Get-ItemProperty $powerKey -Name HiberbootEnabled -ErrorAction Stop).HiberbootEnabled
} catch { $hiber = $null }

if ($hiber -eq 0) {
    Info "already disabled"
} else {
    try {
        Set-ItemProperty $powerKey -Name HiberbootEnabled -Value 0 -Type DWord -ErrorAction Stop
        Ok "Fast Startup disabled - services now really start at every power-on"
    } catch {
        Bad "could not disable Fast Startup: $($_.Exception.Message)"
        Bad "set it by hand:  reg add `"HKLM\SYSTEM\CurrentControlSet\Control\Session Manager\Power`" /v HiberbootEnabled /t REG_DWORD /d 0 /f"
    }
}
# ── 4. Tunnel: deliberately NOT registered ──────────────────────────────────
#
# This used to register QRIVO-Tunnel as a logon task repeating every five
# minutes. Each repetition launched a PowerShell host, and each one flashed a
# console window on top of whatever was on screen. During a demonstration that
# is worse than having no tunnel at all -- and the demo path is the laptop
# hotspot, which needs no tunnel whatsoever.
#
# So the installer now REMOVES that task rather than creating it. Remote access
# is opt-in: run deploy\windows\install-tunnel-task.ps1 yourself when you
# actually want it. That script needs no administrator rights, which is exactly
# why it is a separate script and not a branch of this one.
Write-Host ""
Write-Host "4/5  Tunnel (not installed - opt-in only)"
# Cmdlets, NOT `schtasks ... 2>&1`. Under $ErrorActionPreference='Stop',
# redirecting a native command's stderr wraps each line in an ErrorRecord and
# raises a TERMINATING NativeCommandError. schtasks writes "ERROR: The system
# cannot find the file specified." to stderr for a task that does not exist --
# the normal case here -- so the redirected form aborted this whole script at
# this line, and the firewall and adapter steps below never ran. Verified
# 2026-09-09: install-autostart.ps1 completed steps 1-3 and silently stopped.
foreach ($task in @('QRIVO-Tunnel','QRIVO-Ngrok')) {
    if (Get-ScheduledTask -TaskName $task -ErrorAction SilentlyContinue) {
        try {
            Unregister-ScheduledTask -TaskName $task -Confirm:$false -ErrorAction Stop
            Ok "removed leftover task $task"
        } catch {
            Warn "could not remove $task - delete it with: schtasks /delete /tn `"$task`" /f"
        }
    } else {
        Info "$task not present"
    }
}
Info "remote access is opt-in: deploy\windows\install-tunnel-task.ps1"
# ── 4b. Firewall: let the phone reach the API over the hotspot ──────────────
#
# Apache already listens on 0.0.0.0, so the hotspot interface is covered. What
# blocks the phone is the Windows firewall: there was no inbound rule for
# 8000/8080 at all.
#
# Scoped by LOCAL ADDRESS, not by firewall profile. The first attempt used
# -Profile Private, but the Mobile Hotspot adapter (a Wi-Fi Direct virtual
# adapter) gets NO network profile at all, even once a phone has joined it --
# verified on 2026-09-08 with a phone connected at 192.168.137.166. A
# profile-scoped rule may therefore never apply.
#
# -LocalAddress 192.168.137.1 is both more reliable and TIGHTER: it allows
# inbound connections only to the hotspot address, so the ports stay closed on
# the Wi-Fi interface, on Tailscale, and on any public network the laptop joins
# later. The profile no longer matters.
Write-Host ""
Write-Host "4b/5 Firewall rule for the hotspot"
foreach ($port in 8000, 8080) {
    foreach ($old in @("QRIVO API $port (private)", "QRIVO API $port (hotspot)")) {
        Get-NetFirewallRule -DisplayName $old -ErrorAction SilentlyContinue | Remove-NetFirewallRule -ErrorAction SilentlyContinue
    }
    New-NetFirewallRule -DisplayName "QRIVO API $port (hotspot)" -Direction Inbound -Protocol TCP `
        -LocalPort $port -LocalAddress '192.168.137.1' -Action Allow -Profile Any `
        -Description 'QRIVO: allow a phone on the laptop hotspot to reach the API. Bound to the hotspot address only, so the port stays closed on every other interface.' | Out-Null
    Ok "inbound TCP $port allowed, but ONLY on 192.168.137.1"
}

# ── 5. Network adapter power management ─────────────────────────────────────
Write-Host ""
Write-Host "5/5  Stop Windows powering down the network adapter"
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
