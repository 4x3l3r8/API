#requires -Version 5.1
<#
 Generates luminate/infra/.env from .env.example with fresh random secrets and a
 Passport RSA keypair. Refuses to overwrite an existing .env unless -Force.
 Needs Docker (openssl runs in a throwaway container). -LanHost overrides autodetection.
#>
param(
    [string]$LanHost,
    [switch]$Force
)
$ErrorActionPreference = 'Stop'
$here = $PSScriptRoot
$envFile = Join-Path $here '.env'
if ((Test-Path $envFile) -and -not $Force) { throw ".env already exists. Use -Force to regenerate (this invalidates the existing database credentials)." }

function New-Secret([int]$bytes = 24) {
    $b = New-Object byte[] $bytes
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b)
    ([Convert]::ToBase64String($b) -replace '[^A-Za-z0-9]', '').PadRight(24, 'x')
}

if (-not $LanHost) {
    $route = Get-NetRoute -DestinationPrefix '0.0.0.0/0' | Sort-Object RouteMetric | Select-Object -First 1
    $LanHost = (Get-NetIPAddress -AddressFamily IPv4 -InterfaceIndex $route.ifIndex | Select-Object -First 1).IPAddress
}

Write-Host "Generating Passport RSA keypair (4096 bit)..."
# Windows PowerShell 5.1 turns native stderr (docker pull progress) into errors; relax it for docker calls.
$ErrorActionPreference = 'Continue'
docker pull alpine/openssl *> $null
$priv = (docker run --rm alpine/openssl genrsa 4096 2>$null) -join "`n"
if ($priv -notmatch 'BEGIN') { throw "openssl key generation failed" }
$pub = ($priv | docker run -i --rm alpine/openssl rsa -pubout 2>$null) -join "`n"
if ($pub -notmatch 'BEGIN') { throw "openssl public key derivation failed" }
$ErrorActionPreference = 'Stop'
function To-B64([string]$pem) { [Convert]::ToBase64String([Text.Encoding]::ASCII.GetBytes($pem.Trim() + "`n")) }

$appKeyBytes = New-Object byte[] 32
[Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($appKeyBytes)

$gen = @{
    LAN_HOST               = $LanHost
    APP_KEY                = 'base64:' + [Convert]::ToBase64String($appKeyBytes)
    DB_BOOTSTRAP_PASSWORD  = New-Secret
    DB_OWNER_PASSWORD      = New-Secret
    DB_APP_PASSWORD        = New-Secret
    REDIS_PASSWORD         = New-Secret
    MINIO_ROOT_PASSWORD    = New-Secret
    S3_SECRET_KEY          = New-Secret 30
    INITIAL_USER_PASSWORD  = New-Secret 12
    JWT_PUBLIC_KEY_BASE64  = To-B64 $pub
    JWT_PRIVATE_KEY_BASE64 = To-B64 $priv
}

$out = foreach ($line in Get-Content (Join-Path $here '.env.example')) {
    if ($line -match '^([A-Z0-9_]+)=(.*)$' -and $gen.ContainsKey($Matches[1])) { "$($Matches[1])=$($gen[$Matches[1]])" } else { $line }
}
[IO.File]::WriteAllText($envFile, (($out -join "`n") + "`n"), (New-Object Text.UTF8Encoding($false)))

Write-Host "Wrote $envFile"
Write-Host "API will be at http://${LanHost}:8080  (S3/media at http://${LanHost}:9000)"
Write-Host "Next: docker compose up -d --build"
