#requires -Version 5.1
<#
 Capacity sweep for the PgBouncer live run: the SAME two-tenant HTTP load against
   - a DIRECT connection (no pooler), and
   - the pooler at several pool sizes (transaction mode).
 It restarts only what must change (the pooler, and the app containers that hold connections), runs
 tests/smoke/pooler_interleaved_load.py for each configuration, and prints one comparison table.

   powershell -File ./pooler_sweep.ps1 -Label current -Pools "2,10,20" [-Seconds 40] [-Workers 16]   (Pools is ONE comma-separated string: -File would mangle a list)

 Leaves the stack running THROUGH the pooler at the last pool size; run `docker compose up -d` (no pooler file) to go back to direct.
#>
param(
    [string]$Label = 'run',
    [string]$Pools = '2,10,20',
    [int]$Seconds = 40,
    [int]$Workers = 16,
    [switch]$SkipDirect
)
$ErrorActionPreference = 'Continue'
Set-Location $PSScriptRoot
$direct = @('-f', 'docker-compose.yml', '-f', 'docker-compose.dev.yml')
$pooled = $direct + @('-f', 'docker-compose.pgbouncer.yml')
$out = Join-Path $env:TEMP "sweep_$Label"
New-Item -ItemType Directory -Force $out | Out-Null

function Wait-Healthy([string[]]$names, [int]$timeout = 120) {
    $end = (Get-Date).AddSeconds($timeout)
    while ((Get-Date) -lt $end) {
        $bad = $names | Where-Object { (docker inspect -f '{{.State.Health.Status}}' $_ 2>$null) -ne 'healthy' }
        if (-not $bad) { return }
        Start-Sleep 2
    }
    Write-Warning "not healthy in time: $($names -join ', ')"
}

function Run-Load([string]$name) {
    Write-Host "--- load: $name"
    $json = Join-Path $out "$name.json"
    $log = Join-Path $out "$name.log"
    Remove-Item $json -ErrorAction SilentlyContinue
    python -I ..\tests\smoke\pooler_interleaved_load.py --seconds $Seconds --workers $Workers --label $name --out $json 2>&1 | Out-File $log -Encoding utf8
    Get-Content $log | Select-String -CaseSensitive '^(FAIL|INFO  [0-9]+ requests|INFO  pooler:)|passed$'
    if (-not (Test-Path $json)) {
        Write-Warning "no result file for $name; last lines of its output:"
        Get-Content $log -Tail 15
    }
}

if (-not $SkipDirect) {
    Write-Host '=== DIRECT (apps -> postgres)'
    docker stop luminate-wellms-pgbouncer-1 2>$null | Out-Null
    docker compose @direct up -d --no-deps --force-recreate api worker scheduler 2>&1 | Out-Null
    Wait-Healthy @('luminate-wellms-api-1', 'luminate-wellms-worker-1', 'luminate-wellms-scheduler-1')
    Run-Load "$Label-direct"
}
foreach ($p in ($Pools -split ',' | ForEach-Object { [int]$_.Trim() })) {
    Write-Host "=== POOLED, pool size $p"
    $env:PGB_POOL_SIZE = "$p"
    docker compose @pooled up -d --force-recreate pgbouncer 2>&1 | Out-Null
    Wait-Healthy @('luminate-wellms-pgbouncer-1')
    docker compose @pooled up -d --no-deps --force-recreate api worker scheduler 2>&1 | Out-Null
    Wait-Healthy @('luminate-wellms-api-1', 'luminate-wellms-worker-1', 'luminate-wellms-scheduler-1')
    Run-Load "$Label-pool$p"
}
Remove-Item Env:PGB_POOL_SIZE -ErrorAction SilentlyContinue

Write-Host ''
Write-Host "=== comparison ($Label): $Workers concurrent workers, $Seconds s, two tenants interleaved"
$rows = Get-ChildItem $out -Filter '*.json' | Sort-Object Name | ForEach-Object { Get-Content $_.FullName -Raw | ConvertFrom-Json }
'{0,-22} {1,9} {2,8} {3,16} {4,16} {5,16} {6,6}' -f 'configuration', 'requests', 'req/s', 'list users p50/p95', 'profile p50/p95', 'create p50/p95', 'leaks' | Write-Host
foreach ($r in $rows) {
    $f = { param($o) if ($o) { '{0:N0}/{1:N0} ms' -f $o.p50_ms, $o.p95_ms } else { 'n/a' } }
    '{0,-22} {1,9} {2,8} {3,16} {4,16} {5,16} {6,6}' -f $r.label, $r.requests, $r.rps, (& $f $r.ops.'list users'), (& $f $r.ops.'student profile'), (& $f $r.ops.'create user'), $r.violations | Write-Host
}
