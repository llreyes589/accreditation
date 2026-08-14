# Resident Management Module — Make Deployable & E2E-Verified (Plan C)

> **For Hermes:** Use subagent-driven-development skill to implement this plan task-by-task.

**Goal:** Get the already-built Resident Management module running end-to-end (DB schema created, every existing screen testable) and defer all flowchart-gap work to a later review.

**Architecture:** The module is already code-complete — `DomainController` exposes CRUD for residents, consultants, quizzes/results, research papers, case logs, rotations/assignments, and transfers; 5 client pages (`residents`, `rotations`, `case-logs`, `research`, `evaluation`) already consume them. The ONLY blocker is that the migrations were never executed (`php artisan migrate:status` shows nothing ran), so the tables don't exist. Plan C = run migrations + verify each screen with a real-server ad-hoc, then add one happy-path feature test. No new schema, no new UI.

**Tech Stack:** Laravel 8 + MySQL (`accreditation_db`, real DB) · React + Vite + TS client · PHPUnit (SQLite `:memory:` per-test, but real-MySQL verification required) · oxlint + `npm run build`.

---

## Current context / assumptions

- Repo root: `C:\Users\llrey\Documents\psp-accreditation` (split: `app-api/` + `app-client/`).
- Confirmed already present (read-only inspection this turn):
  - **Models:** `Resident` (SoftDeletes; `user_id, institution_id, track, date_accepted, age_at_enrollment, year_level, promotion_status, promotion_evaluated_at`), `Consultant`, `ConsultantDocument`, `Quiz`, `QuizResult`, `ResearchPaper`, `CaseLog`, `RotationBlock`, `RotationAssignment`, `ResidentTransfer`.
  - **Migrations (exist, NOT run):** `2026_08_03_140000_create_accreditation_domain_tables.php` (residents, consultants, quizzes, quiz_results, case_logs, research_papers + core), `2026_08_03_150000_add_api_workflow_support.php` (residents → promotion_status/promotion_evaluated_at, settings, notifications), `2026_08_04_000000_add_core_operations_tables.php` (resident_transfers, consultant_documents, rotation_blocks, rotation_assignments), `2026_08_11_020000_accreditation_inspection_flow.php` (checklist/inspections — out of scope here).
  - **Routes** (`routes/api.php`, `api/domain` group, middleware `auth:sanctum` + institution scope): `/residents` (GET/POST), `/residents/{resident}/transfers` (POST), `/consultants` (GET/POST), `/consultants/{consultant}/documents` (GET/POST), `/quizzes` (GET/POST), `/quizzes/{quiz}/results` (POST), `/research-papers` (GET/POST), `/case-logs` (GET/POST), `/rotations` (GET/POST), `/rotations/{rotation}/assignments` (POST), `/rotation-assignments/{assignment}` (PUT).
  - **Client pages:** `src/pages/residents.tsx` (243 lines, profile + transfer), `src/pages/rotations.tsx` (246 lines, blocks + assignments), `src/pages/case-logs.tsx` (155 lines), `src/pages/research.tsx` (140 lines), `src/pages/evaluation.tsx` (238 lines — backs the **Quiz/RISE + Record-Result** flow, node K).
  - **Hooks:** `useResidents`, `useConsultants`, `useConsultantDocuments`, `useQuizzes`, `useCaseLogs`, `useRotations`, `useCreateResident`, `useCreateRotation`, plus result/paper/case hooks.
- `phpunit.xml` has SQLite `:memory:` commented out → tests run against real MySQL by default. Per-project convention, after any migration/schema change run `php artisan migrate:fresh --seed` on the real MySQL DB.
- **No feature test** currently touches residents/rotations/cases/quizzes.

## Open questions resolved by Plan C (do NOT implement these)

The flowchart contains nodes with no current schema/UI. These are **explicitly deferred**:
- Node C "expected completion date" → not a `residents` column; defer.
- Node F "conferences & duties" → `CaseLog` only covers cases/procedures; defer.
- Node G/H/I consultant validation + return-for-correction → no validation/status workflow on case logs; defer.
- Node K "examinations and RISE results" → partially covered by `Quiz`/`QuizResult` (type `exam` exists); the `evaluation.tsx` page already exercises this. Acceptable as-is.
- Node M "consultant evaluation" → the `evaluation.tsx` page is Quiz/RISE, NOT a consultant-evaluation form; a true consultant evaluation form is deferred.
- Node O "remediation plan" → deferred.
- Node U "archive resident records" → deferred.

The plan flags these in a closing "Deferred gaps" section; it does not build them.

---

## Step-by-step plan

### Task 1: Confirm migration state + backup the real DB
**Objective:** Verify nothing is migrated and take a safety snapshot before `migrate`.

**Files:** none (read-only + DBA action)

**Step 1:** Run `php artisan migrate:status` from `app-api`.
Expected: only the header line; every domain migration shows **[ ] Pending**.

**Step 2:** Dump the real DB for safety:
```
mysqldump -u <user> -p accreditation_db > ~/psp-accred-backup-$(date +%Y%m%d).sql
```
(or `DB::...` export per your environment). Keep the file; do NOT commit it.

### Task 2: Run migrations on the real MySQL DB
**Objective:** Create all missing tables so the module is queryable.

**Step 1:** `cd app-api && php artisan migrate --force`
Expected: each pending migration prints `Migrated: 2026_08_0x_...`; final line `Migrated  N tables`.

**Step 2:** `php artisan migrate:status`
Expected: every domain migration now shows **[X] Ran?** (Ran).

**Step 3:** `php artisan tinker --execute="echo Schema::hasTable('residents') ? 'residents OK' : 'MISSING'; echo PHP_EOL; echo Schema::hasTable('rotation_assignments') ? 'rotation_assignments OK' : 'MISSING';"`
Expected: both `OK`.

**Step 4:** Commit (schema now matches code):
```
git add database/migrations
git commit -m "chore: run resident-management migrations (schema now matches models/controllers)"
```
(Only if migrations themselves changed; if unchanged, skip commit and note "migrations already on disk — only DB applied".)

### Task 3: Run the canonical test + build gates (baseline)
**Objective:** Prove the existing suite + client still green after the DB is live.

**Step 1:** `cd app-api && php artisan test`
Expected: existing tests pass (AccreditationFlow 19, Registration, PlacesSearch, Example). No failure from new tables.

**Step 2:** `cd app-client && npx tsc --noEmit && npx oxlint src && npm run build`
Expected: tsc clean, oxlint 0 errors, `built in …s`.

### Task 4: Live-server ad-hoc smoke per screen (real MySQL)
**Objective:** Exercise each existing endpoint through a running server to confirm the screens will work, capturing real HTTP codes.

**Step 1:** Write `AppData/Local/Temp/hermes-resident-smoke.php` (ad-hoc, NOT a suite test) that:
- boots the app, ensures roles `TrainingInstitution`/`TrainingOfficer`/`Resident`/`Consultant` exist;
- creates an institution (approved), a TrainingOfficer user, logs in (sanctum token);
- via curl against `http://127.0.0.1:8xxx/api`:
  1. `POST /residents` → 201 (create resident profile — node B)
  2. `GET /residents` → 200 (list)
  3. `POST /consultants` → 201; `GET /consultants` → 200
  4. `POST /rotations` → 201 (calendar-month block — node D); `POST /rotations/{id}/assignments` → 201; `GET /rotations` → 200
  5. `POST /case-logs` → 201 (node F); `GET /case-logs` → 200
  6. `POST /research-papers` → 201 (node L); `GET /research-papers` → 200
  7. `POST /quizzes` (type=exam) → 201; `POST /quizzes/{id}/results` → 201 (node K)
  8. `POST /residents/{id}/transfers` → 201 (node A transfer-out)
- asserts each expected status; prints `PASS/FAIL` per call; exits non-zero on any fail.
**Step 2:** Start `php artisan serve --port=8xxx` (background), sleep 2, run the script, grep `-iv Deprecated`.
Expected: every call PASS (target ≥ 16/16).
**Step 3:** Kill server, `rm` the temp script.
**Step 4 (optional):** Open each client page in the browser via the preview pane / `php artisan serve` + Vite dev to confirm no runtime render errors (manual; not required for the plan to be "done", but recommended).

### Task 5: Add a happy-path feature test (`ResidentManagementFlowTest`)
**Objective:** Lock in the module's happy path so regressions surface in CI. TDD-lite: write the test, run (it needs the real DB tables → must be green after Task 2).

**Files:** Create `tests/Feature/ResidentManagementFlowTest.php`

**Step 1:** Write a test class that, using `RefreshDatabase` + a sanctum-login helper, covers:
- `test_resident_profile_created_and_listed` — POST `/residents` → 201, GET `/residents` returns it.
- `test_rotation_plan_created_and_assigned` — POST `/rotations` (valid calendar month) → 201; assignment → 201.
- `test_case_log_and_research_recorded` — POST `/case-logs` → 201; POST `/research-papers` → 201.
- `test_exam_result_recorded_and_promotion_evaluated` — POST `/quizzes` (exam) → 201; POST results → 201; assert `promotion_status` is `eligible`/`ineligible` (mirrors `DomainController::storeResult` threshold logic).
- `test_transfer_requested` — POST `/residents/{id}/transfers` → 201.
Use the project's existing `ownerWithInstitution()`-style helper pattern from `AccreditationFlowTest` if present; otherwise build a minimal `actingAsInstitution()` helper inline.

**Step 2:** `php artisan test --filter=ResidentManagementFlowTest`
Expected: all methods PASS.

**Step 3:** Full `php artisan test`
Expected: total count = previous (19-ish) + new (≈5) = green, 0 failures.

**Step 4:** Commit:
```
git add tests/Feature/ResidentManagementFlowTest.php
git commit -m "test: add resident-management happy-path feature test"
```

### Task 6: Final canonical gates + commit the module
**Objective:** One clean pass before handing back.

**Step 1:** `cd app-api && php artisan test` → all green.
**Step 2:** `cd app-client && npx tsc --noEmit && npx oxlint src && npm run build` → all green.
**Step 3:** Verify no running `php artisan serve` left in background; `rm` any temp scripts.
**Step 4:** Summarize to user: module is deployable; list the deferred flowchart gaps (see below) for a follow-up plan.

---

## Files likely to change (Plan C)
- `app-api/database/` — migrations already exist; **no new migration files** (only DB applied). If any migration is found broken on run, patch it in place and commit.
- `app-api/tests/Feature/ResidentManagementFlowTest.php` — NEW.
- `app-client/` — **no changes expected** (Plan C is verify-only). If a screen throws a runtime error in Task 4 Step 4, patch the specific page and commit separately, but treat as out-of-scope unless blocking.

## Risks / tradeoffs
- **Real DB, not SQLite:** `RefreshDatabase` runs `migrate:fresh` against MySQL in tests. Per memory, ensure the test DB is the real `accreditation_db` (phpunit.xml SQLite is commented out). A green PHPUnit on MySQL is the bar.
- **Seed-dependent data:** the institution/role seeder must exist so `ownerWithInstitution()`-style helpers work; if `DatabaseSeeder` lacks roles, the test helper must create them (as the ad-hoc does).
- **`evaluation.tsx` semantics:** it is the Quiz/RISE screen (node K), NOT a consultant-evaluation form. Do not "fix" it to be a consultant eval under Plan C.
- **Migrate is irreversible-ish:** Task 1 backup mitigates. Use `--force` only because this is a dev/seed DB.

## Deferred gaps (separate review/module — NOT built here)
1. Expected completion date (node C) — add `expected_completion_date` to `residents` + form field.
2. Conferences & duties logging (node F) — extend `CaseLog` or new `DutyLog`.
3. Consultant validation + return-for-correction workflow (nodes G/H/I) — status/flag on case logs with a correction loop.
4. True consultant evaluation form (node M) — distinct from Quiz/RISE.
5. Remediation plan (node O) — new entity/UI.
6. Portfolio archive (node U) — archive/export flow.

These will be scoped in a follow-up plan after this one is verified.
