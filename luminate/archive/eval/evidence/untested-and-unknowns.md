# Not yet tested / Unknowns register
Status as of end of Batch 2. Each item is labelled **Unknown** (not exercised, no evidence either way) or **Documented** (claimed by docs/source only, not called). Items move to the report's "Risks & unknowns" section if still open at the end. Update this file as items are closed.

## A. Gaps inside scenarios already run

### Batch 1: S1 course lifecycle
| # | Item | Label | Why it matters | How to close |
|---|---|---|---|---|
| A1 | Updating an existing user via API (no PUT/PATCH for `/api/admin/users/{id}` in the OpenAPI spec; real routes not confirmed) | Unknown | Luminate must sync name/email/status changes from PortalAcademy | Read package `routes.php`; try `PUT/PATCH` on a user |
| A2 | Course/lesson/topic update, reorder (`POST /api/admin/courses/sort`) and delete | Unknown | Content authoring from Luminate's own UI | Call each; check cascades |
| A3 | File-backed topic types (Video, Audio, Image, PDF) and topic resources upload | Unknown | Needed for real course content (also S7) | Fold into S7 |
| A4 | Course publication semantics (`status`, `active_from/active_to`, `findable`, `public`) and whether an unpublished course is hidden from enrolled students | Unknown | Release timing per academic term | Toggle and re-read as student |
| A5 | Enrolment by group (`access/add` with `groups`), access removal/set, deadlines (`course_user.deadline/end_date` columns exist) | Unknown / Documented | Cohort-based enrolment, term end dates | Fold into S4 |
| A6 | `GET /api/courses/progress/paginated` and an instructor/admin API for another learner's progress (only the stats endpoint was seen) | Unknown | Lecturer dashboards in Luminate | Probe admin endpoints and `/api/admin/stats/*` |
| A7 | `PUT /api/courses/progress/{topic_id}/ping` effect on `seconds`/time-spent (call returned ok but `seconds` stayed null) | Unknown | Time-on-task reporting | Send ping repeatedly, re-read progress |
| A8 | Behaviour of topics with `can_skip`, ordering rules, prerequisites / sequential locking | Documented (fields exist) | Mandatory-order learning paths | Test as student |
| A9 | Bulk operations and idempotency (e.g. repeated `access/add`, duplicate user create) | Unknown | Safe retries from a .NET integration | Fold into S6 |

### Batch 1: S5 auth/SSO
| # | Item | Label | Why it matters | How to close |
|---|---|---|---|---|
| B1 | Changing token lifetime at runtime via the settings/config API (`escola_auth.token_expiration_minutes` is "administrable") | Documented (source) | SSO session length | Find the admin config endpoint and set it |
| B2 | Token revocation: `POST /api/auth/logout`, whether old JWTs are rejected after logout / user deactivation / password change | Unknown | Security of the SSO bridge | Logout then reuse token; deactivate user then reuse token |
| B3 | External IdP / OIDC delegation, Passport password grant and `client_credentials` grants (two Passport clients exist: Personal Access, Password Grant) | Unknown | Alternative to impersonation that scopes tokens | Call `/oauth/token`; check routes |
| B4 | Social login providers (`/api/auth/social/*`) | Documented | Not likely needed | Skip unless asked |
| B5 | Token scopes (JWTs carry `scopes: []`) and whether a scoped/limited token can be minted | Unknown | Mitigating the impersonation privilege escalation | Investigate Passport scopes in config |
| B6 | Fix or compensating control for the finding "service account with `user_impersonate` can impersonate admin and assign `admin` role" (no scoping by target role) | Unknown (mitigation untested) | Core security gap for Luminate's bridge | Prototype: network-restricted admin routes, custom policy, or proxy-side allow-list |
| B7 | Audit trail: is the `Impersonate` event logged/queryable? | Unknown | Forensics | Check event listeners/activity log |
| B8 | Registration and email flows: `register`, `password/forgot|reset`, email verification, disabling them for SSO-only users | Documented | Preventing a second password path | Test or confirm disable flag |
| B9 | Role mapping at scale: mapping PortalAcademy roles (lecturer, HOD, student) to Wellms roles/permissions; per-course author permissions (`course_*_authored`) | Unknown | Authorisation model | Part of S4 |
| B10 | User deletion/GDPR delete flows (`DELETE /api/admin/users/{id}` worked once; profile delete-init flow untested) | Partly Verified | Data lifecycle | Test cascade on progress/attempts |

### Batch 2: S2 assessment
| # | Item | Label | Why it matters | How to close |
|---|---|---|---|---|
| C1 | Question banks / categories: `category_id` on questions, category CRUD, random-from-bank selection | Documented (field only) | Reusable assessment content | Find category endpoints; test |
| C2 | `randomize_order` and `min_pass_score` semantics beyond the single run (matching shuffle noted in source) | Unknown | Exam integrity | Re-run with flag on |
| C3 | GIFT import/export endpoints (`/api/admin/gift-questions/import|export`) | Unknown | Migrating question sets from Moodle | Round-trip a GIFT file |
| C4 | Weighted aggregate course grade (`counts_to_grade`, `weight`) and a gradebook/journal; the `pcg-grades` module referenced by `QuizAttemptJournalGradeReadyEvent` is not installed | Unknown (availability and licence) | CA scores feeding PortalAcademy | Ask vendor; check Packagist/private repos; else build in Luminate |
| C5 | Whether the pass flag (`is_passed`) ever recomputes correctly once essays are graded; in the run it was `true` before grading | Unknown | Pass/fail integrity | Re-test with a quiz where partial score is below the pass mark |
| C6 | Project topic (`topic-type-project`) with solution grading (`PATCH /api/admin/topic-project-solutions/{id}/grade`) | Unknown | Assignments/rubric-style work | Create project topic, submit solution, grade |
| C7 | Questionnaire / survey module | Unknown | Course feedback | Optional |
| C8 | SCORM in a real browser: SCO JavaScript API against the Wellms player (`/api/scorm/play/{uuid}`), SCORM 1.2 packages, multi-SCO sequencing, bookmarking/suspend data | Unknown | Real SCORM compatibility | Open the player in the browser tool with a real package |
| C9 | SCORM completion → topic/course completion propagation (topic stayed incomplete after SCORM completed) | Verified gap, cause Unknown | Completion integrity | Check listeners; test with SCORM as course-level `scorm_sco_id` |
| C10 | cmi5 package upload (`POST /api/admin/cmi5`), `GET /api/cmi5/player/{auId}` | Unknown | cmi5 content | Needs a cmi5 AU package |
| C11 | xAPI scope: can a learner read other learners' statements; access-key management; multi-tenant LRS isolation | Unknown | Privacy | Query as second student |
| C12 | xAPI emission by Wellms itself (does the platform auto-emit statements for course events, or only store client-sent ones?) | Unknown | Analytics without extra work | Perform actions then list statements |
| C13 | H5P Hub installation (content-type cache empty, hub sync failed in this environment) and the H5P editor/authoring flow | Unknown (blocked by environment) | Authoring H5P inside Luminate | Retry with working hub access; check outbound network from the container |
| C14 | H5P in the browser: player rendering, scoring events flowing to `/h5p` endpoint, interactive video etc. | Documented | Real H5P use | Browser test via Wellms demo front or custom page |
| C15 | Read API for stored H5P results (no REST endpoint found; data only in `h5p_user_progress`) | Unknown | Grades from H5P | Search admin/report endpoints |
| C16 | Authorization of public H5P player settings endpoint (`GET /api/hh5p/content/{uuid}` unauthenticated) and of media on the public MinIO bucket | Verified public; impact Unknown | Content protection for paid/private courses | Test whether private course media URLs are guessable |

## B. Scenarios not started yet (planned batches)
| Batch | Scenario | Status | Objectives |
|---|---|---|---|
| 3 | S3 multi-tenant isolation: two tenants via `laravel-multidomain`, API provisioning, cross-tenant read/write attempts (including ID guessing), per-tenant config/branding | Not started. Only Documented in `docs/multidomain.md` (per-domain DB, bucket, JWT keys via env vars; provisioning by a CLI tool and env/compose edits, i.e. likely not API-provisionable) | 2 |
| 3 | S4 content-model mapping: `CSC101 · 2025/2026 · Semester 1`, two cohorts as groups, two lecturers, cross-listing | Not started. Source hints at `course_group` pivot and multiple `authors` on a course | 4 |
| 4 | S6 extensibility/events: webhooks, event hooks, pagination/filter/bulk, error semantics, versioning, SDK, custom module/field path (`model-fields`) | Not started. Source shows Laravel events (e.g. `CourseFinished`, `TopicFinished`, `QuizAttemptFinishedEvent`) and a notifications package; outbound webhook module existence Unknown | 6 |
| 4 | S7 media: video/file upload, MinIO/S3 backend, presigned URLs, video processing/streaming | Not started. Documented: S3-compatible storage via MinIO; video package present | 7 |
| 5 | S8 paid-boundary audit: which of the 264 `escolalms/*` packages are free vs paid (the stock compose installs 264; whether the ~$3k licence unlocks more modules or support only) | Not started. Nothing verified about pricing or gating | 8 |
| 5 | S9 ops/health: `phpunit` run, resource footprint, release cadence, commit history, open issues, contributors/bus factor, upgrade path (note: Laravel 9.52 is end-of-life) | Not started. Observed only: 12 containers, ~10 min first build | 9 |
| 5 | S10 licence scan completion: npm/`@escolalms/*` JS packages, per-package licence text verification, legal read of the GPL findings | Partly done (composer.lock scan only) | 10 |

## C. Environment and method limitations (apply to every finding)
- Single-node dev stack on Windows/Docker, empty database, fake data, no load or concurrency testing; performance and scalability are Unknown.
- No testing of the bundled admin UI or demo front-end (deliberate); the browser-side behaviour of SCORM/H5P/video players was not exercised.
- The running instance is the repo's `develop`-style Docker image built on 2026-10-08; version/commit pinning was not recorded in the report yet (record `git rev-parse HEAD` of `api/` and composer.lock hash).
- Local overrides changed the stock setup (ports, MinIO image swap, source bind mount removed, LF line endings, one manual `chown`); a clean production-style install may behave differently.
- I ran `escolalms:admin`, `h5p:seed` and the LRS seeder with `docker compose exec` as root; this created root-owned files once. Treat any permission-related errors as environment noise, not product behaviour.
- Cleanups done: temporary test user "evil@example.test" deleted. Remaining fake data: users 1, 4, 5 (service), 6 (JIT learner), role `luminate-service`, course 1 with 6 topics, quizzes, SCORM package 1, H5P content 1.

## D. Update after Batch 3 (S3 + S4)
**Closed**
- B1 token lifetime change at runtime: **Verified** (`POST /api/admin/config`, per-tenant: A 3600s vs B 300s).
- S3 isolation across tenants (read, write, delete, ID collision, shared-key simulation): **Verified** (see `s03-s04-batch3.md`).
- S4 CSC101 mapping with two groups and two lecturers: **Verified** (partial fit).
- Commit pin recorded: api repo `3dd34ebe9378c5d8a58c2333697c2606293d627b`, composer.lock sha256 prefix `330e781a34d96af2`.

**New / still open**
| # | Item | Label |
|---|---|---|
| D1 | Course clone (`GET /api/admin/courses/{id}/clone`) result: job never ran on the default tenant because multi-domain mode leaves it without a queue worker; retry on a tenant domain | Unknown |
| D2 | Why `access/add` ignores `deadline`/`end_date`; whether an update endpoint or another package sets per-learner deadlines | Unknown |
| D3 | Tenant provisioning without restart (hot `domain:add`), per-tenant MinIO credentials and bucket policy as the docs tool generates them, and the missing `multidomain-tool` | Unknown / Documented |
| D4 | Behaviour under a php-fpm restart for the shared-JWT-key simulation, and with Passport token tables identical across tenants | Unknown |
| D5 | Whether a Caddy host allow-list fully closes the unknown-host fallback to the default tenant | Unknown (likely, not tested) |
| D6 | Fix for tutor over-exposure on `GET /api/admin/users` and the 500 on foreign-course stats (policy patch vs proxy) | Unknown |
| D7 | Noisy-neighbour/capacity behaviour of shared queue workers and one php-fpm pool across tenants (no load test) | Unknown |
| D8 | Group-based enrolment + quiz/SCORM/H5P paths (only topic progress completion tested for group-only learners) | Unknown |

## E. Update after Batch 4 (S6 + S7)
**Closed**
- Webhooks/events: no built-in outbound module; custom bridge built and exercised end to end (Verified). Event latency/retry/idempotency of my bridge Verified.
- Pagination, filtering, bulk, error shapes, idempotency, rate limiting, versioning: Verified (see `s06-s07-batch4.md`).
- Media upload (video/audio/PDF/cover/resources/file manager), storage backend, URL strategy: Verified.
- Cross-tenant job isolation: Verified problem and mitigation (per-tenant `REDIS_DB`).
- Earlier open item C15 (H5P results) unchanged. B6 (impersonation hardening) still open.

**New / still open**
| # | Item | Label |
|---|---|---|
| E1 | HLS transcoding output (`ffmpeg` job on `queue-long-job`): not consumed in multi-domain mode; untested with a worker listening to that queue, and video formats/renditions produced | Unknown |
| E2 | Image topic upload needs the public storage URL reachable from the API container; behaviour with a correctly resolvable public host | Unknown |
| E3 | Signed/presigned media URLs or protected media (private bucket + authenticated delivery); behaviour for paid/private course media | Unknown (no support found) |
| E4 | Chunked/resumable uploads for large videos, upload size over Caddy/ingress in production | Unknown |
| E5 | Role assignment for CSV-imported users (imported users have no role and cannot log in) | Unknown |
| E6 | Production setting `APP_DEBUG=false` removes stack-trace leakage and the shape of errors then | Unknown (not tested) |
| E7 | Rate limiting: any throttle on login beyond 15 attempts, or configurable | Unknown |
| E8 | Fix for failed-job recording (`queue.failed.driver=database-uuids`) and whether it resolves lost failure visibility | Unknown (cause Verified, fix untested) |
| E9 | Scaling the per-tenant Redis isolation (logical DB count limits, separate Redis per tenant) and shared cache DB | Unknown |
| E10 | SDK (`escolalms/sdk`) quality and REST docs (`escolalms.github.io/API`) accuracy versus the live API | Documented only |
| E11 | The webhook package installed into a running container is lost on recreate; baking into the image and an upgrade path is not demonstrated | Unknown |
| E12 | Event payload completeness (e.g., final score on `quiz.grade.ready`) if the event were fired after persistence; whether a Wellms patch would be accepted upstream | Unknown |

## F. Update after Batch 5 (S8 + S9 + S10)
**Closed**
- Paid-boundary: nothing used in S1 to S7 was gated (23 of 50 packages exercised; all 50 public). Vendor text says MIT and fees for services only (Documented).
- phpunit: 9 suites (837 tests) run and passing on a clean throwaway DB (Verified).
- Repo health and dependency health measured; Laravel 9 EOL and 29 advisories recorded (Verified).
- Licence scan: GPL path traced (H5P hard dependency of topic-types).
- Correction recorded: 50 `escolalms/*` packages (not "264").

**New / still open**
| # | Item | Label |
|---|---|---|
| F1 | The "~$3k licence" claim: only a third-party directory snippet; vendor page says fees only for services | Unknown, needs a written answer from Escola (`biuro@escola.pl`) |
| F2 | The vendor's educational licence page (404) and whether a university gets different terms | Unknown |
| F3 | Availability, licence and price of `pcg-grades` (gradebook/journal) and other private client modules | Unknown |
| F4 | Support terms, SLA and willingness to support Luminate as an embedder; MIT (site, docs) vs Apache-2.0 (API repo, scorm) vs no licence (Admin, Front, h5p-react) inconsistency | Unknown, needs written clarification |
| F5 | Legal reading of GPL-3.0 exposure (H5P core and editor are a hard dependency of `topic-types`; trax and laravel-invoices removable) for hosted-only versus shipped-to-customer scenarios | Unknown, needs counsel |
| F6 | Feasibility and cost of forking `topic-types` to decouple H5P, and whether upstream would accept it | Unknown |
| F7 | Repo activity for the 15 packages not measured (rate limit): stationary-events (403 only), tags, tasks, templates*, topic-type-gift/project, topic-types, translations, vouchers, video, webinar, youtube | Unknown |
| F8 | The remaining 37 phpunit suites (cart, payments, templates, webinar, tasks, vouchers, etc.) | Unknown |
| F9 | Exploitability of the 29 composer advisories in this deployment and effort to move off Laravel 9 | Unknown |
| F10 | npm dependency licence scan for any Luminate-side use of `@escolalms/*` (only top-level package metadata checked) | Unknown |
| F11 | `docker stats` figures were taken while a test run was active; idle and under-load resource profile, scaling and sizing for real usage | Unknown |

## G. Update after the pooled-model follow-up (see `evidence/pooled-model-investigation.md`)
Closed: shared-DB feasibility, RLS/cache/queue/S3-prefix mechanisms, hot tenant provisioning (24 s, no restart), D3 (hot domain add) [Verified].
New / still open [Unknown]: full 439-operation cross-tenant run; RLS behind PgBouncer transaction pooling (`SET LOCAL`); RLS performance/index design at scale; per-tenant roles/permissions, settings, config, translations; TRAX owner mapping; per-tenant scheduler loop; exports under RLS; all 218 storage call sites with a prefixed disk; tenant claim in JWT; stancl/tenancy and spatie/laravel-multitenancy; per-tenant offboarding/restore; dynamic (registry-driven) worker/scheduler tenant list to replace static `MULTI_DOMAINS`.
