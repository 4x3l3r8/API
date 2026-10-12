# Wellms (EscolaLMS) Prototype Evaluation — Session Handoff Brief

> **Written for:** a fresh Claude Code session (and you) in a new, isolated folder, with no prior context. Everything needed to run the evaluation is in this file.
>
> **Goal of the exercise:** determine *how well and how deep* Wellms can serve as the **headless learning engine embedded inside Luminate** — not whether it's a nice LMS in the abstract, but whether Luminate (and through it, PortalAcademy) can own the entire experience on top of Wellms's API.

---

## 0. How to use this brief

1. Create a **new empty folder** for this work, e.g. `wellms-eval/`, outside the PortalAcademy repos. Save this file inside it as `WELLMS_EVAL_BRIEF.md`.
2. Start a **fresh Claude Code session** in that folder. Do **not** give it access to `PortalAcademyNew_BE` / `_FE` or the Luminate repo — keep the evaluation sandboxed and clean.
3. Paste **Section A (Kickoff Prompt)** as your first message. It is self-contained. Keep the rest of this file in the folder as reference the session can read.
4. Requirements on the machine: Docker + Docker Compose, git, and a REST client (curl/httpie is fine; Postman optional).

---

## A. Kickoff prompt — paste this verbatim into the new session

```
You are helping me run a hands-on technical evaluation of Wellms (also called EscolaLMS),
a headless Laravel LMS, in THIS folder. Read WELLMS_EVAL_BRIEF.md in this folder first —
it has the full context, objectives, setup steps, test scenarios, and the output I want.

Context in one line: I lead the team behind "Luminate", a first-party .NET + React LMS that
is currently feature-light. The plan is to embed a headless, feature-rich engine INSIDE
Luminate and build our own experience on top, so Luminate can run standalone or as the
learning layer of our SIS, "PortalAcademy". Wellms is the lead candidate. I need to know how
deep and how well it can actually serve that role before we commit.

Your mission:
1. Stand up Wellms locally (Docker) following Section D of the brief. Get it running and
   reachable, confirm the Swagger/OpenAPI docs load, and create an admin + an API token.
2. Work through the probe scenarios in Section E, driving everything through the REST API
   (NOT the bundled admin UI) wherever possible — the whole point is to prove the frontend
   can be fully owned by Luminate. Record the exact endpoints you hit and representative
   request/response snippets as evidence.
3. Produce the decision report described in Section F: a filled scorecard, evidence per
   objective, a precise list of what's free vs behind the paid licence, the gaps we'd have to
   build ourselves, and a sketch of how Wellms would sit as a service behind Luminate's .NET.

Rules: stay in this folder; this is a throwaway dev/eval environment so use only fake data,
no real PII; don't claim a capability you didn't exercise — label each finding as Verified
(you called it), Documented (docs only), or Unknown. Work in reasonable batches and check in
with me at each scenario boundary. Start by reading the brief, then propose your setup plan.
```

---

## B. Context the evaluation session needs

- **Luminate** = a first-party LMS (ASP.NET Core + React/Vite), owned and controlled by the evaluator's team. It works but is **feature-light** (basic courses/lessons/quizzes/assignments/enrolment/chat/Teams live classes/certificates; **no SCORM/xAPI/H5P/LTI**; tenancy is schema-level `SchoolId` with **no enforced isolation**).
- **PortalAcademy** = the multi-tenant higher-education SIS / system of record (also .NET modular monolith). It owns students, lecturers, programs, courses, curricula, course registration, **official results** (a `ResultSheet` approval workflow: Draft → Dept → Faculty → Senate → Published), transcripts, finance. It is **not** the thing being replaced — Luminate plugs into it later.
- **The plan:** embed a headless engine **inside Luminate** to give it real depth. Luminate stays the product and the frontend; the engine is a backend service. Luminate must be able to run **standalone** or as **PortalAcademy's learning layer**.
- **Why Wellms is the lead candidate:** it is the only option found that is *simultaneously* truly headless (API-first), multi-tenant (`laravel-multidomain`), **permissively licensed** (Apache-2.0 core, MIT JS components — safe to embed in a commercial product without copyleft), and **standards-rich** (SCORM 2004 + xAPI + H5P). Alternatives were ClassroomIO and LearnHouse (both AGPL → copyleft friction) and Moodle (not headless for activity delivery).
- **Hard boundary to respect in any integration design:** the LMS/engine must **never** become the official results system. LMS scores are formative / continuous-assessment *inputs*; PortalAcademy's `ResultSheet` workflow stays the only source of official grades.

---

## C. Evaluation objectives — the questions to answer

Rate each **Pass / Partial / Fail** with evidence. These are the things that decide the fit.

1. **Truly headless.** Can a full learner lifecycle (create course → add content → enrol → consume → assess → read grade) be driven **entirely via API**, with no dependency on Wellms's own UI? If any step forces the bundled UI, that's a gap Luminate must fill.
2. **Multi-tenancy & isolation.** Does `laravel-multidomain` give real per-tenant isolation (tenant A cannot read/write tenant B), per-tenant config/branding, and a clean way to provision a tenant via API? How strong is the isolation (enforced vs convention)?
3. **Assessment depth.** Question types, scoring, attempts/resumption, time limits, question banks, H5P interactivity, **SCORM import**, **xAPI** statements. Is there a clean **grade-retrieval API** to feed CA scores back to Luminate/PortalAcademy?
4. **Content model fit.** Can its course/group model represent our reality — a course run as `(Course × AcademicSession × AcademicTerm)` with **cohorts/groups** for program offerings, cross-listed courses, and multiple lecturers? How awkward is the mapping?
5. **Auth & SSO.** What's the token model (Passport/Sanctum/JWT)? Can Luminate **JIT-provision** accounts and achieve **single sign-on** (no second password) by issuing/exchanging tokens or delegating auth? Can roles be mapped?
6. **Extensibility & API quality.** Module system (can we add custom modules/fields?), API completeness (pagination, filtering, bulk ops, idempotency, **webhooks/events**), error semantics, versioning, SDK quality.
7. **Media & files.** Upload flow, storage backends (S3?), video handling, presigned URLs.
8. **Free vs paid boundary.** Precisely which modules/features require the **paid licence (~$3k tier)** vs the Apache-2.0 free core. This materially affects cost and "customizable" claims.
9. **Operational footprint.** Services/dependencies, resource needs, upgrade path, test suite (`phpunit`), and **maintenance health** (commit cadence, open issues, release recency, bus factor).
10. **Licence verification.** Confirm Apache-2.0 / MIT across the packages you actually use; scan for any copyleft dependency that would contaminate a commercial embed.

---

## D. Setup directives (stand up Wellms locally)

Verified facts (2026-10, confirm versions live — don't trust blindly):

- **Main app repo:** `https://github.com/EscolaLMS/API` — the deployable Laravel REST API (bundles the modules).
- **Run it:** clone, then `docker compose up -d`. Config is via **`LARAVEL_`-prefixed env vars** (not a classic `.env`) — read the repo's `docker-compose.yml` for the reference set. The app is stateless (config lives in DB + env).
- **Swagger / OpenAPI:** served at `/api/documentation` on the running instance. Live reference demo: `https://api-stage.escolalms.com/api/documentation` (demo DB reseeds daily — safe to explore, not to rely on).
- **REST reference:** `https://escolalms.github.io/API/` · **SDK reference:** `https://escolalms.github.io/sdk/` · **Docs:** `https://docs.wellms.io/` · **Multi-tenancy guide:** `docs/multidomain.md` in the repo.
- **Admin / token:** create an admin via the seeder/artisan (check repo README/getting-started), then obtain an API token and exercise the REST endpoints directly. There is also a "Create-LMS-App" helper and a one-click/Docker installer mentioned in the docs for production.
- **Tests:** `phpunit` runs the suite across packages — a quick signal of health/coverage.
- **Licence:** `LICENSE` in the API repo = **Apache-2.0**; JS components (`@escolalms/*`) = MIT. Verify against the exact packages you use.

Minimum "it's alive" checklist before starting scenarios:
- [ ] Containers up; API reachable on its port.
- [ ] Swagger docs load at `/api/documentation`.
- [ ] Admin user created; API token obtained; one authenticated GET succeeds.

---

## E. Probe scenarios (run in order; API-first)

> For each: note the endpoints, a representative request/response, and the Pass/Partial/Fail call with a one-line reason. Prefer the API over the admin UI deliberately.

1. **Headless course lifecycle.** Via API only: create a course → add lessons/topics → publish → enrol a student → student consumes content → progress is tracked → read progress/completion. (Objective 1)
2. **Assessment run.** Create a quiz/exam with multiple question types → student attempts it → scored → retrieve the score via API. Then import a **SCORM** package and fire/read an **xAPI** statement; embed an **H5P** item. (Objective 3)
3. **Multi-tenant isolation.** Stand up **two** tenants via `multidomain` → create data in each → prove tenant A's token cannot read tenant B's course/users → check per-tenant config/branding. (Objective 2)
4. **Content-model mapping.** Attempt to model one real case: course `CSC101` for `2025/2026 · Semester 1`, two cohorts (two program offerings) as **groups**, two lecturers. Document how cleanly (or not) Wellms represents it. (Objective 4)
5. **Auth/SSO probe.** Identify the token mechanism; test creating a user + issuing a token programmatically (JIT); assess whether Luminate could front login without a second password. (Objective 5)
6. **Extensibility & events.** Check for webhooks/events on key actions (enrolment, completion, grade). Test pagination/filtering/bulk on a list endpoint. Inspect how a custom module/field would be added. (Objective 6)
7. **Media.** Upload a file/video; identify storage backend and URL strategy. (Objective 7)
8. **Paid-boundary audit.** From the repo/docs, list every module and mark free-core vs paid. Confirm which of the above scenarios used a paid module. (Objective 8)
9. **Ops & health.** Record services, run `phpunit`, and summarise repo health (last release, commit cadence, open issues). (Objective 9)
10. **Licence scan.** Verify licences of the packages touched; flag any copyleft dep. (Objective 10)

---

## F. Required output (the deliverable)

A single decision report, `WELLMS_EVAL_REPORT.md`, containing:

1. **Verdict:** `Adopt` / `Adopt with defined custom work` / `Reject for Luminate` — one paragraph, plain.
2. **Scorecard:** the 10 objectives, each Pass/Partial/Fail + one-line evidence.
3. **Evidence log:** per scenario, endpoints hit + representative snippets (so claims are traceable).
4. **Free vs paid map:** exact module list with licence/cost flags.
5. **Gap list:** what Luminate would have to build itself on top of Wellms (the cost of "customizable").
6. **Integration sketch:** how Wellms sits as a **separate service behind Luminate's .NET** (auth bridge, data mapping, where the `(Course×Session×Term)`+group mapping lives, how grades flow out as CA to PortalAcademy without becoming official results).
7. **Risks & unknowns:** anything labelled Documented/Unknown that needs follow-up.

---

## G. Guardrails

- **Isolation:** stay in the eval folder; do not touch or import the PortalAcademy or Luminate codebases.
- **Data:** throwaway dev environment, **fake data only**, no real student/lecturer PII.
- **Honesty of claims:** label every finding **Verified** (you ran it), **Documented** (docs only), or **Unknown**. Don't infer depth you didn't test.
- **Paid features:** never assume a feature is free — confirm against the licence/module boundary and flag it.
- **Scope:** this is an **evaluation**, not production hardening. Don't rabbit-hole on perfecting the deployment; get far enough to answer the objectives.
- If setup fails after a couple of genuine attempts, stop and report what blocked you rather than thrashing.

---

## H. Quick reference (verified facts, so the session needn't re-derive)

| Item | Value |
|---|---|
| Product | Wellms / EscolaLMS — headless Laravel LMS |
| Main repo | github.com/EscolaLMS/API (deployable) |
| Run | `docker compose up -d`; `LARAVEL_`-prefixed env vars |
| Swagger | `/api/documentation` (demo: api-stage.escolalms.com/api/documentation) |
| Docs / REST / SDK | docs.wellms.io · escolalms.github.io/API · escolalms.github.io/sdk |
| Multi-tenancy | `laravel-multidomain`; see `docs/multidomain.md` |
| Standards | SCORM 2004, xAPI/Tin-Can, H5P |
| Licence | Apache-2.0 (core API) · MIT (JS components) · free core + paid extensions (~$3k tier) |
| Stack | PHP ≥8.1, Laravel; PostgreSQL/MySQL; Redis |
| Tests | `phpunit` |

---

## I. What a good result looks like (bar for the top objectives)

- **Headless (Obj 1):** full lifecycle via API with the bundled UI never required = **Pass**. Any mandatory UI step = **Partial/Fail**.
- **Multi-tenancy (Obj 2):** enforced cross-tenant isolation + API provisioning = **Pass**. Works but convention-based/manual = **Partial**.
- **Assessment (Obj 3):** quizzes + SCORM + xAPI + H5P all exercised with a clean grade-read API = **Pass**. Missing SCORM/xAPI/H5P or no grade API = **Partial/Fail**.
- **Licence (Obj 10):** everything you'd embed is Apache-2.0/MIT with no copyleft deps = **Pass**. Any required paid module or copyleft dep = flag loudly.

A clear **Pass on 1, 2, 3, and 10** is the threshold for "Adopt"; anything less lands in "Adopt with defined custom work" or "Reject", with the gap list making the cost explicit.
