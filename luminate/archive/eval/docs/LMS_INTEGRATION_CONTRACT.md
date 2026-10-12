# Luminate ⇄ Wellms — Integration Foundation Contract (shared spec)

> **Status:** canonical source of truth for the Luminate↔Wellms integration. Version 1 · 2026-10-12.
> **Canonical location:** `C:\Users\dcadesanya\source\repos\portal_academy\PortalAcademyNew_BE\docs\LMS_INTEGRATION_CONTRACT.md` (some files in the ./docs/ folder of the portal academy BE repo might give you more context if you ever need it). Repo copies (Luminate repo, Wellms fork) are synced snapshots of this file — if they differ, this one wins.
> **Full background:** the LMS Architecture Vetting report (§25 is this contract; §12 the grade boundary; §11 the tenancy decision; §24 the build plan) and the two eval docs in this folder: `wellms_eval_report.md`, `wellms_pooled_model_investigation.md`.
>
> **How to use this:** both the Wellms-side session and the Luminate-side session must read this before proposing a plan and build to it from their own side. **Do not diverge silently — if you need to change the contract, flag it and update this file (and bump the version), rather than implementing a different shape on one side.**

The one rule everything else depends on: **only the proxy talks to Wellms** — no other Luminate code, and no browser, ever calls Wellms directly.

---

## A. Anti-corruption proxy / BFF (Luminate .NET)

- **Sole gateway** to Wellms; translates Luminate domain terms ↔ Wellms terms; exposes a typed internal client.
- **Normalises** Wellms' 5+ error shapes into Luminate's standard response; **caps page size**; adds **idempotency keys** and **rate limiting**; retries transient failures with backoff.
- **Corrects Wellms' wrong OpenAPI paths** (real impersonate is `/api/admin/auth/impersonate`; H5P is `/api/admin/hh5p/*`).
- **Holds the Wellms service credential server-side**; mints/forwards short-lived tokens; tokens never reach the browser (except a scoped short-lived token for direct media/player endpoints).
- **Injects tenant context** on every call: resolves the tenant from the registry and tells Wellms which tenant the call runs as (Wellms sets `app.tenant_id` for RLS).

## B. Tenant registry (Luminate owns — the "add a tenant = a row" source of truth)

| Field | Meaning |
|---|---|
| `tenant_id` | Luminate's tenant identity; also the value Wellms stamps/filters on for RLS |
| `placement.mode` | `pooled` \| `dedicated` — the reversibility switch (default `pooled`) |
| `placement.connection` | which shared shard, or the dedicated DB connection ref |
| `placement.bucket` · `cache_prefix` | S3 bucket/key-prefix and cache prefix for this tenant |
| `status` | provisioning · active · suspended |

Both tenancy topologies read this same registry, so promoting a tenant to a dedicated DB later is a registry flip + a per-tenant data move — not a code change.

## C. Id-mapping store (Luminate owns — never share primary keys)

| Luminate / PortalAcademy | Wellms | Scope |
|---|---|---|
| `person_id` | `wellms_user_id` | per tenant |
| course run = `sis_course_run_id` (Course×Session×Term) | `wellms_course_id` | per tenant |
| cohort / program offering | `wellms_group_id` | per tenant |
| course registration | enrolment | per tenant |

All provisioning is **ensure-semantics keyed by the Luminate id** (idempotent: re-delivery and out-of-order events converge). The run's identity is also written into Wellms custom fields so Wellms stays self-describing.

## D. Auth bridge (password-less SSO + JIT)

1. User authenticates to Luminate (its own identity, or SSO from PortalAcademy).
2. First LMS use → proxy calls Wellms create-user (random password, `verified:true`), stores `wellms_user_id` in the mapping (JIT).
3. Per session → proxy calls `impersonate` for a short-lived Wellms token, held by the proxy; the browser talks only to Luminate. Direct-to-Wellms only for media/player endpoints, with a scoped short-lived token.
4. Proxy enforces an **impersonation allow-list**: never impersonate an admin, never create admin users (Wellms does not scope this itself).
5. Shared JWT keys + a `tenant` claim verified at the proxy, identical in both tenancy modes.

## E. Outbound provisioning operations (the proxy's surface)

Each idempotent via the mapping; triggered by PortalAcademy domain events over its existing outbox, or by Luminate actions:

`EnsureUser` / `DeactivateUser`, `EnsureCourse` (Course×Session×Term), `EnsureGroup` (cohort), `EnsureTeacher`, `Enrol` / `Unenrol` (by course registration).

## F. Inbound events (Wellms → Luminate webhook receiver)

| Aspect | Contract |
|---|---|
| Transport | signed webhook — `X-Wellms-Signature: sha256=<hmac>`, `X-Wellms-Event`, `X-Wellms-Delivery` |
| Events | `enrolment.created/removed`, `topic.completed`, `quiz.attempt.finished`, `quiz.grade.ready`, `course.completed` |
| Idempotency | de-dup by delivery id; tolerate reordering |
| Known quirks | on `quiz.grade.ready` fetch the final score via API (the event omits it); ignore `course.completed` that coincides with `enrolment.removed`; completion truth = `GET /courses/progress` |

## G. Grade / CA flow (the hard boundary)

Wellms attempt score → `Learning.AssessmentResult` (raw, non-authoritative) → lecturer explicitly carries it into a CA component → PortalAcademy `ResultSheet` workflow produces the official result. **Neither Wellms nor Luminate ever writes an official result.**

## H. Invariants both sessions must honour

- Only the proxy talks to Wellms.
- `tenant_id` on every Wellms tenant-owned table; schema identical across pooled and dedicated (reversibility).
- The registry is the single source of tenant placement; the mapping store is the single source of id correspondence.
- All provisioning is ensure-semantics keyed by Luminate ids.
- PgBouncer transaction-pooling → `SET LOCAL` per transaction (not a session setting).
- The cross-tenant isolation suite over all 439 Wellms API operations is the release gate, re-run on every Wellms upgrade.
- Push only what the LMS needs (name, institutional email, role, enrolment) — no national IDs, JAMB, finance or full records into Wellms.
