# Resident Management — How to Test the Full Flow

This module is **already built** (models, migrations, `DomainController` CRUD, and 5 client pages).
The steps below verify it end-to-end and map every step to the flowchart you forwarded.

> Roles: the resident flow is owned by a **Training Officer** (role `TrainingOfficer`) at an
> **approved institution**. Log in as that officer to exercise the screens.

## Flowchart → screen/endpoint map

| Flowchart node | What to do (UI) | API (verified) |
|---|---|---|
| A Resident admitted → B Training officer creates resident profile | **Residents** page → `New Resident` → fill name/username/email/password/track → Create | `POST /api/residents` → 201 |
| C Assign year level + expected completion date | Year level is derived from `date_accepted` (auto via `refreshYearLevel`). *Expected completion date is NOT yet captured — see gaps.* | `resident.refreshYearLevel(duration)` |
| D Create rotation plan | **Rotations** page → `New Rotation` → title/category/month (must be a full calendar month) → Assign resident | `POST /api/rotations` → 201, `POST /api/rotations/{id}/assignments` → 201 |
| E Resident begins rotation → F Record cases, procedures, conferences, duties | **Case Logs** page → add case type / procedure / count | `POST /api/case-logs` → 201 |
| G Consultant reviews → H acceptable? → I return for correction | *No validation/return loop in code yet — see gaps.* | — |
| J Update resident portfolio | Implicit (records aggregate under the resident) | — |
| K Examinations & RISE results | **Evaluation** page → create Quiz (type `exam`) → `Record Result` (score) → promotion status updates | `POST /api/quizzes` → 201, `POST /api/quizzes/{id}/results` → 201 |
| L Case report & research outputs | **Research** page → add paper (title/stage) | `POST /api/research-papers` → 201 |
| M Consultant evaluation | *The Evaluation page currently covers Quiz/RISE, not a consultant-eval form — see gaps.* | — |
| N complete? → O remediation → Q final year → R advance | Promotion status is evaluated from exam results; advancement/remediation UI not built yet — see gaps. | `resident.promotion_status` |
| Transfer (residency A→B) | **Residents** page → `Transfer` → pick destination institution | `POST /api/residents/{id}/transfers` → 201 |
| U Archive resident records | *Not built — see gaps.* | — |

## Flowchart in the app (Resident Lifecycle view)

A new **Resident Lifecycle** page (`/residents/:id`) renders the forwarded flowchart for a
single resident as a stage list (A→B→C→D→E/F→G/H/I→K→L→M→N/O→Transfer→U). Reach it from
**Residents → Lifecycle** button on any row.

Each stage reads the existing module endpoints (no new schema):

| Stage | Source | Status rule |
|---|---|---|
| B Profile | `GET /residents` | always Done |
| C Year level | resident `year_level` | Done if set |
| D Rotation plan | `GET /rotations` (assignments) | Done if assigned |
| E/F Cases | `GET /case-logs` | Done if any |
| G/H/I Consultant review | — | **Planned** (not built) |
| K Exams & RISE | `GET /quizzes` (results) | Done if any; shows promotion status |
| L Research | `GET /research-papers` | Done if any |
| M Consultant eval | — | **Planned** |
| N/O Completion & remediation | — | **Planned** |
| Transfer | `GET /transfers/incoming` + resident transfers | Done if any |
| U Archive | — | **Planned** |

Planned stages are shown greyed with a "Planned" badge so the gap is visible.

## Manual click-through (browser)

1. Start the API: `cd app-api && php artisan serve --port=8000`
2. Start the client: `cd app-client && npm run dev`
3. Log in as a **Training Officer** at an approved institution.
4. **Residents** → click **Lifecycle** on a resident → the flowchart renders with Done/Not-recorded/Planned per stage and deep-links to the action page.
5. Create data via the linked pages (Rotations, Case Logs, Evaluation, Research, Transfers) and revisit Lifecycle to see stages flip to Done.

## Automated verification (what was run)

- **Feature test** `tests/Feature/ResidentManagementFlowTest.php` — 5 tests, all green:
  - resident profile created + listed
  - rotation plan created + assigned
  - case log + research recorded
  - exam result recorded + promotion evaluated
  - transfer requested
- **Full PHPUnit suite**: `php artisan test` → **24 passed**.
- **Client gates**: `npx tsc --noEmit` clean · `npx oxlint src` 0 errors · `npm run build` OK.
- **Live-server ad-hoc smoke** (real MySQL, every endpoint): **17/17 PASS** — each flowchart-mapped call returned the expected HTTP status.

## Known gaps (deferred — not built yet, flagged for a follow-up plan)

1. **Expected completion date** (node C) — no `residents` column / form field.
2. **Conferences & duties** logging (node F) — `CaseLog` only covers cases/procedures.
3. **Consultant validation + return-for-correction loop** (nodes G/H/I) — no status/flag workflow on case logs.
4. **True consultant evaluation form** (node M) — `evaluation.tsx` is Quiz/RISE only.
5. **Remediation plan** (node O) — no entity/UI.
6. **Portfolio archive** (node U) — no export/archive flow.

## One bug fixed during verification

`DomainController::storeRotation` rejected every valid end-of-month date because
`Carbon::endOfMonth()` carries a `23:59:59` time that never `eq()`-matched the date-only input.
Changed the calendar-month check to compare **date strings**. This unblocked node D
(Create rotation plan), which was previously impossible via the API.
