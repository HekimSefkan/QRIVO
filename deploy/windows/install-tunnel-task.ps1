<#
    QRIVO — register the tunnel/publish scheduled task.

        powershell -ExecutionPolicy Bypass -File C:\Projects\QRIVO\deploy\windows\install-tunnel-task.ps1

    NO ADMINISTRATOR RIGHTS NEEDED. The task runs as you, at your logon, with
    LeastPrivilege — so `schtasks /create` accepts it from an ordinary shell.
    (Register-ScheduledTask was refused unelevated on this machine; schtasks
    with an explicit InteractiveToken/LeastPrivilege principal is not.)

    WHY XML RATHER THAN New-ScheduledTaskTrigger
    PowerShell 5.1 cannot express "repeat forever". Setting
    -RepetitionDuration ([TimeSpan]::MaxValue) serialises to
    P99999999DT23H59M59S, which the Task Scheduler rejects outright:

        Register-ScheduledTask : The task XML contains a value which is
        incorrectly formatted or out of range. (8,42):Duration:P99999999DT23H59M59S
        HRESULT 0x80041318

    In the task XML an OMITTED <Duration> means exactly "repeat indefinitely",
    which is what we want and what the cmdlet cannot produce. So the XML is
    written directly.

    WHY IT REPEATS AT ALL
    The first reboot test showed the logon run being TERMINATED
    (LastTaskResult 0xC000013A) after starting cloudflared but before
    publishing, leaving the tunnel live on a new address while the published
    document named an old one. Restart-on-failure does not cover a termination.
    Repeating every 5 minutes makes this a reconciler: whatever stops one run,
    the next puts it right. It is also what republishes the address after a
    wake-from-sleep, when cloudflared reconnects with a different hostname.
    The publisher exits early when the published address already matches, so
    the steady-state cost is one HTTPS GET every five minutes.
#>

param([switch]$Uninstall)

$ErrorActionPreference = 'Continue'

$TASK      = 'QRIVO-Tunnel'
$PUBLISHER = 'C:\Projects\QRIVO\deploy\windows\publish-endpoint.ps1'
$user      = "$env:USERDOMAIN\$env:USERNAME"

function Ok($m)   { Write-Host "  [OK]   $m" -ForegroundColor Green }
function Bad($m)  { Write-Host "  [FAIL] $m" -ForegroundColor Red }
function Info($m) { Write-Host "  [--]   $m" -ForegroundColor DarkGray }

Write-Host ""
Write-Host "QRIVO tunnel task" -ForegroundColor Cyan
Write-Host "=================" -ForegroundColor Cyan
Write-Host ""

if ($Uninstall) {
    schtasks /delete /tn $TASK /f 2>&1 | Out-Null
    if ($LASTEXITCODE -eq 0) { Ok "removed $TASK" } else { Info "$TASK was not present" }
    Write-Host ""
    exit 0
}

if (-not (Test-Path $PUBLISHER)) { Bad "publisher not found at $PUBLISHER"; exit 1 }

# Note the ABSENT <Duration> inside <Repetition> — that is the "forever" the
# PowerShell cmdlet cannot express.
$xml = @"
<?xml version="1.0" encoding="UTF-16"?>
<Task version="1.2" xmlns="http://schemas.microsoft.com/windows/2004/02/mit/task">
  <RegistrationInfo>
    <Author>$user</Author>
    <Description>QRIVO - start the Cloudflare tunnel and publish its public address. Repeats every 5 minutes as a reconciler.</Description>
  </RegistrationInfo>
  <Triggers>
    <LogonTrigger>
      <Enabled>true</Enabled>
      <UserId>$user</UserId>
      <Delay>PT30S</Delay>
      <Repetition>
        <Interval>PT5M</Interval>
        <StopAtDurationEnd>false</StopAtDurationEnd>
      </Repetition>
    </LogonTrigger>
  </Triggers>
  <Principals>
    <Principal id="Author">
      <UserId>$user</UserId>
      <LogonType>InteractiveToken</LogonType>
      <RunLevel>LeastPrivilege</RunLevel>
    </Principal>
  </Principals>
  <Settings>
    <MultipleInstancesPolicy>IgnoreNew</MultipleInstancesPolicy>
    <DisallowStartIfOnBatteries>false</DisallowStartIfOnBatteries>
    <StopIfGoingOnBatteries>false</StopIfGoingOnBatteries>
    <AllowHardTerminate>true</AllowHardTerminate>
    <StartWhenAvailable>true</StartWhenAvailable>
    <RunOnlyIfNetworkAvailable>false</RunOnlyIfNetworkAvailable>
    <IdleSettings>
      <StopOnIdleEnd>false</StopOnIdleEnd>
      <RestartOnIdle>false</RestartOnIdle>
    </IdleSettings>
    <AllowStartOnDemand>true</AllowStartOnDemand>
    <Enabled>true</Enabled>
    <Hidden>false</Hidden>
    <RunOnlyIfIdle>false</RunOnlyIfIdle>
    <WakeToRun>false</WakeToRun>
    <ExecutionTimeLimit>PT10M</ExecutionTimeLimit>
    <Priority>7</Priority>
  </Settings>
  <Actions Context="Author">
    <Exec>
      <Command>powershell.exe</Command>
      <Arguments>-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "$PUBLISHER" -Quiet</Arguments>
    </Exec>
  </Actions>
</Task>
"@

# Task XML must be UTF-16 with a BOM, matching the declaration above.
$xmlPath = Join-Path $env:TEMP 'qrivo-tunnel-task.xml'
[System.IO.File]::WriteAllText($xmlPath, $xml, [System.Text.Encoding]::Unicode)

$out = schtasks /create /tn $TASK /xml $xmlPath /f 2>&1 | Out-String
if ($LASTEXITCODE -ne 0) {
    Bad "schtasks refused the task:"
    Write-Host $out.Trim() -ForegroundColor Red
    exit 1
}
Ok "registered $TASK"
Remove-Item $xmlPath -ErrorAction SilentlyContinue

# Prove the repetition really landed, rather than assuming it did.
$check = schtasks /query /tn $TASK /xml ONE 2>&1 | Out-String
if ($check -match '<Interval>PT5M</Interval>') { Ok "repeating trigger confirmed: every 5 minutes" }
else { Bad "the task registered but WITHOUT the repeating trigger" }
if ($check -match '<Duration>')  { Bad "unexpected <Duration> present - repetition would stop" }
else { Ok "no <Duration> element - repeats indefinitely" }

Write-Host ""
Write-Host "  Run it now with:  schtasks /run /tn $TASK" -ForegroundColor Gray
Write-Host "  Remove it with:   .\install-tunnel-task.ps1 -Uninstall" -ForegroundColor DarkGray
Write-Host ""
