# Batch 5: S8 paid-boundary audit, S9 ops and health, S10 licence scan
Pin: API repo `3dd34ebe9378c5d8a58c2333697c2606293d627b` (2026-07-29), composer.lock sha256 prefix `330e781a34d96af2`. Date of web checks: 2026-10-08.
Files: `s08-modules.md` (50-package table), `s09-github.txt`, `s09-packages.txt`, `s09-composer-audit.json`, `s09-docker-stats.txt`. Scripts: `scripts/s08_modules.py`, `s09_github.py`, `s09_packages.py`.

**Correction to earlier batches:** the install has **50** `escolalms/*` packages (262 packages in total), not "264". The earlier number was a loose text count of composer.lock.

# S8. Free vs paid
## Verified (from the running install)
- All 50 `escolalms/*` packages are fetched from **public GitHub** (`api.github.com` dist URLs); `composer.json` has **no `repositories`**, no `auth.json`, no credentials. Nothing in the build asked for a licence key.
- I exercised **23 of the 50** packages across S1 to S7 (list in `s08-modules.md`); **none was gated**. Every scenario in this evaluation ran on the public packages.
- Each package ships a LICENSE file (50 of 50 in `vendor/escolalms/*`). Composer licences: 49 MIT, 1 Apache-2.0 (`escolalms/scorm`).

## Documented (vendor statements)
- `https://www.wellms.io/license` (fetched): "Wellms jest licencjonowany na licencji open source MIT." Fees: "Opłaty pobieramy wyłącznie w przypadku świadczenia dodatkowych usług m.in.:" implementing Wellms for a client, workshops/UX/mock-ups, building custom features, adapting existing features, support/consultation. "Szczegóły dotyczące licencji udzielamy mailowo pod adresem biuro@escola.pl".
- `https://docs.wellms.io/` (fetched): "The entire codebase is available on GitHub (MIT)"; no commercial/premium modules mentioned.
- GitHub org `EscolaLMS`: 78 public repos; no statement about private or premium modules.
- The brief's "paid licence (~$3k tier)" appears **only in a third-party directory** (elearningindustry, via search summary: "one-time purchased license", "starting price $3000"); the page itself was not retrievable. It **conflicts** with the vendor's own page (fees for services only). Cannot reconcile: **Unknown**; needs a written answer from Escola.

## Unknown / open
- The vendor's "Licencja edukacyjna" (educational licence) link on the home page returns **404** (`/licencja-edukacyjna`); terms unknown. Relevant for a university deployment.
- Source mentions a module `pcg-grades` ("Consumed (in pcg-grades) to upsert the partial grade for the journal") that is **not in the public package set**: evidence that client-specific/private modules exist; availability and terms unknown. A gradebook is exactly what Luminate would want.
- Whether Escola would sell or support the stack for a third party's product (support terms, SLA): Unknown.
- Licence inconsistency to resolve in writing: website and docs say MIT; the `API` repo LICENSE and GitHub metadata say **Apache-2.0**; `escolalms/scorm` says Apache-2.0; `Admin` and `Front` repos show **no licence** (GitHub API `license: null`).
Verdict S8: **Pass for "nothing needed was paywalled"** (verified), with an open commercial question: the $3k claim is unverified and the vendor's own text says fees are for services. Confirm in writing.

# S9. Operations and health
## Footprint (Verified, `docker stats` after the tests started, API at 82% CPU was the test run)
12 containers. Memory at rest: api 457 MiB, minio 268, postgres 95, reportbro 48, soketi 44, mjml 33, front 13, admin 12, caddy 11, adminer 8, redis 8, mailhog 3 (about 1.0 GB total). Images: `wellms-api` **2.39 GB** (built in ~10 min the first time), reportbro 1.77 GB, admin 755 MB, demo 774 MB. Volumes with eval data: postgres 122 MB, minio 20 MB. Dependencies required: PostgreSQL, Redis, S3-compatible store, mjml and reportbro (email/PDF templating), a websocket server (soketi), SMTP.

## Tests
PHPUnit 9 suites ship **inside `vendor/escolalms/*/tests`** and are driven by the root `phpunit.xml` (46 suites). Running them needs: dev dependencies (`composer install` without `--no-dev`, not in the image), a migrated Postgres database, and the Passport key files copied into the Orchestra Testbench skeleton (`vendor/orchestra/testbench-core/laravel/storage/`), a step only visible in the Makefile. **`phpunit.xml` hard-codes `DB_DATABASE=default`**, which is the application's own database in the stock compose, so running tests naively can reset live data; I used a throwaway `wellms_test` database with explicit overrides (verified the resolved DB name before every migrate; live DBs checked untouched afterwards).

**Results (Verified, 9 of the 46 suites run): 837 tests, 4,149 assertions, 0 failures.**
| Suite | Tests | Assertions |
|---|---|---|
| topic-type-gift | 200 | 552 |
| scorm | 25 | 79 |
| course-access | 30 | 151 |
| lrs | 17 | 325 |
| settings | 52 | 142 |
| model-fields | 26 | 99 |
| headless-h5p | 158 | 703 |
| courses | 205 | 1,427 |
| auth | 124 | 671 |
Three false starts were my own set-up errors, not product failures, and each needed a step that only the repo's Makefile or the package layout reveals: (1) Passport key files must be copied into the testbench skeleton (first run: 178 of 200 errors "Invalid key supplied"; my first copy silently didn't run because a `pkill -f` pattern killed its own shell); (2) the test DB must not contain the seeded admin user (3 auth failures vanished on a clean `migrate:fresh`); (3) `courses` needs its test-fixture migration (`vendor/escolalms/courses/tests/Database/Migrations`) applied (8 failures vanished). The other 37 suites (cart, payments, templates*, webinar, tasks, vouchers, etc.) were **not run: Unknown**. These are the vendor's own tests of each package against a clean database; they say nothing about the multi-tenant, queue and event defects found above, which the suites did not catch.

## Repository health (Verified, GitHub API, unauthenticated)
- `EscolaLMS/API`: created 2021-01-26; default branch `develop`; last push **2026-07-29 (71 days before 2026-10-08)**; 19 stars, 19 forks; **0 open issues and 0 open PRs, 76 closed**; Apache-2.0; 15 contributors (top contributor 42% of commits).
- Releases: 1.0.50 (2026-05-28), 1.0.51 (2026-06-17), 1.0.52 (2026-07-28), 1.0.53 and 1.0.54 (2026-07-29). Last 100 commits span 2025-01-17 to 2026-07-29, by **3 authors**; commit messages of recent releases are dependency updates.
- Organisation: 78 repos, 59 followers, Kraków-based vendor (Escola S.A.).
- **Per-package activity (35 of 50 measured; rate limit stopped the rest, 14 Unknown):** median **664 days since last push**; 29 packages untouched for over a year; 17 for over two years; only 1 within 90 days. Examples: core (2024-07-11), lrs (2024-07-10), scorm (2025-01-23), courses (2025-08-08), auth (2026-05-26), headless-h5p (2026-07-09), video (2026-07-31). Most packages are `0.x` versions. Low commit frequency may mean maturity or neglect; either way **the API is mostly dependency-bump releases around very stable modules**.
- npm: `@escolalms/sdk` v1.0.0 (2025-05-07, MIT), `@escolalms/components` v0.0.165 (2025-09-12, MIT), `@escolalms/h5p-react` v0.2.21 (2025-04-25, **no licence field**), `@escolalms/ts-models` v0.0.35 (2023-04-25, ISC).

## Platform and dependency health (Verified)
- **Laravel 9.52.21**: end of life. The current Laravel support table lists version 10 with security fixes until 2025-02-04 and nothing for 9; 12 had bug fixes until 2026-08-13. PHP 8.3.23 in the image.
- `composer audit` in the container: **29 advisories in 8 packages** (12 high, 11 medium, 5 low, 1 unrated): `laravel/framework` 9.52.21 (5; ranges include 9.x, one high CRLF injection in the email rule; no fix will come on 9), `league/commonmark` (12, mostly DoS), `dompdf/dompdf` 2.0.8 (6; relevant to PDF certificates: SVG local file read, DoS), `maatwebsite/excel` (1 high, controlled export path), `composer/composer` (2, tooling), `firebase/php-jwt`, `league/flysystem`, `phpseclib` (1 each). **Exploitability in this deployment was not tested.**
- Upgrade path: stack is pinned to `laravel ^9`; moving to a supported Laravel means upgrading about 50 packages the vendor maintains at low cadence: **Unknown**, likely a vendor-led effort.

## Operational traps found in earlier batches (cross-reference)
Multi-domain mode leaves the default site without a queue worker or scheduler (S3); queue keys shared across tenants unless `REDIS_DB` is set per tenant (S3/S6); `failed_jobs` insert fails so failures vanish (S6); video transcoding queue is never consumed in multi-domain mode (S7); Docker image `bitnami/minio` removed and CRLF breakage on Windows (S0); stale docs (admin email, hh5p paths); `h5p:seed` dead URL.
Verdict S9: **Partial**: runs in a modest footprint and has a real test suite and regular API releases, but the platform is end-of-life (Laravel 9) with open advisories, the maintainer base is small, most modules are quiet, and several multi-tenant ops defaults are unsafe.

# S10. Licence scan
## PHP (composer.lock, 262 packages): MIT 224, Apache-2.0 16, BSD-3 13, GPL-3.0-only 3, LGPL 5, GPL-3.0 2, GPL-2.0-only 2 (nette, dual-licensed with BSD-3), GPL-3.0-or-later 1.
Copyleft that is **not** dual-licensed (no permissive alternative):
| Package | Licence | Pulled in by | Removable? |
|---|---|---|---|
| `h5p/h5p-core` 1.24.4, `h5p/h5p-editor` 1.24.4 | GPL-3.0 | `escolalms/headless-h5p`, which is **hard-required by `escolalms/topic-types`**, which `topic-type-gift` (quizzes), `topic-type-project`, `video` and `recommender` depend on | **No, not without forking `topic-types`** |
| `trax2/framework` 2.0.4 | GPL-3.0-or-later | `escolalms/lrs` (required by `escolalms/cmi5`) | Yes: drop `lrs` and `cmi5` (loses xAPI and cmi5; `topic-types` only *suggests* cmi5) |
| `laraveldaily/laravel-invoices` 3.3.1 | GPL-3.0-only | `escolalms/invoices` (required by nothing) | Yes |
LGPL (dynamic use, notice and relink obligations only): dompdf 2.0.8 (LGPL-2.1), php-font-lib, php-svg-lib, ezyang/htmlpurifier, smalot/pdfparser (required by `topic-types`).
nette/schema and nette/utils: BSD-3 / GPL-2.0 / GPL-3.0 (choose BSD-3).
So the brief's assumption "Apache-2.0 / MIT with no copyleft" is **false for the quiz and content-type path**: GPL-3.0 H5P libraries are a hard dependency of the core topic types.
## Application and JS
- API repo LICENSE: Apache-2.0 (verified file and GitHub metadata); every `escolalms/*` package declares MIT except `scorm` (Apache-2.0).
- npm: sdk MIT, components MIT, **h5p-react no licence declared**, ts-models ISC. Admin and Front repos: **no licence**. Not used in a Luminate-owned front end except by choice, but `h5p-react` is the obvious H5P player component.
## What the GPL means for Luminate (analysis, not legal advice)
- GPL-3.0 (not AGPL): obligations arise on **distribution/conveying**, not on running the software as a service. Luminate calling Wellms over HTTP is communication between separate programs, not a combined work.
- Risk appears if Luminate **ships Wellms to customers** (standalone installs, on-prem, "run as the SIS learning layer" bundled in an installer): the Wellms stack with H5P/trax GPL code must then be offered under GPL terms with source (it is open source already), and Luminate's own proprietary code must remain a separate program, not linked into Wellms' PHP process. Writing a custom Composer module that runs **inside** the Wellms process (like the webhook bridge in S6) makes that module part of the combined work: its licence must be GPL-compatible if distributed.
- Needs a lawyer's reading before commitment. The decision between running it as a hosted service only, shipping it, or forking `topic-types` to drop the H5P hard dependency is a legal and engineering choice.
Verdict S10: **Fail against the brief's bar ("no copyleft dependency") on the full install; Partial if the three removable modules are dropped and the H5P GPL boundary is accepted behind a network service.** Flag loudly.
