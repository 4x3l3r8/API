# Wellms tenancy for Luminate (add-on, no core patches)

Wellms 1.0.54 runs API-only behind the Luminate proxy, **pooled**: one Postgres database, a `tenant_id` on every tenant-owned
table, row-level security. Everything here is an add-on or configuration; `api/` itself is the unmodified Wellms fork.
Contract: `docs/LMS_INTEGRATION_CONTRACT.md` (v3). Where this deviates or extends it, see "Contract notes" below.

| Path | What |
|---|---|
| `addons/luminate-tenancy/` | the Laravel package: context token, RLS primitives, queue/scheduler/storage/cache/settings isolation, commands |
| `infra/` | the deployment (compose, Dockerfile, Postgres roles/init, bootstrap) - see `infra/README.md` |
| `tests/` | the isolation suites (live, against the running stack) |
| `tools/context_token.py` | mints `X-Tenant-Context` tokens the way the proxy does |
| `archive/` (other branch) | the throwaway eval, kept on `luminate/eval-archive` |

## What is enforced, and where it is proven

| Guarantee | Mechanism | Proven by |
|---|---|---|
| No request without a valid signed tenant context; no default tenant | `X-Tenant-Context` HS256 JWS, fail-closed middleware before routing/auth | `context_token_smoke.py`, unit tests |
| Rows are confined to the tenant, reads and writes | `tenant_id` default + RLS policy on 149 tables; transaction-local `app.tenant_id` (PgBouncer-safe) | `rls_checks.py`, `isolation_smoke_4b.py` |
| Same natural keys in two tenants | unique keys and application-assigned PKs rewritten to `(tenant_id, ...)` | `rls_checks.py`, `config_isolation.py` |
| Future Wellms tables are protected | DDL event trigger enrols new tables; drift check flags new non-composite keys | `rls_checks.py` (probe table) |
| User token belongs to the tenant | `tenant` claim on Passport tokens, explicit 403 on mismatch, plus RLS | `token_binding.py` |
| Jobs run as the tenant that dispatched them, or not at all | HMAC tag bound to the job uuid; verified before deserialisation; refusals dead-lettered | `queue_failclosed.py` |
| Settings, cache, files are per tenant | cache prefix; per-tenant settings reload; S3 key prefix and local scratch directories | `config_isolation.py`, `storage_isolation.py` |
| Scheduled work runs per active tenant only | `luminate:schedule:work`, a `schedule:run` child per active tenant from the replica | `scheduler_per_tenant.py` |
| The app never serves tenant work as the owner/superuser | `AppRoleGuard` (HTTP, jobs, provisioning) + the session list in Postgres | `prod_posture.py` |
| Production posture: no tenant-less Swagger, no fixtures | base compose is secure by default; `status --strict` | `prod_posture.py` |

## Running the suites
Stack up (`infra/README.md`), dev override included (it is the local default), then from `luminate/infra`:

```
python -I ..\tests\smoke\context_token_smoke.py   # also: isolation_smoke_4b, queue_failclosed, token_binding,
python -I ..\tests\sql\rls_checks.py              # config_isolation, storage_isolation, scheduler_per_tenant, prod_posture
```
Unit tests (no stack needed): see `addons/luminate-tenancy/tests` (`composer install && vendor/bin/phpunit` in the package directory,
e.g. inside the built image with `--entrypoint sh`). All suites are re-runnable and leave only fake data behind.

## Operating notes
- `luminate:tenancy:status [--strict]` - connection class, DB role, coverage, drift. Run it after every Wellms upgrade.
- `luminate:tenant:provision <tid>|--all` - roles, permissions, first admin, Passport clients, reference rows. Idempotent; runs as the app role.
- `LUMINATE_TENANT=<tid> php artisan <command>` - run any command as a tenant (unknown/inactive tenants abort).
- Changing `addons/luminate-tenancy/database/sql/001_functions.sql` ships with a NEW migration that re-applies it (the install migration has already run).
- After a Wellms upgrade: run the suites; read `luminate:tenancy:status`; look for new `[tenant_job_missing]` dead letters and `NULL`-tenant tables
  (migration-seeded rows no tenant can see - `rls_checks.py` fails on any table not in its known list).

## Contract notes (for the contract owner)
- `placement.bucket` is read as `<bucket>[/<key-prefix>]`; a pooled tenant with no prefix gets `tenants/<tid>`. (Asked; approved as recommended.)
- A queued job for a non-active tenant is dead-lettered (`failed_jobs`, retryable with `queue:retry`), not deferred: Horizon runs with `tries=1`, so a deferral would burn the attempt.
- §A.2 replica: only the static snapshot exists; **push-from-Luminate and the periodic reconcile are not built** (parked, see below).
- §F (webhook payload, retry, replay) is still open in the contract; the webhook module is not built.

## Known gaps and unknowns
- **Parked (4d):** replica push + reconcile sync; a live run behind PgBouncer in transaction mode (`SET LOCAL` is proven to be transaction-scoped, but not yet through a real pooler).
- **Batch 5/6:** signed webhook module; the generated cross-tenant suite over all 439 operations (the current suites are hand-written and cover the paths above, not every operation).
- **Unknown:** SCORM and H5P upload end to end (they use the same default-disk mechanism, not exercised); features that unzip in the API container and finish in a queue job need shared local storage between the web and worker containers.
- **By design / residual:** foreign-key checks bypass RLS in Postgres (the API validators close this; a direct SQL insert of a guessed foreign id could still link across tenants); the owner role bypasses RLS and is for migrations only; bucket credentials see all tenants' objects.
- **Not built:** the scoped short-lived token for direct media/player URLs (the bucket is private, direct URLs are refused for everyone).
