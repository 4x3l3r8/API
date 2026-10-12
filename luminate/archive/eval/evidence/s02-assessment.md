# Batch 2: S2 assessment (Verified unless marked)
Base `http://127.0.0.1:8080`, header `Host: api.localhost`.

## Quiz engine = "GIFT quiz" topic (package escolalms/topic-type-gift)
- Authoring: `POST /api/admin/topics` (form-encoded) with `topicable_type=EscolaLms\TopicTypeGift\Models\GiftQuiz`; quiz settings `PUT /api/admin/gift-quizes/{id}` JSON `{value,max_attempts,max_execution_time,min_pass_score,counts_to_grade,weight,randomize_order}`.
- Questions: `POST /api/admin/gift-questions {topic_gift_quiz_id,score,order,value}`; `value` is **Moodle GIFT syntax**. Types accepted (all 8 created OK): multiple_choice, multiple_choice_with_multiple_right_answers (`~%50%`), true_false, short_answers, matching, numerical_question (`{#8:0}`), essay, description. Import/export GIFT: `/api/admin/gift-questions/import|export`.
- Student attempt: `POST /api/quiz-attempts {topic_gift_quiz_id}` -> 201, `max_score:17`. Questions are inside the attempt; **correct answers are not exposed** to the student (options only).
- Answers: `POST /api/quiz-answers/all {topic_gift_quiz_attempt_id, answers:[{topic_gift_question_id, answer:{<key>:value}}]}`; key by type: `text`, `multiple` (array), `bool`, `numeric`, `matching` (map). Server scores.
- Finish: `POST /api/quiz-attempts/{id}/end` -> `result_score 9, max_score 17, result_percent 52.94, is_passed true, correct_answers_count 4`, per-answer `score` + `feedback`.
- Manual grading: `PATCH /api/admin/quiz-answers/{id} {score,feedback}` (essay 0 -> 4) -> attempt re-scored to 13/17 (76.47%). Tutor comment: `PATCH /api/admin/quiz-attempts/{id}/feedback {"feedback":"..."}` (field is `feedback`).
- **Grade read API**: `GET /api/admin/quiz-attempts?course_id=&user_id=&topic_gift_quiz_id=&page=&per_page=` (paginated, filterable); `GET /api/admin/quiz-attempts/{id}`; `GET /api/admin/quiz-attempts/export` (xlsx). Student: `GET /api/quiz-attempts`.
- Attempts/resume: `max_attempts=2` honoured. Re-POSTing while an attempt is active returns the SAME attempt (HTTP 200, resume). A 3rd attempt over the limit returns **HTTP 200 with the old ended attempt** (no error) -> client must check `is_ended`.
- Time limit: `max_execution_time` is in **minutes**. 1-minute quiz: after `end_at` a queue job auto-ended the attempt (kept the in-time answer, score 1); a later answer got **403**.
- Caveats: `is_passed:true` was reported while an essay was still ungraded (pass flag computed on partial score); no aggregated gradebook in this install. Source has `QuizAttemptJournalGradeReadyEvent` "consumed in pcg-grades" -> a gradebook module exists elsewhere (NOT installed; availability/licence UNKNOWN). Question bank: `category_id` on questions exists (Documented; categories CRUD not exercised -> Unknown).
Verdict quiz: **Pass** (rich types, auto + manual scoring, attempts, timers, grade-read API).

## SCORM (package escolalms/scorm)
- `POST /api/admin/scorm/parse` and `/upload` (multipart `zip`) with a hand-built SCORM 2004 4th-ed package -> `version: scorm_2004`, 1 SCO (id 1, uuid 5ccfa166-...). Content stored in MinIO/S3 disk.
- Attach: topic `ScormSco` with `value=<sco id>`. Launch data JSON: `GET /api/scorm/show/{uuid}`; Wellms-served HTML player: `GET /api/scorm/play/{uuid}` (200, 7KB). So a SCORM runtime is available as an iframe, or Luminate can build its own using the JSON launch data.
- Tracking: `POST /api/scorm/track/{uuid} {"cmi":{...}}` and `GET /api/scorm/track/{scoId}/{key}` -> read back `cmi.completion_status="completed"`, `cmi.success_status="passed"`, `cmi.score.raw=90`.
- Gotchas: student role lacks `scorm_track-read`/`scorm_track-update` by default (403); granted via `PATCH /api/admin/roles/student` (full list is replaced). SCORM completion did **not** auto-complete the course topic (progress stayed 0): the client must also PATCH progress. Real SCO behaviour in a browser (JS API calls) not exercised: Unknown.
Verdict SCORM: **Pass (API-level)**; browser runtime Documented.

## xAPI (packages escolalms/lrs + cmi5, built on TRAX LRS)
- Prerequisite not done at boot: run `php artisan db:seed --class="EscolaLms\Lrs\Database\Seeders\LrsSeeder"` (else `GET /api/cmi5/courses/1` -> 404).
- `GET /api/cmi5/courses/{id}` -> `{endpoint: http://api.localhost/trax/api/<access-uuid>/xapi/std, activityId, registration}`.
- `POST <endpoint>/statements` (header `X-Experience-API-Version: 1.0.3`, learner bearer token) -> 200 `["<statement-uuid>"]`; `GET <endpoint>/statements` returns it; admin: `GET /api/admin/cmi5/statements`. Embedded LRS is standard xAPI.
- cmi5 package upload `POST /api/admin/cmi5`: endpoint present, NOT exercised (no cmi5 package built) -> Unknown.
- Whether the learner can read OTHER learners' statements: not tested -> Unknown.
- **LICENCE**: `escolalms/lrs` (MIT) requires `trax2/framework` = **GPL-3.0-or-later**.
Verdict xAPI: **Pass (statement store + read)**; licence flag.

## H5P (package escolalms/headless-h5p, uses h5p/h5p-core + h5p-editor)
- Real routes are under `/api/admin/hh5p/...` (OpenAPI lists `/api/hh5p/...` for admin routes: wrong again).
- Environment blockers (not Wellms capability): `php artisan h5p:seed` fails (hard-coded h5p.org URL 404). Hub library install `POST /api/hh5p/library-install?id=H5P.MultiChoice` returned `{"success":true,"data":null}` but installed nothing; content-type cache empty (`outdated:true`) -> H5P Hub not reachable/synced. (I also created a root-owned storage dir by running artisan as root; fixed with chown.)
- Worked: `POST /api/admin/hh5p/content/upload` (multipart `h5p_file`) with Wellms's own fixture `multiple-choice.h5p` -> content id 1, libraries auto-installed from the package. Topic `H5P` `value=1` -> player settings embedded in topic JSON; `GET /api/hh5p/content/{uuid}` (**public, unauthenticated**).
- Result capture: `POST /api/courses/progress/{topic_id}/h5p {"event","data"}` stored in `h5p_user_progress` (DB confirmed). No REST read endpoint found in the OpenAPI for stored H5P results; did not auto-complete the topic.
- Browser rendering of the H5P player and the H5P editor not exercised (needs JS front): Documented.
- **LICENCE**: `h5p/h5p-core` and `h5p/h5p-editor` = **GPL-3.0**.
Verdict H5P: **Partial** (content import + embed + result capture verified; authoring via Hub blocked here; GPL).

## Licence scan (composer.lock, 262 packages): early finding for Objective 10
MIT 224, Apache-2.0 16, BSD-3 13. Copyleft present:
- GPL-3.0: `h5p/h5p-core`, `h5p/h5p-editor`
- GPL-3.0-or-later: `trax2/framework` (pulled by escolalms/lrs -> cmi5)
- GPL-3.0-only: `laraveldaily/laravel-invoices`
- LGPL: dompdf (2.1), htmlpurifier, php-font-lib, php-svg-lib, pdfparser
- nette/schema, nette/utils: dual BSD-3 / GPL (choose BSD)
App LICENSE (api repo) = Apache-2.0. Contradicts the brief's "no copyleft" assumption for H5P/xAPI/invoices; removing those modules avoids GPL but loses H5P, xAPI, cmi5.
