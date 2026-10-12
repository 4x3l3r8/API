# Wellms for Luminate: dev deployment

API-only Wellms (pinned to tag 1.0.54) behind the Luminate proxy. Dev only, fake data only.
Contract: `docs/LMS_INTEGRATION_CONTRACT.md`.

```powershell
cd luminate/infra
powershell -File .\bootstrap.ps1     # once; writes git-ignored .env with generated secrets
docker compose up -d --build
```

| What | Where |
|---|---|
| API | `http://<LAN_HOST>:8080` |
| Swagger UI / spec | `/api/documentation` / `/docs` (439 operations) |
| S3 / media | `http://<LAN_HOST>:9000/wellms` |
| Admin login | `INITIAL_USER_EMAIL` / `INITIAL_USER_PASSWORD` in `.env`; `POST /api/auth/login` |

Postgres, Redis and the MinIO console are not published.

## Database roles
- `wellms_owner`: owns the schema; used only by the one-shot `migrate` job.
- `wellms_app`: used by `api` and `worker`; DML only, no DDL. Neither role is superuser or BYPASSRLS.
- `db_bootstrap`: container superuser; used only by the Postgres init script, never by the app.

## Other machines (Luminate dev session)
Windows blocks inbound ports by default. In an **elevated** PowerShell:

```powershell
New-NetFirewallRule -DisplayName "Luminate Wellms dev" -Direction Inbound -Protocol TCP -LocalPort 8080,9000 -Action Allow -Profile Private
```

`LAN_HOST` is autodetected by `bootstrap.ps1`; if the IP changes, re-run it with `-LanHost <ip>` (use `-Force` only on a fresh stack, it regenerates DB credentials).

## Reset
`docker compose down -v` removes this stack's volumes (project `luminate-wellms`) and nothing else.
