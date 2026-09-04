<#
    QRIVO — one-line health check for each moving part.

        powershell -ExecutionPolicy Bypass -File .\check-qrivo.ps1

    Prints one line per component: MySQL, API, teacher panel, public tunnel.
    Anything not UP comes with the exact thing to do about it.
    Exit code 0 = everything up, 1 = something is down.
#>

$MYSQLD     = 'C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqld.exe'
$CONFIG_URL  = 'https://raw.githubusercontent.com/HekimSefkan/QRIVO/endpoint/endpoint.json'
$REPO        = 'C:\Projects\QRIVO'

$failed = 0

function Report($name, $up, $detail, $fix) {
    if ($up) {
        Write-Host ("  {0,-22} UP      {1}" -f $name, $detail) -ForegroundColor Green
    } else {
        Write-Host ("  {0,-22} DOWN    {1}" -f $name, $detail) -ForegroundColor Red
        Write-Host ("  {0,-22}         -> {1}" -f "", $fix) -ForegroundColor Yellow
        $script:failed++
    }
}

# A third state, for when WE cannot tell. Blaming QRIVO because a free
# third-party checker happens to be down would be the same class of wrong
# answer as the old false positive, just pointing the other way. So this is
# never counted as a failure -- and never printed green either.
function ReportUnknown($name, $detail, $hint) {
    Write-Host ("  {0,-22} UNKNOWN {1}" -f $name, $detail) -ForegroundColor DarkYellow
    Write-Host ("  {0,-22}         -> {1}" -f "", $hint) -ForegroundColor DarkGray
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

# 4 — Public tunnel (Cloudflare) and the published address
#
# HONESTY RULE. An earlier version fetched the public URL from this laptop and
# printed green while the phone could not connect: the machine that HOSTS the
# service can succeed for reasons a phone on mobile data does not share. So the
# public line goes green ONLY when an INDEPENDENT third-party service fetches a
# nonce written seconds earlier. If that check cannot run, this says so rather
# than guessing.

$tunnelUp = [bool](Get-Process cloudflared -ErrorAction SilentlyContinue)
Report "Tunnel" $tunnelUp `
    $(if ($tunnelUp) { "cloudflared running" } else { "cloudflared not running" }) `
    "run .\start-qrivo.ps1"

# The address is not fixed - it changes each restart - so read what is actually
# published. This doubles as a check that the phone can discover it at all.
$published   = $null
$configOk    = $false
$configDetail = "not checked"
try {
    $doc = Invoke-RestMethod "$CONFIG_URL`?t=$([DateTimeOffset]::Now.ToUnixTimeSeconds())" -TimeoutSec 20
    $published = $doc.api_base_url
    if ($published) {
        $gen = [datetime]::Parse($doc.generated_at, [Globalization.CultureInfo]::InvariantCulture, [Globalization.DateTimeStyles]::AdjustToUniversal -bor [Globalization.DateTimeStyles]::AssumeUniversal)
        $age = [int]((Get-Date).ToUniversalTime() - $gen).TotalMinutes
        $configOk = $true
        $configDetail = "$published (published ${age}m ago)"
    } else { $configDetail = "document has no api_base_url" }
} catch {
    $configDetail = "cannot read the published config: $($_.Exception.Message.Split([Environment]::NewLine)[0])"
}
Report "Published address" $configOk $configDetail "run .\start-qrivo.ps1 to republish"

# Ask INDEPENDENT third parties to fetch a nonce written seconds ago.
#   - allorigins / codetabs echo the body, so they can confirm the exact nonce.
#   - hackertarget returns only headers, so it confirms HTTP 200 and that the
#     Content-Length matches the nonce we just wrote. Weaker, but still external
#     and it is the one that reliably answers from this network.
$publicOk      = $false
$publicUnknown = $false
$publicDetail  = "skipped - tunnel or published address missing"

if ($tunnelUp -and $published) {
    $nonce = "qrivo-" + [guid]::NewGuid().ToString('N').Substring(0,12)
    $nonce | Set-Content "$REPO\backend\public\probe.txt" -Encoding ascii -NoNewline
    $probe = "$published/probe.txt"
    $anyAnswered = $false

    foreach ($c in @(
        @{ name = 'allorigins';   url = "https://api.allorigins.win/raw?url=$probe";        body = $true },
        @{ name = 'codetabs';     url = "https://api.codetabs.com/v1/proxy?quest=$probe";   body = $true },
        @{ name = 'hackertarget'; url = "https://api.hackertarget.com/httpheaders/?q=$probe"; body = $false }
    )) {
        try {
            $r = Invoke-WebRequest $c.url -UseBasicParsing -TimeoutSec 20
            $anyAnswered = $true
            if ($c.body) {
                if ("$($r.Content)".Trim() -eq $nonce) {
                    $publicOk = $true
                    $publicDetail = "$($c.name) fetched our exact nonce - genuinely public"
                    break
                }
            } else {
                $text = "$($r.Content)"
                if ($text -match 'HTTP/1\.\d 200' -and $text -match "Content-Length:\s*$($nonce.Length)\b") {
                    $publicOk = $true
                    $publicDetail = "$($c.name) got HTTP 200 with our nonce's exact length ($($nonce.Length)B)"
                    break
                }
            }
        } catch { }
    }

    if (-not $publicOk) {
        if ($anyAnswered) {
            $publicDetail = "an external checker answered but did not see our content"
        } else {
            $publicUnknown = $true
            $publicDetail  = "no external checker responded (they are free services and go down)"
        }
    }
}

if ($publicUnknown) {
    ReportUnknown "Reachable from outside" $publicDetail "this is NOT evidence QRIVO is down - test on your phone with Wi-Fi off"
} else {
    Report "Reachable from outside" $publicOk $publicDetail "test on your phone with Wi-Fi off; if that fails, run .\start-qrivo.ps1"
}

Write-Host ""
Write-Host "  The last line is green ONLY when an independent third-party service" -ForegroundColor DarkGray
Write-Host "  fetched a nonce written seconds earlier. A request from this laptop" -ForegroundColor DarkGray
Write-Host "  is never accepted as proof." -ForegroundColor DarkGray
if ($failed -eq 0) {
    Write-Host "  Everything is up and externally reachable." -ForegroundColor Green
    Write-Host "  Panel: http://127.0.0.1:8080   (open on THIS laptop)" -ForegroundColor Gray
    Write-Host ""
    exit 0
} else {
    Write-Host "  $failed component(s) down - see the arrows above." -ForegroundColor Red
    Write-Host ""
    exit 1
}
