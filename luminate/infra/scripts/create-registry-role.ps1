# One-time, for a database that was created BEFORE the registry writer role existed (fresh databases get it from postgres/init/01-roles.sh).
# Creates the role with the password from .env (DB_REGISTRY_USER / DB_REGISTRY_PASSWORD), as the bootstrap superuser. Idempotent.
$ErrorActionPreference = 'Stop'
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$envFile = Join-Path (Split-Path -Parent $here) '.env'
$e = @{}
foreach ($l in Get-Content $envFile) { if ($l -match '^([A-Z0-9_]+)=(.*)$') { $e[$Matches[1]] = $Matches[2] } }
$reg = if ($e.DB_REGISTRY_USER) { $e.DB_REGISTRY_USER } else { 'wellms_registry' }
if (-not $e.DB_REGISTRY_PASSWORD) { throw 'DB_REGISTRY_PASSWORD is empty in .env' }
$db = if ($e.DB_NAME) { $e.DB_NAME } else { 'wellms' }
$sql = @"
DO `$`$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '$reg') THEN
    CREATE ROLE "$reg" LOGIN PASSWORD '$($e.DB_REGISTRY_PASSWORD)' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;
  ELSE
    ALTER ROLE "$reg" PASSWORD '$($e.DB_REGISTRY_PASSWORD)';
  END IF;
END `$`$;
GRANT CONNECT ON DATABASE "$db" TO "$reg";
"@
$sql | docker exec -i -e PGPASSWORD=$($e.DB_BOOTSTRAP_PASSWORD) luminate-wellms-postgres-1 psql -h 127.0.0.1 -U $($e.DB_BOOTSTRAP_USER) -d postgres -v ON_ERROR_STOP=1
"GRANT USAGE ON SCHEMA public TO `"$reg`";" | docker exec -i -e PGPASSWORD=$($e.DB_BOOTSTRAP_PASSWORD) luminate-wellms-postgres-1 psql -h 127.0.0.1 -U $($e.DB_BOOTSTRAP_USER) -d $db -v ON_ERROR_STOP=1
Write-Host "role $reg ready; run 'docker compose up -d' so the migration applies the grants"
