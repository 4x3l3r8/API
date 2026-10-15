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
| "Add a tenant = a row" (contract §A.2): Luminate pushes tenant changes, a signed pull reconciles; workers, the scheduler and provisioning follow it; updates only move forward | `luminate_tenant_registry` (global table), HMAC-signed `PUT /api/luminate/registry/tenants/{tid}`, `luminate:registry:reconcile`, rev ordering, tombstones | `registry_sync.py`, unit tests |
| The app never serves tenant work as the owner/superuser | `AppRoleGuard` (HTTP, jobs, provisioning) + the session list in Postgres | `prod_posture.py` |
| Production posture: no tenant-less Swagger, no fixtures | base compose is secure by default; `status --strict` | `prod_posture.py` |
| Signed webhooks to Luminate (contract v4 §F): signed, stable delivery id, backoff, dead-letter, tenant in the body | outbox table (tenant-owned, RLS) is the source of truth; a queued job and a per-tenant sweeper only move rows along | `webhooks_e2e.py`, unit tests |
| Grades ride on the pull: `is_fully_graded` on the attempts list/detail (contract v5 §F) | one key added to the response by a middleware (no core patch), one query per page | `webhooks_e2e.py`, `quiz_timeout_probe.py` |
| Wellms never autonomously moves money (contract §H) | cart/subscription schedule events skipped; the renewal jobs are refused by the worker | `ops_guards.py` |
| `failed_jobs` (global, un-tenanted) does not grow forever | `luminate:maintenance`, hourly from the scheduler service, outside any tenant; 14 days by default | `ops_guards.py` |
| Every operation is isolated (the release gate, Batch 6): 699 operations (439 spec + 260 unspecced routes), V1 no context / V2 token binding / V3 attack with the victim's real ids, independent owner-role oracle, both directions | `tests/gate/isolation_gate.py` (`make gate`) | `tests/gate/README.md` |
| The application role cannot rewrite the tenant registry (contract A.2.3) | writes log in as a separate writer role; the app role is SELECT-only; `status` fails if that regresses | `registry_sync.py` section G, `prod_posture.py` |
| Safe behind PgBouncer in TRANSACTION pooling (contract §H): no tenant setting survives its transaction; works with statements outside a transaction | `SET LOCAL` per transaction; `PDO::PGSQL_ATTR_DISABLE_PREPARES` (`PgsqlOptions`); `docker-compose.pgbouncer.yml` | `pooler_isolation.py`, `pooler_interleaved_load.py`, every other suite re-run through the pooler |

## Running the suites
Stack up (`infra/README.md`), dev override included (it is the local default), then from `luminate/infra`:

```
python -I ..\tests\smoke\context_token_smoke.py   # also: isolation_smoke_4b, queue_failclosed, token_binding,
python -I ..\tests\sql\rls_checks.py              # config_isolation, storage_isolation, scheduler_per_tenant, prod_posture,
                                                  # webhooks_e2e (about 4 min), ops_guards, quiz_timeout_probe (about 2 min)
```
`webhooks_e2e.py` needs the dev override (a test receiver on 127.0.0.1:8099 and short retry timings, 3,5,8,10 s with a 40 s cap).

## Running behind PgBouncer (transaction mode)
```
docker compose -f docker-compose.yml -f docker-compose.dev.yml -f docker-compose.pgbouncer.yml up -d     # from luminate/infra
python -I ..\tests\smoke\pooler_isolation.py            # reuse across tenants, statements outside a transaction, a control that MUST leak
python -I ..\tests\smoke\pooler_interleaved_load.py     # two tenants hammering the real API (count-driven: >= 60 cross-tenant reads)
powershell -File .\pooler_sweep.ps1 -Label x -Pools "2,10,20"   # same load direct vs pooled at several pool sizes
```
Only the app role goes through the pooler; the one-shot `migrate` job stays direct. The pool is deliberately tiny (2) so clients must share backends.
Plain `docker compose up -d` (no pooler file) returns to a direct connection.

What the live run found (all reproduced by `pooler_isolation.py` section A):
1. **Laravel's default PDO mode breaks behind a transaction-mode pooler.** pdo_pgsql sends `DEALLOCATE` when a prepared statement object is destroyed;
   PgBouncer has renamed it, the backend errors, the transaction aborts and the next statement fails with 25P02. Fine over a direct connection.
2. **Emulated prepares are NOT the fix.** Laravel casts bools to ints; emulation inlines `revoked = 0` against a boolean column: "operator does not
   exist: boolean = integer". It broke every login, even direct. The fix is `PDO::PGSQL_ATTR_DISABLE_PREPARES` (unnamed extended-protocol statements,
   Postgres still infers parameter types). It is on in every topology (`LUMINATE_DB_POOLER_SAFE_PREPARES`, default true), as `PgsqlOptions`.
3. The API container reported healthy about 28 s before it could serve (502 meanwhile); its healthcheck now tests the FastCGI port.

Isolation result (pool of 2 backends, tenants interleaved): 10,000 transactions from 16 clients with 0 violations; every backend served all three tenants
and tenant-less transactions in turn; 9,000 operations through the real connection class from 6 concurrent tenants (statements outside a transaction,
inside `DB::transaction`, Eloquent, bound parameters, raw reads that bypass the wrapper) with 0 violations; 476 concurrent HTTP requests (61 cross-tenant
reads, 51 wrong-context tokens) with 0 observations and the composite unique key intact. The control (a session-level setting) does leak, so the probe can see one.

Cost of the tenant-scoping connection (dev laptop, containers on one host, so round trips are cheap; **p50, direct unless stated**):

| | per statement |
|---|---|
| unscoped statement (no tenant scoping) | 447 us direct, 722 us via PgBouncer |
| current wrapper outside a transaction (BEGIN, set_config, statement, COMMIT = 4 round trips) | 1254 us direct, 2329 us via PgBouncer |
| inside an explicit transaction, per statement (BEGIN + set_config once) | 479 us direct, 729 us via PgBouncer: about +32 us |
| BEGIN and set_config merged into one round trip (not built) | 994 us direct, 1890 us via PgBouncer: about 27 to 32 percent less overhead |

On real requests (same statements, wrapped versus a plain connection, 30 interleaved repetitions, warm caches): about **0.62 ms per statement outside a
transaction**: profile (6 statements) +1 ms, courses (39) +26 ms (+42%), quiz attempts (66) +41 ms (+34%), users list (57) +35 ms (+6%). With a cold
cache the same requests make 11, 17, 35, 86, 208 statements (login, profile, courses, attempts, users list). The pooler hop itself adds roughly 20 to 70 ms
to those requests (users list 743 to 811 ms single client). The overhead grows linearly with network round-trip time: it is 3 extra round trips per statement.

Capacity: the same 16-worker, two-tenant load gave about 4 req/s direct and 3.9 / 4.3 / 4.5 req/s at pool sizes 2 / 10 / 20, so **neither the pooler nor the
pool size is the limit here**. The API container's PHP is (about 3.3 cores busy while Postgres used about 0.5 core and PgBouncer about 0.5 core; a trivial
request costs about 200 ms of PHP).
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
- §A.2 replica: push + reconcile are built, but the contract does not yet define their wire shape, so **this is a proposal for the contract owner** (below).
- Webhooks are built to §F v4/v5 and are OFF until `LUMINATE_WEBHOOK_URL` is set (a secret is then mandatory; https only outside dev).
  The retry schedule is read as: first attempt immediate, then +1m, +5m, +30m, +2h, +6h, and +6h repeating, dead-lettered when the next
  attempt would land beyond 24 h of the event (8 attempts over about 20.6 h). The contract says "e.g.", so this is a reading, not a rule.
- The signed string `"{delivery_id}.{timestamp}.{raw_body}"` is dot-joined, so it is unambiguous only because ids are UUIDs and timestamps
  are digits (`("a",1,"2.x")` and `("a.1",2,"x")` sign the same string). Wellms only emits those shapes; **receivers should validate them
  before verifying.** Worth one sentence in §F.
- Wellms emits `enrolment.*` only for DIRECT user access; access granted through a group fires nothing, so `wellms_group_id` is never present.
  `enrolment.removed` is always accompanied by `course.completed` (Wellms fires both), and a genuine completion also emits `course.completed`.
- Manual grading DOES fire `quiz.grade.ready` (verified live), but only for a quiz with `counts_to_grade` once the attempt is ended and fully
  graded. Quizzes without `counts_to_grade` never emit it, so the pull stays the system of record.
- `is_fully_graded` can read true for an attempt that is still OPEN (every answer so far is auto-graded) while its `result_score` is null:
  "final" is `is_ended && is_fully_graded`. Suggested wording for §F.
- `topic_type` in `topic.completed` is Wellms' own `topicable_type` (the model class), exactly as its API exposes it.

## Registry sync wire shape (AUTHORITATIVE; mirrored into contract §A.2.1)
Everything Luminate must reproduce byte for byte. The unit test `RegistryAuthTest::testPublishedVectors` pins the vectors below to the code.

**Signature (both directions).** Two headers on every registry request:
- `X-Luminate-Timestamp`: the current Unix time in whole seconds, decimal digits only (`^\d{1,12}$`), e.g. `1800000000`. Accepted within +/-300 s of the receiver's clock.
- `X-Luminate-Signature`: `sha256=` + 64 **lower-case** hex characters of `HMAC-SHA256(key, message)`, where
  `message = "{timestamp}.{METHOD}.{path}.{sha256hex(body)}"`:
  - `{timestamp}` is the header value, verbatim.
  - `{METHOD}` is upper-case (`PUT`, `GET`).
  - `{path}` is the request path exactly as sent on the wire: leading `/`, **no query string, no host**, no normalisation (e.g. `/api/luminate/registry/tenants/t-demo`).
  - `{sha256hex(body)}` is the lower-case hex SHA-256 of the **raw request body bytes** as sent; for a request with no body it is the SHA-256 of the empty string
    (`e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855`). Sign the bytes you send; do not re-serialise JSON.
  - The four parts are joined with a single `.`. The key is `LUMINATE_REGISTRY_KEY` (UTF-8 bytes); the receiver also accepts `LUMINATE_REGISTRY_KEY_PREVIOUS` (rotation).
    The key **must differ from the tenant-context key**: with equal keys registry sync is disabled and answers `503 registry_disabled`.

Vectors (key `registry-test-key`, timestamp `1800000000`):
```
PUT  /api/luminate/registry/tenants/t-demo
body {"status":"active","rev":7,"placement":{"mode":"pooled","connection":"shard-1","bucket":"wellms","cache_prefix":"t-demo"}}
sha256(body) = c29cb0624cd419a5477435efe0bd114fec568f148a5fbb1f6dbb1036cce6a9c9
message      = 1800000000.PUT./api/luminate/registry/tenants/t-demo.c29cb0624cd419a5477435efe0bd114fec568f148a5fbb1f6dbb1036cce6a9c9
signature    = sha256=d2b4a9a7ca25e01f057991a0d10c365e56cb0c6661640d83d1b633c982173988

GET  /registry/tenants   (what Wellms sends when pulling; the path of the configured pull URL)
message      = 1800000000.GET./registry/tenants.e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855
signature    = sha256=a2b3f92a8ac8017c764d504e8952a2c48eb8d63e2c1429d79c4635141c718e6c

pull RESPONSE body {"as_of":1800000000,"tenants":[]}      (note the "response." prefix; no method, no path)
message      = response.1800000000.6b9c8680f313340e329d4c18ec8ffa8f14dc10f42ca0abf1e668b3f980387a1c
signature    = sha256=0ff6f84d73db4cad80d43c45587ff33198b271283c14bb3bb04f61d099bf3784
```

**Entry (push body, and each element of the pull list).** Field names and types are exact; unknown fields are ignored on push.
```
{ "status":    "provisioning" | "active" | "suspended" | "removed",
  "rev":       <integer >= 0>,
  "placement": { "mode": "pooled" | "dedicated", "connection": <opaque name>, "bucket": <bucket[/prefix]>, "cache_prefix": <string> } }
```
Pull elements add `"tenant_id"`. Patterns (the same ones the context token is held to): `tenant_id` and `cache_prefix` `^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$`;
`connection` `^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$` (no `:` `/` `@`, so a DSN cannot be smuggled in); `bucket` `^[A-Za-z0-9][A-Za-z0-9._/-]{0,254}$` with no `..`, no `//`, no trailing `/`.

**Push** `PUT /api/luminate/registry/tenants/{tid}`, `Content-Type: application/json`, no `X-Tenant-Context`. A body `tenant_id`, if present, must equal the path.

| answer | meaning |
|---|---|
| `200 {"tenant_id","outcome":"applied or unchanged or stale","status","rev"}` | `status` and `rev` are what is stored after the call |
| `409` (same body shape, `"outcome":"conflict"`) | same `rev`, different content: refused, nothing changed |
| `422 {"message","code":"registry_payload_invalid"}` | invalid entry |
| `401 {"code"}` with code `registry_signature_missing`, `registry_signature_invalid`, `registry_timestamp_invalid` or `registry_timestamp_expired` | bad authentication |
| `503 {"code"}` with code `registry_disabled` or `registry_misconfigured` | no usable key, or no registry writer role |

**Read (signed, same headers).** `GET /api/luminate/registry/tenants/{tid}` returns `{"tenant_id","status","rev","placement","provisioned":bool,"provisioned_at":int|null,"updated_at":int}`
(`404 registry_tenant_unknown`); `GET /api/luminate/registry/tenants` returns `{"tenants":[ ...same shape... ]}` including `removed` tombstones.

**Ordering (`rev`).** Per tenant, Wellms only moves forward: `rev` lower than stored -> `stale`; equal and identical -> `unchanged`; equal and different -> `409`; higher -> `applied`.
`rev` **must never decrease for a tenant id, including across delete and recreate.** A tenant stored as `removed` by an explicit push needs a **strictly higher** `rev` to come back;
one tombstoned because a pull did not list it comes back at an equal or higher `rev`.

**Pull.** Wellms `GET`s `LUMINATE_REGISTRY_PULL_URL` (https unless `LUMINATE_REGISTRY_ALLOW_HTTP`; redirects are not followed) with the request signature above, every
`LUMINATE_REGISTRY_RECONCILE_INTERVAL` seconds (300). Luminate answers `200`, `Content-Type: application/json`, a body of exactly
`{"as_of": <unix seconds>, "tenants": [ {"tenant_id", "status", "rev", "placement"}, ... ]}` (a JSON **list**, no duplicate `tenant_id`) and the **response** headers
`X-Luminate-Timestamp` and `X-Luminate-Signature` (the `response.` domain above). All-or-nothing: a non-200, a bad or missing response signature or timestamp, a malformed body or entry,
a duplicate id, an `as_of` more than 120 s in the future, or an **empty list** (unless the operator passes `--allow-empty`) rejects the whole pull and changes nothing.
A tenant the list omits becomes `removed` (never deleted) unless something touched it less than 120 s before `as_of`.

**Provisioning and staleness.** A tenant that appears as `provisioning` or `active` is provisioned within a scheduler tick; wait for `provisioned: true` on the signed `GET` before flipping it
to `active`. Staleness is reported by `luminate:tenancy:status`, never enforced; a worker's replica cache is 2 s; requests never read the replica.

**Write lockdown (contract §A.2.3).** The application role is read-only on the replica. Every write (push, pull, the provisioned flag, pull bookkeeping) logs in as a dedicated writer role
(`DB_REGISTRY_USER`, connection `luminate_registry`) that can touch nothing else. `luminate:tenancy:status` fails if the application role can write, and `--strict` also requires
the writer to be configured and forbidden for tenant work.

## Known gaps and unknowns
- **Must before prod, not built:** per-tenant bootstrap admin (contract A.2.2). Provisioning still creates each tenant's first admin from the shared env credential. Needs a decision on how the unique credential
  reaches the tenant (Wellms has no forced-change flag; options are a one-time reset link to an admin e-mail supplied with the tenant, or a generated secret returned once to Luminate over the signed channel).
- **Gate coverage gaps (see `tests/gate/README.md`):** about 320 operations have no victim id to attack (collections, or types the seeder cannot create: xAPI/Trax LRS stores, payments, templates, ...).
  They still get V1, V2, the leak scan and the write check. About 120 operations are 5xx in stock Wellms for the victim's own request too (Passport web routes call `viaRemember`; nothing runs).
  Two endpoints (`*/signed-screen-urls`) accept a foreign `webinar_id`/`consultation_id` in the body and return a signed URL under the attacker's own tenant prefix (no victim data returned).
- **Cost of per-statement scoping:** about 0.62 ms per statement outside a transaction on this host; material for chatty requests and linear in network round-trip time.
  Options, none built: merge BEGIN + set_config into one round trip (about 27 to 32 percent of the overhead, a small change to the connection class), cut Wellms'
  statement count per request (its role/permission loading is an N+1: 208 statements for a cold users list), run PgBouncer next to the app, or scope a whole read-only
  request with one transaction (fewer round trips, but it holds a pooler backend for the whole request).
- **PgBouncer config is a dev experiment:** plain-text userlist from env and a tiny pool; production should use `auth_query` and a real pool size.
- **Batch 6:** the generated cross-tenant suite over all 439 operations (the current suites are hand-written and cover the paths above, not every operation).
- **Webhooks:** delivery is at-least-once (a lost job, a crashed worker or a suspended tenant leaves a due row, and the sweeper re-sends it; the
  receiver dedups on the delivery id). Retries are timely through delayed jobs, with the once-a-minute sweeper as the safety net. A delivery older
  than the window is dead-lettered and kept 90 days; there is no replay command (the contract's pull covers the gap). Delivered rows are kept 14 days.
- **Cart/subscription:** disabled by default for every environment (`LUMINATE_DISABLED_SCHEDULES`, `LUMINATE_DENIED_JOBS`). The cart module itself is
  still installed and its API routes still exist; only its autonomous scheduling and renewal jobs are off.
- **Unknown:** SCORM and H5P upload end to end (they use the same default-disk mechanism, not exercised); features that unzip in the API container and finish in a queue job need shared local storage between the web and worker containers.
- **By design / residual:** foreign-key checks bypass RLS in Postgres (the API validators close this; a direct SQL insert of a guessed foreign id could still link across tenants); the owner role bypasses RLS and is for migrations only; bucket credentials see all tenants' objects.
- **Not built:** the scoped short-lived token for direct media/player URLs (the bucket is private, direct URLs are refused for everyone).
