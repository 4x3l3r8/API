# Wellms (EscolaLMS) as the headless engine inside Luminate: evaluation report

Date: 2026-10-08. Evaluated build: `EscolaLMS/API` commit `3dd34ebe9378c5d8a58c2333697c2606293d627b` (2026-07-29, release 1.0.54), `composer.lock` sha256 prefix `330e781a34d96af2`. Laravel 9.52.21, PHP 8.3.23, PostgreSQL 12, Redis, MinIO.
Method: stood up the stack in Docker on a Windows dev machine and drove it through its REST API (not the admin UI), with fake data only. Every finding is labelled **[V] Verified** (I called it), **[D] Documented** (docs or source only) or **[U] Unknown**. Raw evidence, scripts and the full unknowns register are in `evidence/` and `scripts/`.

---

## 1. Verdict

**Adopt with defined custom work, conditional on three gates that I could not close from here.** Functionally, Wellms is a real headless engine: I ran the whole learner lifecycle, eight question types with server-side scoring, SCORM and xAPI tracking, group-based cohort enrolment, signed outbound events (with a small bridge I wrote), media upload to S3-compatible storage, and strong per-tenant data isolation, all through REST. That is a lot of depth Luminate does not have, and none of it needed the bundled UI or any paid module. But it is not the clean "Apache/MIT, no copyleft, mainstream stack" engine the brief assumed. The core content path (`topic-types`, which the quiz engine, video and project types depend on) **hard-requires GPL-3.0 H5P libraries**; the platform is on **Laravel 9, which is end-of-life**, with 29 open dependency advisories; the vendor is small and most modules are quiet; and the default multi-tenant deployment has **unsafe background-job and provisioning behaviour** that Luminate would have to harden. The three gates before committing are: (1) **legal sign-off on the GPL path** for your delivery model (hosted service only is far safer than shipping Wellms to customers); (2) **written answers from Escola** on licence terms (the "~$3k" claim is unverified; the vendor says MIT and fees only for services), support, and a Laravel upgrade roadmap; (3) a **short hardening proof of concept** of the items in section 5 that are rated "must". If gate 1 fails because Luminate must ship on-prem to customers, or gate 2 shows no upgrade path, the answer becomes **Reject for Luminate** (or "fork and own it", which is a different, larger cost).

Against the brief's own threshold ("Pass on 1, 2, 3 and 10 = Adopt"): objective 1 passes, objectives 2 and 3 are Partial, objective 10 fails. So this is **not** a plain Adopt.

---

## 2. Scorecard

| # | Objective | Rating | One-line evidence |
|---|---|---|---|
| 1 | Truly headless | **Pass** | Create course, lesson, topics, users, enrolment, consume, progress, completion and stats all done via REST; the only UI-bound parts are the SCORM/H5P/video *players*, which Luminate would render itself [V]. |
| 2 | Multi-tenancy and isolation | **Partial** | Data isolation verified strong (separate DBs, JWT keys, DB roles, buckets; no cross-tenant read/write/delete). But tenants cannot be created via API (env + manual DB/bucket + container restart), and background jobs leak across tenants unless `REDIS_DB` is set per tenant [V]. |
| 3 | Assessment depth | **Partial** | 8 question types, auto and manual grading, attempts, resume, timers, SCORM tracking and xAPI all verified. Missing: aggregated gradebook, H5P authoring (hub blocked here), real-browser SCORM/H5P, score on quiz events [V]. |
| 4 | Content-model fit | **Partial** | Course = run, groups = cohorts, authors = lecturers, custom fields = session/term works. No academic calendar, no per-cohort dates, no custom-field filter; mapping table needed in Luminate [V]. |
| 5 | Auth and SSO | **Partial** | Passport JWT; JIT user creation + impersonation-minted tokens give password-less SSO [V]. But the only mechanism is unscoped: a narrow service account could impersonate the root admin and create admin users [V]. Mitigation untested. |
| 6 | Extensibility and API quality | **Partial** | Clean event model, custom fields at runtime, ordinary Composer extension (I built a webhook bridge in ~150 lines) [V]. No outbound webhooks, event-plumbing defects, 5+ error shapes, no rate limit/idempotency/versioning, wrong OpenAPI paths [V]. |
| 7 | Media and files | **Partial** | Video/audio/PDF/cover/resources upload via API to MinIO/S3 [V]. Public-URL delivery only (private buckets give 403), image-topic upload coupled to a public URL, video transcoding job never consumed in multi-domain mode [V]. |
| 8 | Free vs paid boundary | **Pass (provisional)** | All 50 `escolalms/*` packages are public; 23 exercised, none gated [V]. Vendor text: MIT, fees only for services [D]. The "$3k licence" is an unverified third-party claim [U]. |
| 9 | Operational footprint and health | **Partial** | ~1 GB RAM, 9 suites / 837 tests pass [V]. Laravel 9 EOL, 29 advisories (12 high), median package last-pushed 664 days ago, 3 active committers [V]. |
| 10 | Licence verification | **Fail** (against the brief's bar) | GPL-3.0 H5P core/editor are a hard dependency of `topic-types`; GPL-3.0 `trax2` (xAPI/cmi5) and `laravel-invoices` are removable; h5p-react, Admin and Front have no declared licence [V]. |

---

## 3. Evidence log

Base URL in all examples: `http://127.0.0.1:8080` with header `Host: api.localhost` (or `tenant-a.localhost` / `tenant-b.localhost`). `Authorization: Bearer <JWT>` omitted for brevity.

### S0. Setup [V] (`evidence/s00-setup.md`)
- `docker compose up -d --build` with local overrides: Caddy remapped to 8080/8443 (host 80/443 held by Windows); `bitnami/minio:latest` no longer exists on Docker Hub, used `bitnamilegacy/minio`; repo checked out with CRLF breaks `init.sh` on Windows (fixed with LF); source bind-mount removed. First build about 10 minutes; 12 containers.
- `GET /api/name` -> `Application Name: Wellms`. Swagger at `/api/documentation`; spec at `/docs?api-docs.json`: OpenAPI 3.0.0, title `EscolaLMS`, version `0.0.1`, **338 paths / 439 operations**.
- Admin auto-created at boot: `admin@escolalms.com` / value of `LARAVEL_INITIAL_USER_PASSWORD` (docs say `admin@escolasoft.com`, stale).

### S1. Headless course lifecycle [V] (`evidence/s01-s05-batch1.md`)
```
POST /api/auth/login {"email","password"}            -> {"success":true,"data":{"token":"<RS256 JWT>","expires_at":"..."}}
POST /api/admin/courses {"title":"CSC101 ...","status":"published"}    -> 201 id=1
POST /api/admin/lessons {"title":"Week 1","order":1,"course_id":1}    -> 201 id=1
POST /api/admin/topics  (form-urlencoded ONLY; JSON body ignored)
     title=Welcome&lesson_id=1&order=1&active=1&topicable_type=EscolaLms\TopicTypes\Models\TopicContent\RichText&value=<p>..   -> 201
POST /api/admin/users {"email","first_name","last_name","password","password_confirmation","roles":["student"],"verified":true,"return_url":"http://..."}
POST /api/admin/courses/1/access/add {"users":[4]}                     -> 200 (student then gets 200, was 403)
GET  /api/courses/1/program                                            -> lessons + topics + content
PATCH /api/courses/progress/1 {"progress":[{"topic_id":1,"status":1}]} -> status enum 0=incomplete 1=complete 2=in-progress
GET  /api/admin/stats/course/1 -> PeopleBought 1, PeopleFinished 1
```
Result: full lifecycle without UI. Quirks: topics are form-only; the skip-verification flag is `verified` (not `email_verified`, undocumented); `return_url` must be a full URL; status values are easy to get wrong (I did).

### S2. Assessment [V] (`evidence/s02-assessment.md`)
- Quiz = "GIFT quiz" topic (Moodle GIFT syntax). All 8 types accepted: multiple choice, multi-answer (partial credit), true/false, short answer, matching, numerical, essay, description.
```
PUT  /api/admin/gift-quizes/1 {"max_attempts":2,"max_execution_time":600,"min_pass_score":5,"counts_to_grade":true,"weight":2}
POST /api/admin/gift-questions {"topic_gift_quiz_id":1,"score":3,"value":"Pick the primes. {~%50%2 ~%50%3 ~%-100%4}"}
POST /api/quiz-attempts {"topic_gift_quiz_id":1}          -> 201 (questions delivered without answers)
POST /api/quiz-answers/all {"topic_gift_quiz_attempt_id":1,"answers":[{"topic_gift_question_id":2,"answer":{"multiple":["2","3"]}}]}
POST /api/quiz-attempts/1/end  -> {"result_score":9,"max_score":17,"result_percent":52.94,"is_passed":true}
PATCH /api/admin/quiz-answers/7 {"score":4,"feedback":"..."}  -> attempt re-scored to 13/17
GET  /api/admin/quiz-attempts?course_id=&user_id=&topic_gift_quiz_id=&page=&per_page=   (grade read, paginated)
```
Attempts honoured (max 2); re-POST while active resumes the same attempt; over the limit returns HTTP 200 with the *old ended attempt* (no error). Timer is in **minutes**; after `end_at` a queue job auto-ended the attempt and a late answer got 403. `is_passed` was true while an essay was still ungraded.
- **SCORM** (hand-built SCORM 2004 package): `POST /api/admin/scorm/parse|upload` (multipart `zip`) -> `scorm_2004`, 1 SCO; attach as `ScormSco` topic; `GET /api/scorm/show/{uuid}` (launch JSON), `/api/scorm/play/{uuid}` (Wellms-served HTML player); `POST /api/scorm/track/{uuid} {"cmi":{"cmi.completion_status":"completed","cmi.score.raw":"90",...}}` then `GET /api/scorm/track/{scoId}/cmi.score.raw` -> `90`. Students lack `scorm_track-*` permissions by default (403 until granted via roles API). SCORM completion did not complete the topic.
- **xAPI**: needs `db:seed --class="EscolaLms\Lrs\Database\Seeders\LrsSeeder"` first. `GET /api/cmi5/courses/1` -> `{"endpoint":".../trax/api/<uuid>/xapi/std",...}`; `POST <endpoint>/statements` -> `["<statement-uuid>"]`; read back via `GET <endpoint>/statements` and `GET /api/admin/cmi5/statements`.
- **H5P**: `h5p:seed` points at a dead URL and hub library install installed nothing (hub not synced), so I used Wellms' own fixture: `POST /api/admin/hh5p/content/upload` (multipart `h5p_file`) -> content id 1; H5P topic; player settings at `GET /api/hh5p/content/{uuid}` (**public, no auth**); result capture `POST /api/courses/progress/{topic_id}/h5p {"event","data"}` stored in `h5p_user_progress` (no REST read endpoint found). Browser rendering [U].
- No aggregated gradebook; source references `pcg-grades` (not installed) for that [U].

### S3. Multi-tenancy [V] (`evidence/s03-s04-batch3.md`, `evidence/s06-s07-batch4.md`)
- Provisioning: no API; per tenant create Postgres DB+role and bucket, add env vars (`MULTI_DOMAINS=...`, `<DOMAIN_UPPER>_APP_NAME`, `_DB_*`, `_AWS_*`, `_INITIAL_USER_*`; values with spaces need inner quotes) and recreate the single `api` container; `init_multidomains.sh` migrates (152 tables) and generates app key + Passport keys per tenant. The `multidomain-tool` the docs mention is not in the repo.
- Two tenants with deliberately colliding ids (both admins `sub=1`, both courses id 1):
```
A's admin token -> B's host: GET /api/profile/me, /api/admin/courses, /api/admin/users, /api/admin/courses/1  -> 401
A's token -> B: POST /api/admin/courses, DELETE /api/admin/courses/1 -> 401, 401
Postgres: role tenant_a on tenant_b / default DB -> "permission denied for table users"
B's key pair overwritten with A's (simulated shared JWT keys): A's token still 401 on B (Passport also checks the token row in the tenant DB)
```
- Per-tenant config works: `POST /api/admin/config` set `escola_auth.registration=disabled` and token lifetime 60 min on A only (B stayed enabled / 300 s); per-tenant settings/branding via `/api/admin/settings`; separate buckets (`tenant-a/avatars/1/px.png`).
- **Cross-tenant job leak [V]**: queue keys are not tenant-prefixed and the multi-domain workers loop over all tenants. With workers stopped, tenant A queued its timed-quiz job `MarkAttemptAsEnded(3)`; running a worker for tenant B **executed A's job against B's database** and ended B's unrelated attempt 3 (`end_at` moved from 2026-10-09 00:44 to 14:45:47). **Mitigation proven**: per-tenant `REDIS_DB` (A=2, B=3); repeat run left B untouched and A's jobs still ran. Not in the docs [V].
- Other: unknown hostnames fall through to the default tenant; tenants inherited the shared MinIO root key unless per-tenant keys are issued; enabling multi-domain mode silently removes the default site's queue worker and scheduler; one shared php-fpm pool and Redis.

### S4. Content-model mapping [V]
`CSC101`, `2025/2026 Semester 1`, two programme cohorts, two lecturers:
```
POST /api/admin/model-fields {"name":"academic_term","type":"varchar","class_type":"EscolaLms\Courses\Models\Course","rules":"[\"nullable\",\"string\"]"}
POST /api/admin/user-groups/ {"name":"2025/2026 S1"} ; {"name":"BSc CS Y1 (2025/26)","parent_id":1}
POST /api/admin/courses {"title":"CSC101 ...","authors":[8,9],"active_from":"...","active_to":"...",
       "fields":{"academic_session":"2025/2026","academic_term":"Semester 1","course_code":"CSC101","sis_course_run_id":"CSC101-2025-S1"}}
POST /api/admin/courses/2/access/add {"groups":[2,3]}
```
Works: both cohorts see the course; the cross-listed student sees it once; removing a learner from groups revokes access immediately and correctly (DB-checked); both lecturers can edit their course and get 403 on others; group-only learners get completion recorded. Does not fit: active window is course-wide (enforced both ends, same "not activated yet" message); no per-cohort dates (`deadline`/`end_date` accepted with 200 but ignored); no custom-field filter on the course list; reuse across terms is `clone` (async, not completed here); no per-cohort stats. Defects: tutors can list **all** users' names/emails but get 403 on any single user; foreign-course stats give 500; group + direct enrolment double-lists a learner in stats.

### S5. Auth and SSO [V]
- Passport; RS256 JWT, `sub`=user id, `scopes:[]`, default lifetime 300 s (`escola_auth.token_expiration_minutes`, runtime-settable, verified 3600 s on one tenant); `GET /api/auth/refresh` works.
- SSO bridge: `POST /api/admin/auth/impersonate {"user_id":N}` returns a normal user token without a password (the OpenAPI wrongly lists `/api/auth/impersonate`, which 404s). JIT: `POST /api/admin/users` with a random password and `verified:true`.
- Service account `luminate-service` with only `user_create,user_read,user_list,user_impersonate,course_list,course_read,course_update`: created a learner, enrolled them and minted their token; 403 on `POST /api/admin/courses`. **But it could also impersonate user 1 (root admin) and create a user with role `admin`** (test user deleted). Permissions do not scope by target role.
- Not tested: OIDC/external IdP delegation, password grant, token revocation on logout/deactivation, scoped tokens [U].

### S6. Extensibility, events, API quality [V] (`evidence/s06-s07-batch4.md`)
- 54 events bindable to Email, SMS and PDF templates; **no HTTP/webhook channel**. I built `custom/luminate-webhooks` (3 PHP files, a service provider, a synchronous listener and a queued delivery job), installed with a Composer path repository and auto-discovered. Signed headers `X-Wellms-Event`, `X-Wellms-Delivery`, `X-Wellms-Signature: sha256=<hmac>`. Delivered in 2 to 5 s: `enrolment.created`, `topic.completed`, `quiz.grade.ready`, `quiz.attempt.finished`, `course.completed`, `enrolment.removed`. With the receiver forced to fail twice: retries at +5 s and +21 s, same delivery id.
- Defects found: queued listeners on `Course*` events receive an uninitialised object (private props on an abstract parent + `SerializesModels`); quiz events carry `result_score: null` (final score only via API: 4/4, 100%); event order not guaranteed; **unenrolling emits `CourseFinished` unconditionally**; failed jobs are never recorded (`failed_jobs.uuid` NOT NULL vs legacy driver).
- Lists: Laravel paginator `{success,data,meta,message}`; **no page-size cap** (`per_page=100000` returned everything; `per_page=abc` -> 500); unknown filters silently ignored; CSV user import (60 users, 8.9 s) creates users with **no role who cannot log in**; bulk enrol 50 users in one call (1.5 s), idempotent on repeat, all-or-nothing on a bad id.
- `Idempotency-Key` ignored (two identical POSTs created two courses); at least five error body shapes (`{message}`, `{error}`, `{message,success}`, `{message,errors}`, raw exception dumps, plus a stray HTTP 423); 15 rapid bad logins all 422, **no rate limit**; no `/v1`; CORS `*`. (Trace leakage is due to `APP_DEBUG=true` in the reference compose; production setting not tested.)

### S7. Media [V]
- MinIO/S3, bucket per tenant, keys `course/{courseId}/topic/{topicId}/{type}/{random}.ext`. Multipart upload of Video (mp4, ffprobe extracted 320x240, 4000 ms), Audio, PDF (page count computed), course `image`/`poster`, topic resources and the file manager all succeeded; limits 2 GB, no chunked upload found [U].
- Image topic: HTTP 500 (`getimagesize(http://storage.localhost/...)`: the server re-reads its own upload through the public URL, unreachable from the API container).
- URLs are public object URLs (`http://storage.localhost/<bucket>/<key>`); no presigned/temporary URL support found in vendor code. Buckets are private by default (anonymous GET 403); public bucket gave 200, correct MIME and **Range 206**.
- Video transcoding: `ProcessVideo` is queued on `redis-long-job`/`queue-long-job`, which the multi-domain workers do not consume; HLS never produced (state stuck at `queue`).

### S8. Free vs paid [V]/[D] (`evidence/s08-modules.md`, `evidence/s08-s10-batch5.md`)
See section 4.

### S9. Ops and health [V] (`evidence/s09-*.txt`, `evidence/s09-composer-audit.json`)
- 12 containers; memory at rest api 457 MiB, minio 268, postgres 95 (about 1 GB total); images api 2.39 GB, reportbro 1.77 GB, admin 755 MB, demo 774 MB. First build ~10 min; each tenant add = restart.
- `phpunit`: tests ship inside `vendor/escolalms/*/tests`; need dev deps, a migrated DB, Passport keys copied into the Testbench skeleton and a test-fixture migration; **`phpunit.xml` hard-codes `DB_DATABASE=default`** (the app's own DB in the stock compose). With a throwaway DB: **9 of 46 suites, 837 tests, 4,149 assertions, 0 failures** (topic-type-gift 200, courses 205, headless-h5p 158, auth 124, settings 52, course-access 30, model-fields 26, scorm 25, lrs 17). 37 suites not run [U]. The suites did not catch the multi-tenant, queue or event defects above.
- API repo: created 2021-01-26, last push 2026-07-29 (71 days), 19 stars, releases 1.0.50 to 1.0.54 (2026-05-28 to 2026-07-29), 0 open issues/PRs, 76 closed, 15 contributors (top 42%), 3 authors in the last 100 commits (mostly dependency updates).
- Packages (35 of 50 measured): median 664 days since last push; 29 over a year; 17 over two years; 1 within 90 days; most are `0.x`.
- Platform: **Laravel 9.52.21 is end-of-life** (Laravel's support table lists v10 security fixes ending 2025-02-04). `composer audit`: **29 advisories** (12 high, 11 medium, 5 low, 1 unrated): `league/commonmark` 12, `dompdf` 6, `laravel/framework` 5 (ranges include 9.x, incl. a high CRLF issue), `composer/composer` 2, one each for `maatwebsite/excel` (high), `firebase/php-jwt`, `league/flysystem`, `phpseclib`. Exploitability here untested.

### S10. Licence scan [V] (`evidence/s08-s10-batch5.md`)
262 PHP packages: MIT 224, Apache-2.0 16, BSD-3 13, GPL-3.0-only 3, LGPL 5, GPL-3.0 2, GPL-2.0-only 2 (nette, dual-licensed with BSD-3), GPL-3.0-or-later 1. Detail in section 4.

---

## 4. Free vs paid map, and licence map

**Cost position.** [V] Every `escolalms/*` package is public on GitHub and installed with no credentials; I used 23 of 50 and nothing was gated. [D] The vendor's licence page says Wellms is MIT and that it charges fees "only for additional services" (implementation, workshops/UX, custom features, adaptation, support). [U] The brief's "~$3k paid tier" appears only in a third-party directory snippet and contradicts that. [U] An "educational licence" page linked from wellms.io returns 404. [U] A gradebook module referenced in source (`pcg-grades`) is not in the public set. Treat any "paid extension" as a *service or private module*, not a lock on the public packages, and confirm in writing.

| Group | Packages (installed version in `evidence/s08-modules.md`) | Licence | Cost / flag |
|---|---|---|---|
| Core platform | core, auth, permissions, settings, courses, course-access, courses-import-export, files, images, model-fields, translations, pages, categories, tags, dictionaries | MIT | Free, public. Used: core, auth, permissions, settings, courses, course-access, files, images, model-fields |
| Content types and assessment | topic-types, topic-type-gift, topic-type-project, scorm, video, youtube, questionnaire | MIT (scorm: Apache-2.0) | Free. **topic-types hard-requires headless-h5p (GPL)** |
| H5P | **headless-h5p** (+ `h5p/h5p-core`, `h5p/h5p-editor`) | MIT wrapper, **GPL-3.0** libraries | Free but **copyleft, not removable without forking topic-types** |
| xAPI / cmi5 | **lrs** (+ `trax2/framework`), **cmi5** | MIT wrapper, **GPL-3.0-or-later** library | Free but copyleft; removable cleanly |
| Invoices | **invoices** (+ `laraveldaily/laravel-invoices`) | MIT wrapper, **GPL-3.0-only** library | Free but copyleft; removable cleanly (nothing requires it) |
| Reporting and templates | reports, templates, templates-email, templates-pdf, templates-sms, notifications, bulk-notifications | MIT | Free (LGPL libs: dompdf, htmlpurifier) |
| Commerce | cart, payments, przelewy24-php, vouchers | MIT | Free, not exercised |
| Live and collaboration | webinar, jitsi, mattermost, pencil-spaces, consultations, consultation-access, stationary-events | MIT | Free, not exercised |
| Misc | bookmarks_notes, tasks, recommender, mailerlite, csv-users, assign-without-account | MIT | Free; only csv-users partly exercised |
| JS (npm) | `@escolalms/sdk` v1.0.0, `@escolalms/components` v0.0.165 | MIT | Free [V] (metadata only) |
| JS (npm) | `@escolalms/h5p-react` v0.2.21, `@escolalms/ts-models` v0.0.35 | **none declared**, ISC | Flag: h5p-react has no licence |
| Apps | `escolalms/admin`, `escolalms/demo` (Docker images; repos Admin, Front) | **no licence declared** | Flag; not needed if Luminate owns the UI |
| Application | `EscolaLMS/API` repo | Apache-2.0 (website and docs say MIT) | Resolve the inconsistency in writing |

**Copyleft, precisely** (non-dual-licensed): `h5p/h5p-core` and `h5p/h5p-editor` 1.24.4 (GPL-3.0) <- `headless-h5p` <- **`topic-types`** <- `topic-type-gift`, `topic-type-project`, `video`, `recommender`; `trax2/framework` 2.0.4 (GPL-3.0-or-later) <- `lrs` <- `cmi5`; `laraveldaily/laravel-invoices` 3.3.1 (GPL-3.0-only) <- `invoices`. LGPL (dynamic use; notice/relink obligations): dompdf 2.0.8, php-font-lib, php-svg-lib, htmlpurifier, smalot/pdfparser.

**What it means (analysis, not legal advice).** GPL-3.0 (not AGPL) obliges on *distribution*, not on running a service. Luminate talking to a Wellms service over HTTP is two separate programs. Exposure appears if Luminate **ships** Wellms (standalone installs, on-prem, bundled installers), or if Luminate code is placed **inside** Wellms' PHP process (a custom module like my webhook bridge is then part of the combined work). Decision for counsel: hosted-only vs shipped; and whether to fork `topic-types` to drop the H5P hard dependency.

---

## 5. Gap list: what Luminate must build or fix

Priority: **Must** = needed before any production use; **Should** = needed for a good product; **Could** = later. Size is my relative judgment (S/M/L), not an estimate from data.

| # | Gap | Evidence | Priority | Size |
|---|---|---|---|---|
| 1 | **Server-side proxy / anti-corruption layer** in the .NET API: hides Wellms from browsers, normalises the 5+ error shapes, caps page size, adds rate limiting and idempotency keys, corrects the OpenAPI paths, generates a typed client | S5, S6 | Must | M |
| 2 | **Auth bridge** hardening: Wellms admin credential held only in the .NET backend; network-restrict `/api/admin/*`; allow-list impersonation targets (never admin) in the proxy; short-lived tokens; audit log; test token revocation and deactivation | S5 (escalation) | Must | M |
| 3 | **Tenant provisioning automation** (IaC): DB+role, bucket, per-tenant S3 keys, per-tenant `REDIS_DB` or separate Redis, env, restart; ingress host allow-list; health checks; per-tenant worker/scheduler config | S3 | Must | L |
| 4 | **Background-job safety**: enforce per-tenant queues, fix `failed_jobs` recording, run workers for the video long-job queue, monitor queue depth/failures | S3, S6, S7 | Must | M |
| 5 | **Event/webhook service**: productionise the bridge (baked into the image, tenant-aware config, order tolerance, fetch final score after `quiz.grade.ready`, ignore the spurious `course.completed` on unenrol, dead-letter handling) | S6 | Must | M |
| 6 | **Gradebook / continuous-assessment layer**: aggregate attempts with weights (`counts_to_grade`, `weight`), CA export to PortalAcademy; Wellms has per-attempt grades only | S2 | Must (if CA needed) | M to L |
| 7 | **Platform upgrade and patching**: Laravel 9 to a supported version, 29 advisories, ~50 low-cadence `0.x` packages; or a funded vendor commitment | S9 | Must | L |
| 8 | **GPL decision**: legal review; if shipping, fork `topic-types` to remove the H5P hard dependency and drop `lrs/cmi5/invoices` | S10 | Must | M to L |
| 9 | **Role/permission hardening**: tutor user-list PII leak, foreign-course stats 500, per-role permission sets via roles API (student lacks SCORM tracking by default) | S4, S2 | Should | S to M |
| 10 | **Academic model in Luminate**: sessions/terms/calendar, per-cohort dates, `sis_course_run_id <-> wellms_course_id` mapping table, term rollover (clone + reset), custom-field filtering done in Luminate's DB | S4 | Should | M |
| 11 | **Protected media delivery**: authenticated proxy or signed-URL layer in front of private buckets; fix image-topic coupling; transcoding pipeline; large/resumable upload | S7 | Should | M |
| 12 | **Players in the front end**: SCORM runtime, H5P player/editor (GPL libraries in the browser), video/HLS player, xAPI client | S2, S7 | Should | M |
| 13 | **Onboarding paths**: CSV import that assigns roles and verifies, bulk enrol conventions (use groups *or* direct users, not both), unenrol semantics | S4, S6 | Should | S |
| 14 | **Observability and ops**: logs, metrics, backups, upgrade runbooks, non-debug production config, rate-limit at the edge | S6, S9 | Should | M |
| 15 | **Test harness**: contract tests for the integration surface (the vendor's suites missed every defect found here); run the other 37 vendor suites | S9 | Should | S to M |

Not a gap, already provided: course/lesson/topic model, enrolment (users and groups), 8 quiz types with server scoring and manual grading, attempts/timers, SCORM tracking, xAPI store, H5P content and results, progress/completion/stats, templated email/PDF (certificates are [D] via `templates-pdf` on `CourseFinished`, not exercised), media storage, per-tenant settings.

---

## 6. Integration sketch: Wellms as a service behind Luminate's .NET

```
 Browser (Luminate React)                      PortalAcademy (SIS, system of record)
        |                                             ^   |  registrations, programmes, staff
        v                                             |   v
 +-------------------------+   events / CA scores   +---------------------------+
 | Luminate .NET API (BFF) |<---------------------->| PortalAcademy APIs        |
 |  - own users/sessions   |                        |  ResultSheet workflow      |
 |  - mapping DB           |                        |  (official results only)   |
 |  - gradebook (CA)       |                        +---------------------------+
 |  - webhook receiver     |
 +------------+------------+
              | private network only (service account, allow-listed calls)
              v
 +-------------------------------+      +-------------------+
 | Wellms API (Laravel, php-fpm) |----->| PostgreSQL / tenant|
 |  + luminate-webhooks module   |----->| Redis (REDIS_DB/ten)|
 |  + queue workers (all queues) |----->| MinIO/S3 (key/tenant)|
 +-------------------------------+      +-------------------+
```
**Auth bridge.** Luminate authenticates the person (its own identity, or SSO from PortalAcademy). For first use the .NET backend calls `POST /api/admin/users` with a random password and `verified:true` (JIT), stores the returned Wellms user id, then for each session calls `POST /api/admin/auth/impersonate` to mint a short-lived JWT. Preferred: the .NET proxy holds that token and makes the Wellms calls itself, so the token never reaches the browser; only the media and player endpoints that must be reached directly get a short-lived token. The service account is restricted by network and by an allow-list in the proxy (never impersonate admins; never create admin users), because Wellms itself does not scope that permission. No second password exists for learners. [V mechanism; hardening untested]

**Data mapping (where `(Course x Session x Term)` + cohorts live).** The authority is PortalAcademy. Luminate keeps a mapping table: `course_offering_id (Course x AcademicSession x AcademicTerm) <-> wellms_course_id`, `programme_cohort_id <-> wellms_group_id`, `person_id <-> wellms_user_id`. A Wellms course is **one run**; the run's identity is also stored in Wellms custom fields (`course_code`, `academic_session`, `academic_term`, `sis_course_run_id`) so Wellms stays self-describing. Cohorts are user groups under a per-session parent group; the offering is granted to the groups; lecturers are course authors with the `tutor` role. Use **groups or direct enrolment, not both** (double listing in stats). Term start/end are Wellms `active_from/active_to` (one window per course); per-cohort dates, calendars and rollover live in Luminate; next term = clone then reset fields. [V]

**Tenancy.** For each PortalAcademy school with isolation requirements, one Wellms tenant (own DB, JWT keys, bucket with its own keys, `REDIS_DB`), created by automation, behind a host allow-list. One tenant per many schools relies on groups and is not isolation. Standalone Luminate is the same bridge with a tenant created for the customer. [V isolation; automation to build]

**Grades and the hard boundary.** Wellms emits `quiz.grade.ready` (webhook bridge); Luminate fetches `GET /api/admin/quiz-attempts/{id}` for the final score (the event does not carry it), stores it in its own gradebook as a **continuous-assessment component** keyed by `wellms_attempt_id` (idempotent), applies weights, and sends it to PortalAcademy as a *formative/CA input* labelled as LMS-sourced. **Wellms and Luminate never write official results**: PortalAcademy's `ResultSheet` (Draft, Dept, Faculty, Senate, Published) remains the only source of official grades. Essay and manual grades re-emit after the lecturer finishes. [V events and scores; CA export to build]

**Events in.** Enrolment, completion and grade events arrive signed and de-duplicated by delivery id; the receiver tolerates reordering and ignores `course.completed` that coincides with `enrolment.removed`; completion truth comes from `GET /api/courses/progress`.

**Deployment shape.** Run Wellms as a **hosted, separate service** (containers, private network). This is also the lowest-risk reading of the GPL dependencies. Shipping it to customers needs the legal gate first.

---

## 7. Risks and unknowns

**Top risks (Verified)**
1. GPL-3.0 H5P libraries are a hard dependency of the core topic types (S10).
2. Laravel 9 end-of-life with 29 dependency advisories; no evidence yet of an upgrade plan (S9).
3. Cross-tenant job execution in default multi-domain mode (S3/S6); mitigation exists but is undocumented and limited by Redis DB count at scale.
4. Unscoped impersonation: a service credential can become admin (S5).
5. Small maintainer base: 3 active committers, median module quiet for ~22 months (S9).
6. Silent operational failures: failed jobs unrecorded, video queue unconsumed, default site loses workers when tenants are added (S3/S6/S7).
7. Event-plumbing defects (queued `Course*` listeners, `CourseFinished` on unenrol, scores missing from events) (S6).

**Unknowns that need follow-up** (full list: `evidence/untested-and-unknowns.md`)
- Commercial: whether the "$3k licence" exists; educational licence terms (404); availability/price of `pcg-grades` and other private modules; support/SLA for an embedder; MIT vs Apache-2.0 vs no-licence inconsistencies. **Ask `biuro@escola.pl` in writing.**
- Legal: counsel's reading of the GPL exposure for hosted vs shipped; feasibility and upstream acceptance of forking `topic-types`.
- Technical not exercised: real-browser SCORM and H5P (authoring and player), H5P hub sync, cmi5 upload, HLS transcoding output, signed/protected media, chunked uploads, OIDC/password-grant delegation, token revocation, `APP_DEBUG=false` behaviour, rate limiting beyond 15 attempts, hot tenant creation, course clone result, per-learner deadlines, question banks/categories, GIFT import/export, project-topic grading, the 37 other vendor test suites, the SDK, load/concurrency and sizing, the 15 packages whose repo activity was not measured.
- Environment limits: single dev node on Windows/Docker with local overrides (ports, MinIO image, LF endings, no source mount); a clean production install may differ. The webhook package and tenant env tweaks I made exist only inside the running container.

**Recommended next steps (before committing)**
1. Send the commercial and support questions to Escola (including a Laravel upgrade roadmap) and brief counsel on the GPL path with sections 4 and 6.
2. Run a 2-week hardening proof of concept covering gaps 1 to 5 and 8: proxy + scoped auth bridge, tenant automation with per-tenant Redis/S3 keys, queue/worker correctness, productionised webhook module, and a decision on forking `topic-types`.
3. Decide go/no-go on the verdict gates; if "no-go", compare against the alternatives in the brief with the same probes, now that the cost of "feature-rich but unmaintained-at-the-edges" is measured.

---

## Appendix: reproducibility and corrections

- Run it: `docker compose -f api/docker-compose.yml -f docker-compose.override.yml [-f docker-compose.tenants.yml] --project-directory api -p wellms up -d --build`; scripts in `scripts/` (`wl.py` helper, `s0x_*.py`), receiver `scripts/webhook_receiver.py`, custom module `custom/luminate-webhooks/`.
- Corrections made during the evaluation: package count is **50** (not 264); `/api/auth/impersonate` and the `hh5p` admin paths in the OpenAPI are wrong (real: `/api/admin/auth/impersonate`, `/api/admin/hh5p/*`); my early "queued listener works" assumption was wrong (platform defect); a path typo once wrote a file outside the eval folder and was moved and the stray directory removed.
- My own set-up errors that looked like product failures and were ruled out: wrong progress status value, missing `return_url`, bash path conversion, a `pkill` that killed its own shell, a seeded admin user in the test DB, a missing test-fixture migration.
