# Batch 1: S1 headless course lifecycle + S5 auth/SSO (all Verified unless marked)
Base: `http://127.0.0.1:8080`, header `Host: api.localhost`. Helper: `scripts/lib.sh`.

## S1 lifecycle: every step done by REST, no UI
| Step | Call | Result |
|---|---|---|
| Admin login | `POST /api/auth/login {email,password}` | `{success,message,data:{token,expires_at}}` |
| Create course | `POST /api/admin/courses` JSON `{title,summary,description,status:"published",level,language}` | 201, id=1 |
| Create lesson | `POST /api/admin/lessons` JSON `{title,order,course_id}` | 201, id=1 |
| Topic types | `GET /api/admin/topics/types` | RichText, Video, Audio, Image, PDF, OEmbed, H5P, ScormSco, Cmi5Au, GiftQuiz, Project |
| Create topic | `POST /api/admin/topics` **form-encoded only** (JSON body ignored -> 422 "lesson_id required"); `topicable_type=EscolaLms\TopicTypes\Models\TopicContent\RichText`, `value=<p>..` | 201 |
| Create student | `POST /api/admin/users` JSON; needs `return_url`, `password`, `password_confirmation`; flag to skip email verification is `verified:true` (NOT `email_verified`; undocumented in OpenAPI) | 201 |
| Enrol | `POST /api/admin/courses/{id}/access/add {"users":[4]}` | 200, users listed |
| Student login | `POST /api/auth/login` | 200 (422 "Email not validated" if user not verified) |
| Consume | `GET /api/courses/my` -> ids; `GET /api/courses/1/program` -> lessons+topics+content | 200 (403 before enrolment) |
| Progress write | `PATCH /api/courses/progress/1 {"progress":[{"topic_id":1,"status":1}]}` | 200; **status enum 0=incomplete 1=complete 2=in-progress** (I first used 2 = no completion) |
| Progress read | `GET /api/courses/progress/1`, `/api/courses/progress` | per-topic status, started_at, finished_at; course `start_date`,`finish_date` |
| Course completion | after all topics status=1: `course_user.finished=true`, `finish_date` set | confirmed in DB and API |
| Admin analytics | `GET /api/admin/stats/course/1` | PeopleBought 1, PeopleFinished 1 (`EscolaLms\Reports` package) |

Quirks: topic endpoint form-only; `PUT /topics/{id}/ping` returns ok; OpenAPI spec is thin on request bodies; `/api/admin/users` has no PUT/PATCH in spec (user update surface unclear: UNKNOWN, test later).
Verdict S1: **Pass** (full lifecycle via API; no UI step needed).

## S5 auth/SSO
- Mechanism: **Laravel Passport**, tokens are RS256 JWTs. Claims: `sub`=user id, `aud`=personal access client id, `exp`=iat+300s, `scopes:[]`. Two clients seeded: Personal Access, Password Grant.
- TTL: `escola_auth.token_expiration_minutes` (default 5), "administrable" (settable at runtime via config API, not tested).
- Refresh: `GET /api/auth/refresh` with bearer -> new token (200).
- **SSO bridge**: `POST /api/admin/auth/impersonate {"user_id":N}` (OpenAPI wrongly says `/api/auth/impersonate`, which 404s) returns a normal user token, no password. Student token calling it -> 403. Permission: `user_impersonate`.
- **JIT**: `POST /api/admin/users` with random password + `verified:true` -> immediately loginable/impersonatable. Verified end to end with a service account (role `luminate-service`: user_create,user_read,user_list,user_impersonate,course_list,course_read,course_update): created user 6, enrolled user 6, minted token for user 6. Same account got 403 on `POST /api/admin/courses`.
- **SECURITY FINDING**: that same narrow service account could (a) `impersonate` user 1 (root admin) -> token, and (b) create a user with `roles:["admin"]`. Permissions are not scoped by target role/tenant. A leaked service credential = full takeover. Needs mitigation (see report): keep credential only in Luminate backend, network-restrict Wellms admin routes, audit `Impersonate` event, or patch/add a policy module. (test user 7 deleted)
- Role mapping: roles are Spatie permission roles; `POST /api/admin/roles`, `PATCH /api/admin/roles/{name} {permissions:[...]}` works (names normalized, underscores -> hyphens: `luminate_service` became `luminate-service`). 257 permissions.
- Not tested: OAuth password grant / external IdP (OIDC) delegation, social login, token revocation on logout, client_credentials. Mark UNKNOWN / Documented.
Verdict S5: **Pass with caveat** (JIT + SSO via impersonation works, but admin-grade bridge with no scoping).
