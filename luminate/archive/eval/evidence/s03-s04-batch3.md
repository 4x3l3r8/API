# Batch 3: S3 multi-tenant isolation + S4 content-model mapping
Pin: API repo commit `3dd34ebe9378c5d8a58c2333697c2606293d627b` (2026-07-29 "Updated dependencies"), composer.lock sha256 prefix `330e781a34d96af2`. Laravel 9.52.21, PHP 8.3.23.
Scripts: `scripts/wl.py` (helper), `scripts/s04_*.py`. Config: `docker-compose.tenants.yml`.

# S3. Multi-tenant (laravel-multidomain)

## Provisioning (Verified)
- There is **no API to create a tenant**: OpenAPI has no tenant/domain endpoints (searched). Docs mention a `multidomain-tool` CLI; it is **not in the repo** (`api/multidomain-tool` absent), so DB, bucket and credentials had to be made by hand.
- What I did for `tenant-a.localhost` and `tenant-b.localhost`: created a Postgres DB+role per tenant, a MinIO bucket per tenant, added `MULTI_DOMAINS=...` plus `<DOMAIN_KEY>_APP_NAME/DB_*/AWS_BUCKET/AWS_URL/INITIAL_USER_EMAIL/INITIAL_USER_PASSWORD` env vars, and **recreated the single `api` container**. `init_multidomains.sh` then ran `domain:add`, generated per-tenant `.env.<domain>`, app key, Passport keys and client, migrated (152 tables each) and seeded permissions. About 1 to 2 minutes.
- Consequences: adding a tenant = config change + restart of the shared container (all tenants, including default, restart). Not self-service or zero-downtime.
- Gotchas hit: a value with a space (`APP_NAME=Tenant Alpha`) must be double-quoted inside the value or the generated dotenv breaks; the init script **keeps going after that failure** and leaves a half-built tenant (`Failed to parse dotenv file`), no hard stop. `Host`-based routing (Caddy `*.localhost` wildcard to php-fpm).
- **Multi-domain mode silently changes the default site (Verified)**: `init_multidomains.sh` deletes the default Horizon and scheduler, and `queue.sh` loops only over `MULTI_DOMAINS`. After enabling tenants, the default domain (`api.localhost`) has **no queue worker or scheduler**: queued jobs (course clone: "Course cloning started", never completed; also emails, auto-ending of timed quizzes) will not run for it. Tenants get a shared round-robin pool of 3 workers (`--max-jobs=20 --stop-when-empty` per domain), so noisy-neighbour risk (Documented from `queue.sh`).

## Isolation tests (Verified)
Two admins both have `sub=1`; both tenants have course id 1 and user ids 1,2 (deliberate ID collision).
| Attack | Result |
|---|---|
| A's admin token to B's host: `GET /api/profile/me`, `/api/admin/courses`, `/api/admin/users`, `/api/admin/courses/1` | all 401 |
| A's token to B: `POST /api/admin/courses`, `DELETE /api/admin/courses/1` (write/delete) | 401, 401 |
| B's token to A; A/B tokens to the default tenant | 401 |
| Control: B's token on B | 200, sees only `BETA-ONLY Course` |
| Each tenant's admin user list | only own users |
Why it holds: separate **Passport key pair per tenant** (fingerprints differ), separate `aud` (personal-access client id), separate DB. Extra layer: with B's key pair **overwritten by A's** (simulating the documented shared `JWT_*_KEY_BASE64` option) A's token was still 401 on B (Passport also looks up the token row in the tenant DB). One simulation only; php-fpm restart not tried.
- DB roles are scoped: role `tenant_a` connecting to `tenant_b` or `default` gets `permission denied for table users` (Verified).
- Separate S3 buckets per tenant: avatar for tenant A landed in `tenant-a/avatars/1/px.png`, tenant B's in `tenant-b/...` (Verified).
- Per-tenant runtime config (Verified): `POST /api/admin/config` set `escola_auth.registration=disabled` and `token_expiration_minutes=60` on A only; `GET /api/config` showed A disabled, B enabled; new A token exp-iat = 3600s vs B 300s. (Closes open item B1.) Per-tenant settings/branding: `POST /api/admin/settings` (`brand_color` in A) readable via public `GET /api/settings/global/brand_color` on A, 404 on B and default. App name per tenant ("Tenant Alpha"/"Tenant Beta").

## Weak spots (Verified unless noted)
- **Unknown hostnames fall through to the default tenant**: `Host: evil.localhost` returns `Application Name: Wellms` (default DB). Needs a Caddy/ingress allow-list. `foo.example.com` returned empty (no Caddy match).
- **Storage credentials were shared**: my tenants inherited the root MinIO key (`AWS_ACCESS_KEY_ID=admin`) because I did not set per-tenant keys. The docs' tool would generate them (Documented). Storage isolation is therefore bucket-name convention unless keys are issued per tenant.
- **Redis is one shared instance and password**; separation is by key prefix (`tenant_alpha_horizon`, `tenant_beta_horizon`, `wellms_horizon`): convention.
- Public MinIO read: my hand-made tenant buckets returned 403 for anonymous GET; the stock `wellms` bucket policy was set by an init script I did not replicate (Unknown how public media is served per tenant).
- Shared codebase and process: one php-fpm pool for all tenants (no resource isolation, Documented).
Verdict S3: **Partial leaning Pass on data isolation (strong: DB, JWT keys, DB roles, buckets), Fail on API provisioning** (manual, env-and-restart). By the brief's bar, "enforced cross-tenant isolation + API provisioning = Pass; manual = Partial" => **Partial**.

# S4. Content-model mapping: CSC101, 2025/2026 Semester 1, two cohorts, two lecturers
Built on the default tenant. Fake users: lecturers `lect.one/two`, students `stud.a` (CS only), `stud.b` (IT only), `stud.ab` (both).

| Need | Wellms representation | Result |
|---|---|---|
| Course identity (code, session, term, SIS run id) | No native concepts. **Custom fields** via `POST /api/admin/model-fields` (`class_type=EscolaLms\Courses\Models\Course`, `type=varchar`, `rules='["nullable","string"]'`), then `fields:{academic_session,...}` on course create | Works, returned in course JSON (Verified). Stored as untyped strings. |
| Filter courses by those fields | `GET /api/admin/courses?academic_term=...` | **Ignored** (returned a course that lacks the field): no custom-field filter (Verified). Must filter client-side or in Luminate's DB. Free-text `search=CSC101` works. |
| Term/session | None. Window only: `active_from` / `active_to` on the course | Enforced for learners both before start and after end (404 "Course is not activated yet", same message for expired). One window per course. |
| Course x Session x Term run | **One Wellms course per run** (each run is a separate course record) | Fits as "course = run". Next-term reuse = `GET /api/admin/courses/{id}/clone` (async job: needs a queue worker; did not complete on the default tenant, see S3 trap), so cloned result Unknown. No shared master content across runs. |
| Cohorts / programme offerings | **User groups**, hierarchical (`parent_id`), tree via `GET /api/admin/user-groups/tree/`; I used `2025/2026 S1` > `BSc CS Y1` and `BSc IT Y1`. Members via `POST /api/admin/user-groups/{id}/members {user_id}` | Works. |
| Enrol cohorts | `POST /api/admin/courses/{id}/access/add {"groups":[2,3]}` | All three students see the course in `/api/courses/my`; cross-listed `stud.ab` once (Verified). |
| Revocation | remove member / remove group access | **Immediate** and correct: cross-listed learner `[2]` -> remove IT only `[2]` -> remove CS too `[]` + program 403 -> re-add `[2]` (Verified, checked against DB). |
| Two lecturers | Course `authors:[L1,L2]` on create/update | Both listed; both see it in `/api/courses/authored`; both can update the course and add lessons; both get 403 editing another course (Verified). Role is `tutor`. |
| Completion for group-only learners | derived from topic progress | Works: `finish_date` set, admin stats Finished=1, no `course_user` row needed (Verified). |
| Per-cohort differences (different dates, different content per cohort) | `course_group` has only `course_id, group_id`; no per-cohort columns | Not representable. `access/add` accepts `deadline`/`end_date` with HTTP 200 but the stored learner `deadline` equalled the course's earlier `active_to` (2026-02-15), not the value sent: **parameter appears ignored** (Verified behaviour; cause Unknown). |
| Cohort reporting | `GET /api/admin/user-groups/users` lists groups with members; `GET /api/admin/stats/course/{id}` is per course | No per-cohort course stats; join groups x stats in Luminate. |
| Cross-listing | same course granted to several groups | Works. A same-content course offered to different programmes needs no duplication. |

Defects/quirks found in S4 (Verified):
1. **Tutor user-list leak**: a tutor can `GET /api/admin/users` and sees **all 9 users' names and emails** (admin, service account, students of other courses) yet gets 403 reading any single user, even their own students. Tenant-wide PII exposure to lecturers; needs a policy fix or proxying.
2. A tutor requesting stats for a course they do not own gets **HTTP 500**, not 403.
3. Mixing group enrolment and a direct enrolment for the same learner makes admin stats list the learner **twice** with contradictory `finished` flags.
4. `max_execution_time`-style unit confusion again: `fields` must be an array (not a JSON string); `rules` for model-fields must be a JSON string; `return_url` must be a full URL on user create; topic create is form-only. API conventions are inconsistent.
5. `PeopleBought` is 0 for group-enrolled courses and 1 for direct (it counts purchases, not enrolments).
Verdict S4: **Partial** => workable mapping (course = run, groups = cohorts, authors = lecturers, custom fields = session/term), but the academic calendar, per-cohort dates, term-level reuse and field filtering would live in Luminate (a mapping table `sis_course_run_id <-> wellms_course_id` is required).
