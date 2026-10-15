# The release gate: a generated cross-tenant isolation suite over every operation

    make gate            # from luminate/, dev stack up; exit code 1 on any violation
    make gate-both       # A attacks B, then B attacks A

Re-run it on every Wellms upgrade and before every release. It regenerates itself from the OpenAPI spec and the routes the application
actually serves, so a new route is covered the moment it exists.

## What it covers
Operations = the 439 in `evidence/openapi.json` UNION every route the application serves. The spec omits about 260 (xAPI/Trax, Horizon, OAuth,
payment callbacks, `/api/admin/hh5p/*`, ...) and lists 22 that the application does not serve (the contract's wrong-paths list); both are reported.

For every operation, as the ATTACKER tenant's admin:

| variant | request | must |
|---|---|---|
| V1 | no `X-Tenant-Context` | be refused `401 tenant_context_*` before anything runs |
| V2 | attacker's bearer under the VICTIM's context | be refused `403` (token binding, contract D.5) |
| V3 | the attack: real VICTIM ids in the path, query and body (ids picked by table: `/courses/{course}` takes a victim course id; `course_id`, `user_ids`, ... in bodies too), with the attacker's own valid context and token | return nothing that exists only in the victim, and leave the victim's data byte-identical |
| C (control) | the same request as the VICTIM on its own ids | (evidence only) prove the id was real, so a blocked V3 is not just a wrong id |

The oracle does not depend on the code under test: it reads the database as the table OWNER. `victim-only values` are every text/uuid value of the
victim's rows minus anything the attacker also has (plus the run's markers); the response is scanned for them (an echo of what the attacker itself
sent is ignored). The victim's data is fingerprinted (count + md5 of every row, every tenant table) before and after each mutating attack; tables that
change on their own are calibrated out and listed.

## Outcomes
- **PROVEN** the control found the resource, the attack did not. **BLOCKED** the attack was stopped but the control was inconclusive (bad body, missing precondition).
- **LIST** no victim id to attack (collection endpoints, or a type with no seeded victim data): the leak scan and the write check still ran.
- **STOCK-DEFECT** 5xx for the victim's own request too (stock Wellms is broken there, e.g. Passport's web routes call `viaRemember`); **NOT-FOUND-500** the attack 5xx
  is identical to a nonexistent id (an unhandled not-found; reveals nothing). **ERROR5XX** anything else: look at it.
- **NO-ROUTE** in the spec, not served. **SKIPPED** authenticates itself (docs; registry sync, covered by `registry_sync.py`).
- **VIOLATION** V1/V2 wrong, victim-only data in a response, or victim data changed. Fails the gate.
- Also listed, not a failure: 2xx responses that ACCEPTED a victim id in the request body (a cross-tenant reference).

## Seeding, and what it cannot reach
`seed_recipes.py` builds a realistic object graph through the API with bodies Wellms accepts (categories, groups, pages, products, courses, lessons, topics, gift quizzes with
questions and attempts, students with access, webinars, consultations, events, questionnaires, settings, tasks, ...): 3 of each in the victim, 1 in the attacker, so the victim
owns ids the attacker lacks. A schema-driven pass then tries every other creatable type. Types the API will not let it create stay unseeded (xAPI/Trax LRS stores, payments, vouchers
beyond one, templates, ...); their operations degrade to LIST and the report names them. The gate does not hide that: a green gate with many LIST rows means "no leak found", not "proven".

## Gotchas
- Operations run as an admin can delete that admin ("delete my account"). The gate restores the attacker's admin after each attack and the victim's after each control; if the
  VICTIM's admin disappears during the attack phase that is reported as a violation (and only then restored, so the run can continue).
- Needs the dev override (Swagger exemption) so `/api/oauth2-callback` etc. are expected to be served untokened; `prod_posture.py` proves production refuses them.
- Stops the compose `scheduler` while it runs, restores it at the end.
- Reports: `tests/gate/report/<attacker>_to_<victim>.md` (committed summary) and `.json` (ignored).
