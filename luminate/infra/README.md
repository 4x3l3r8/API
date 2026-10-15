# Wellms for Luminate: deployment (dev)

API-only Wellms (pinned to tag 1.0.54) behind the Luminate proxy, pooled tenancy. Dev only, fake data only.
Contract: `docs/LMS_INTEGRATION_CONTRACT.md` (v3). What is enforced and how it is tested: `../README.md`.

```powershell
cd luminate/infra
powershell -File .\bootstrap.ps1     # once; writes git-ignored .env with generated secrets
docker compose up -d --build         # first build downloads ~260 packages (cached afterwards); later rebuilds take about a minute
```

| What | Where |
|---|---|
| API | `http://<LAN_HOST>:8080` |
| Swagger UI / spec (dev only) | `/api/documentation` / `/docs` (439 operations) |
| S3 / media | `http://<LAN_HOST>:9000/wellms` (bucket is private) |
| Tenants | `tenants.snapshot.json` (the interim local replica of the Luminate registry) |

## Calling the API (for the Luminate proxy)
**Every request needs** `X-Tenant-Context: <token>`: a compact HS256 JWS signed with `TENANT_CONTEXT_KEY` (in `.env`; share it only with the proxy).
Claims: `tid`, `status` (must be `active`), `placement {mode, connection, bucket, cache_prefix}`, `iat`, `exp` (<= 300 s after `iat`), `jti`.
Dev tokens: `python -I ../tools/context_token.py --tid t-alpha`. Then log in as that tenant's machine admin (`POST /api/auth/login`, `INITIAL_USER_EMAIL` in `.env` and the tenant's **own** secret: there is no shared
password; get one with `python -I ../tools/luminate_client.py password t-alpha`, which asks Wellms over the signed registry channel, see `../README.md`);
the returned user token only works for that tenant (403 otherwise).
Dev tenants: `t-alpha`, `t-beta`, `t-delta` (active), `t-gamma` (suspended).

## Dev vs production-like
`bootstrap.ps1` sets `COMPOSE_FILE` so a plain `docker compose up` includes `docker-compose.dev.yml`, which opens Swagger/spec without a
tenant token and enables the test fixtures. **The base file alone is the secure default**: `docker compose -f docker-compose.yml up -d`.

## Services
`postgres` (16), `redis`, `minio` (+ one-shot `minio-init`), one-shot `migrate` (owner role, DDL only) and `provision` (app role: per-tenant
roles/permissions/admin), then `api` (php-fpm behind `caddy`), `worker` (Horizon), `scheduler` (one `schedule:run` per active tenant per minute).

## Database roles
- `wellms_owner`: owns the schema; used only by `migrate`. RLS does not apply to it.
- `wellms_app`: used by api, worker, provision and scheduler; DML only, no DDL; RLS always applies. These services **refuse to run** if configured with the owner/bootstrap role.
- `db_bootstrap`: container superuser; used only by the Postgres init script (creates roles, installs the tenancy functions and the DDL event trigger).

## Common tasks
```powershell
docker compose run --rm provision t-alpha        # (re)provision one tenant (also re-runs `migrate`, which is a no-op when up to date)
docker compose exec -u www-data api php artisan luminate:tenancy:status [--strict]
docker compose logs -f worker scheduler
```
Edit `tenants.snapshot.json` to add/suspend a tenant: workers and the scheduler pick it up without a restart; run `provision` for a new one.
Run artisan as `-u www-data`, never root (root-created files in `storage/` break php-fpm).

## Other machines (Luminate dev session)
Windows blocks inbound ports by default. In an **elevated** PowerShell:

```powershell
New-NetFirewallRule -DisplayName "Luminate Wellms dev" -Direction Inbound -Protocol TCP -LocalPort 8080,9000 -Action Allow -Profile Private
```

`LAN_HOST` is autodetected by `bootstrap.ps1`; if the IP changes, re-run it with `-LanHost <ip>` (use `-Force` only on a fresh stack, it regenerates DB credentials).

## Reset
`docker compose down -v` removes this stack's volumes (project `luminate-wellms`) and nothing else.
