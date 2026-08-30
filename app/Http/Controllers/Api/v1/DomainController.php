<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\{InstitutionDocument, Consultant, ConsultantDocument, Quiz, QuizResult, ResearchPaper, CaseLog, Accreditation, AccreditationInspection, ChecklistItem, Resident, ResidentTransfer, RotationBlock, RotationAssignment, ConsultantReview, ConsultantEvaluation, RemediationPlan, PortfolioArchive, Setting, TrainingOfficer, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash};

class DomainController extends Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    private function institution(Request $r)
    {
        $u = $r->user();
        if ($u->hasRole('TrainingInstitution')) return $u->institution;
        if ($u->hasRole('TrainingOfficer')) return optional($u->trainingOfficer)->institution;
        if ($u->hasRole('Resident')) return optional($u->resident)->institution;
        abort(403, 'No institution context.');
    }
    public function me(Request $r)
    {
        return $r->user()->load('roles', 'institution', 'trainingOfficer.institution.documents', 'resident.institution');
    }
    public function pending(Request $r)
    {
        return response()->json(['status' => $r->user()->status, 'email_verified' => $r->user()->hasVerifiedEmail()]);
    }
    public function dashboard(Request $r)
    {
        $i = $this->institution($r);
        $residents = $i->residents();
        $consultantExpiry = ConsultantDocument::whereHas('consultant', function ($q) use ($i) {
            $q->where('institution_id', $i->id);
        })->whereNotNull('expires_at');
        return response()->json(['institution' => $i, 'documents' => $i->documents()->latest()->get(), 'expired_documents' => $i->documents()->whereDate('expires_at', '<', today())->count(), 'accreditations' => $i->accreditations()->latest()->get(), 'unread_notifications' => $r->user()->unreadNotifications()->count(), 'metrics' => ['residents_by_track' => $residents->selectRaw('track, count(*) as total')->groupBy('track')->pluck('total', 'track'), 'residents_by_year_level' => $residents->selectRaw('year_level, count(*) as total')->groupBy('year_level')->pluck('total', 'year_level'), 'promotion_statuses' => $residents->selectRaw('promotion_status, count(*) as total')->groupBy('promotion_status')->pluck('total', 'promotion_status'), 'case_total' => CaseLog::whereHas('resident', function ($q) use ($i) {
            $q->where('institution_id', $i->id);
        })->sum('count'), 'cases_by_type' => CaseLog::whereHas('resident', function ($q) use ($i) {
            $q->where('institution_id', $i->id);
        })->selectRaw('case_type, sum(count) as total')->groupBy('case_type')->pluck('total', 'case_type'), 'assessment_averages' => QuizResult::whereHas('quiz', function ($q) use ($i) {
            $q->where('institution_id', $i->id);
        })->join('quizzes', 'quiz_results.quiz_id', '=', 'quizzes.id')->selectRaw('quizzes.type, avg(quiz_results.score) as average')->groupBy('quizzes.type')->pluck('average', 'type'), 'rotation_assignments' => RotationAssignment::whereHas('rotationBlock', function ($q) use ($i) {
            $q->where('institution_id', $i->id);
        })->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'), 'expired_consultant_documents' => (clone $consultantExpiry)->whereDate('expires_at', '<', today())->count(), 'expiring_consultant_documents' => (clone $consultantExpiry)->whereBetween('expires_at', [today(), today()->addDays(30)])->count()]]);
    }
    public function institutionProfile(Request $r)
    {
        return response()->json($this->institution($r));
    }
    public function updateInstitutionProfile(Request $r)
    {
        $i = $this->institution($r);
        $d = $r->validate([
            'name' => 'required|string|max:255',
            'address' => 'nullable|string',
            'hospital_level' => 'nullable|string|max:255',
            'laboratory_level' => 'nullable|string|max:255',
            'bsf_category' => 'nullable|string|max:255',
            'director' => 'nullable|string|max:255',
            'chairman' => 'nullable|string|max:255',
            'contact_number' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'year_program_opened' => 'nullable|integer|min:1900|max:2100',
            'region' => 'nullable|string|max:255',
            'province' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);
        $i->update($d);
        return response()->json($i);
    }

    public function notifications(Request $r)
    {
        return $r->user()->notifications()->latest()->paginate();
    }

    public function readNotification(Request $r, $id)
    {
        $n = $r->user()->notifications()->findOrFail($id);
        $n->markAsRead();
        \App\Services\NotificationService::recordRead($r->user()->id);
        return response()->json($n);
    }

    public function getPreferences(Request $r)
    {
        return response()->json($r->user()->notificationPreferences()->get());
    }

    public function updatePreferences(Request $r)
    {
        $d = $r->validate([
            'preferences' => 'required|array',
            'preferences.*.category' => 'required|string|max:50',
            'preferences.*.channel' => 'required|in:database,email,in_app',
            'preferences.*.enabled' => 'boolean',
            'preferences.*.quiet_hours_start' => 'nullable|string|max:8',
            'preferences.*.quiet_hours_end' => 'nullable|string|max:8',
        ]);
        $saved = [];
        foreach ($d['preferences'] as $p) {
            $saved[] = \App\Models\NotificationPreference::updateOrCreate(
                ['user_id' => $r->user()->id, 'category' => $p['category'], 'channel' => $p['channel']],
                [
                    'enabled' => $p['enabled'] ?? true,
                    'quiet_hours_start' => $p['quiet_hours_start'] ?? null,
                    'quiet_hours_end' => $p['quiet_hours_end'] ?? null,
                ]
            );
        }
        return response()->json($saved);
    }
    public function documents(Request $r)
    {
        return $this->institution($r)->documents;
    }
    public function storeDocument(Request $r)
    {
        $d = $r->validate(['type' => 'required|in:license,permit,accreditation,other,' . implode(',', Accreditation::REQUIRED_DOC_TYPES), 'file' => 'required|file|max:10240']);
        $i = $this->institution($r);
        $path = $r->file('file')->store('institution-documents/' . $i->id, 'public');
        $expiry = today()->addYear()->startOfYear();
        return response()->json(InstitutionDocument::create(['institution_id' => $i->id, 'type' => $d['type'], 'file_path' => $path, 'expires_at' => $expiry]), 201);
    }
    public function consultants(Request $r)
    {
        return Consultant::where('institution_id', $this->institution($r)->id)->get();
    }
    public function storeConsultant(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:255', 'specialty' => 'required|in:AP,CP,AP_CP', 'credentials' => 'nullable|string', 'linked_documents' => 'nullable|array']);
        return response()->json(Consultant::create(array_merge($d, ['institution_id' => $this->institution($r)->id])), 201);
    }
    public function quizzes(Request $r)
    {
        return Quiz::where('institution_id', $this->institution($r)->id)->with('results')->get();
    }
    public function storeQuiz(Request $r)
    {
        $d = $r->validate(['title' => 'required|string|max:255', 'type' => 'required|in:quiz,exam', 'max_score' => 'required|numeric|min:1']);
        return response()->json(Quiz::create(array_merge($d, ['institution_id' => $this->institution($r)->id, 'created_by' => $r->user()->trainingOfficer->id])), 201);
    }
    public function storeResult(Request $r, Quiz $quiz)
    {
        $i = $this->institution($r);
        abort_unless($quiz->institution_id === $i->id, 403);
        $d = $r->validate(['resident_id' => 'required|exists:residents,id', 'score' => 'required|numeric|min:0', 'taken_at' => 'nullable|date']);
        $resident = Resident::findOrFail($d['resident_id']);
        abort_unless($resident->institution_id === $i->id, 403);
        $result = QuizResult::create(array_merge($d, ['quiz_id' => $quiz->id]));
        $required = data_get(Setting::getValue('promotion_thresholds', []), $resident->track . '.' . $resident->year_level . '.' . $quiz->type);
        if ($required !== null) $resident->update(['promotion_status' => $result->score >= $required ? 'eligible' : 'ineligible', 'promotion_evaluated_at' => now()]);
        return response()->json($result, 201);
    }
    public function papers(Request $r)
    {
        return ResearchPaper::whereHas('resident', function ($q) use ($r) {
            $q->where('institution_id', $this->institution($r)->id);
        })->get();
    }
    public function storePaper(Request $r)
    {
        $d = $r->validate(['resident_id' => 'required|exists:residents,id', 'title' => 'required|string|max:255', 'stage' => 'required|string|max:100', 'notes' => 'nullable|string']);
        $resident = Resident::findOrFail($d['resident_id']);
        abort_unless($resident->institution_id === $this->institution($r)->id, 403);
        return response()->json(ResearchPaper::create($d), 201);
    }
    public function cases(Request $r)
    {
        return CaseLog::whereHas('resident', function ($q) use ($r) {
            $q->where('institution_id', $this->institution($r)->id);
        })->get();
    }
    public function storeCase(Request $r)
    {
        $d = $r->validate(['resident_id' => 'required|exists:residents,id', 'case_type' => 'required|string|max:255', 'procedure' => 'nullable|string|max:255', 'count' => 'nullable|integer|min:1', 'logged_at' => 'nullable|date']);
        $resident = Resident::findOrFail($d['resident_id']);
        abort_unless($resident->institution_id === $this->institution($r)->id, 403);
        return response()->json(CaseLog::create($d), 201);
    }
    public function accreditations(Request $r)
    {
        return $this->institution($r)->accreditations()->latest()->get();
    }
    /** Show one accreditation with its captured inspections + the institution's uploaded documents. */
    public function accreditationShow(Request $r, Accreditation $accreditation)
    {
        $i = $this->institution($r);
        abort_if($accreditation->institution_id !== $i->id, 403);
        return response()->json([
            'accreditation' => $accreditation->load('inspections'),
            'documents' => $i->documents()->get(),
            'checklist_items' => ChecklistItem::orderBy('sort_order')->get(),
        ]);
    }
    public function submitAccreditation(Request $r)
    {
        $i = $this->institution($r);
        $d = $r->validate(['checklist_snapshot' => 'required|array']);

        $missing = $i->documents()->whereIn('type', Accreditation::REQUIRED_DOC_TYPES)
            ->pluck('type')->all();
        $missing = array_values(array_diff(Accreditation::REQUIRED_DOC_TYPES, $missing));
        if (!empty($missing)) {
            return response()->json([
                'message' => 'Missing required supporting documents.',
                'missing_documents' => $missing,
            ], 422);
        }

        $latest = $i->accreditations()->latest()->first();

        // Submission is allowed when there is no prior accreditation (first application),
        // the latest was REJECTED (the institution is invited to re-apply), or the latest is an
        // approved/probationary cycle that is DUE FOR RENEWAL (valid_until is past or within the
        // 90-day renewal window). All other states block: a still-in-progress application
        // (pending/requirements_completed/inspection_scheduled/inspected) must resolve first, and a
        // valid cycle that is not yet due cannot be renewed early.
        $inProgress = $latest && in_array($latest->status, [
            Accreditation::STATUS_PENDING,
            Accreditation::STATUS_REQUIREMENTS_COMPLETED,
            Accreditation::STATUS_INSPECTION_SCHEDULED,
            Accreditation::STATUS_INSPECTED,
        ]);
        $rejected = $latest && $latest->status === Accreditation::STATUS_REJECTED;
        $dueForRenewal = $latest
            && in_array($latest->status, [Accreditation::STATUS_APPROVED, Accreditation::STATUS_PROBATIONARY])
            && $latest->valid_until
            && $latest->valid_until->lte(now()->addDays(90));

        if ($latest && !$rejected && !$dueForRenewal) {
            $message = $inProgress
                ? 'Your institution already has an accreditation application in progress. A new or renewal application cannot be submitted until the current one is resolved.'
                : 'Your current accreditation is still valid and not yet due for renewal. A renewal application can be submitted once the validity period ends or enters the 90-day renewal window.';
            return response()->json(['message' => $message], 422);
        }

        // Renewal when the previous cycle's validity has ended or is ending within 90 days.
        $isRenewal = $latest
            && $latest->valid_until
            && $latest->valid_until->lte(now()->addDays(90));

        $acc = Accreditation::create([
            'institution_id' => $i->id,
            'checklist_snapshot' => $d['checklist_snapshot'],
            'status' => 'pending',
            'submission_type' => $isRenewal ? 'renew' : 'new',
            'submitted_at' => now(),
        ]);
        return response()->json($acc, 201);
    }
    public function trainingOfficers(Request $r)
    {
        return $this->institution($r)->trainingOfficers()->with('user')->get();
    }
    public function storeTrainingOfficer(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:255', 'username' => 'required|string|max:255|unique:users,username', 'email' => 'required|email|unique:users,email', 'password' => 'required|string|min:8', 'phone' => 'nullable|string|max:50', 'telegram_handle' => 'nullable|string|max:255']);
        $i = $this->institution($r);
        $u = DB::transaction(function () use ($d, $i) {
            $u = User::create(['name' => $d['name'], 'username' => $d['username'], 'email' => $d['email'], 'password' => Hash::make($d['password']), 'status' => $this->autoApprove ? 'approved' : 'pending',                 'email_verified_at' => $this->autoApprove ? now() : null,]);
            $u->assignRole('TrainingOfficer');
            TrainingOfficer::create(['user_id' => $u->id, 'institution_id' => $i->id, 'phone' => $d['phone'] ?? null, 'telegram_handle' => $d['telegram_handle'] ?? null]);
            return $u;
        });
        if (!$this->autoApprove) {
            $u->sendEmailVerificationNotification();
        }
        return response()->json($u->load('roles', 'trainingOfficer'), 201);
    }
    public function residents(Request $r)
    {
        $i = $this->institution($r);
        return $i->residents()->with('user')->with(['transfers' => function ($q) {
            $q->latest();
        }])->get();
    }
    public function storeResident(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:255', 'username' => 'required|string|max:255|unique:users,username', 'email' => 'required|email|unique:users,email', 'password' => 'required|string|min:8', 'track' => 'required|in:AP,CP,AP_CP', 'date_accepted' => 'nullable|date|before_or_equal:today', 'age_at_enrollment' => 'nullable|integer|min:0']);
        $i = $this->institution($r);
        // Propagate/validate the resident's training track against the institution's
        // accredited tracks (t_f18a9c4a). AP_CP is treated as both AP and CP.
        $requested = $d['track'] === 'AP_CP' ? ['AP', 'CP'] : [$d['track']];
        $allowed = $i->accreditedTracks();
        if (!empty($allowed) && array_diff($requested, $allowed) !== []) {
            return response()->json([
                'message' => 'This institution is not accredited for the selected track. Accredited tracks: ' . (implode(', ', $allowed) ?: 'none') . '.',
            ], 422);
        }
        // Residents always start pending and are approved through the resident lifecycle workflow
        // (not auto-approved in dev like institution owners), so the approval gate is preserved.
        // However, email verification is skipped in non-production environments.
        $u = DB::transaction(function () use ($d, $i) {
            $u = User::create(['name' => $d['name'], 'username' => $d['username'], 'email' => $d['email'], 'password' => Hash::make($d['password']), 'status' => 'pending']);
            $u->assignRole('Resident');
            Resident::create(['user_id' => $u->id, 'institution_id' => $i->id, 'track' => $d['track'], 'date_accepted' => $d['date_accepted'] ?? null, 'age_at_enrollment' => $d['age_at_enrollment'] ?? null]);
            return $u;
        });
        if (!$this->autoApprove) {
            $u->sendEmailVerificationNotification();
        }
        return response()->json($u, 201);
    }
    public function requestTransfer(Request $r, Resident $resident)
    {
        $i = $this->institution($r);
        abort_unless($resident->institution_id === $i->id, 403);
        $d = $r->validate(['to_institution_id' => 'required|exists:institutions,id', 'reason' => 'nullable|string|max:255']);
        abort_if($d['to_institution_id'] == $i->id, 422, 'Destination must be another institution.');
        abort_unless(\App\Models\Institution::where('id', $d['to_institution_id'])->where('registration_status', 'approved')->exists(), 422);
        return response()->json(ResidentTransfer::create($d + ['resident_id' => $resident->id, 'from_institution_id' => $i->id, 'requested_by' => $r->user()->id, 'status' => ResidentTransfer::STATUS_PENDING]), 201);
    }
    public function incomingTransfers(Request $r)
    {
        return ResidentTransfer::where('to_institution_id', $this->institution($r)->id)->where('status', ResidentTransfer::STATUS_PENDING)->with(['resident.user', 'destination'])->get();
    }
    public function acceptTransfer(Request $r, ResidentTransfer $transfer)
    {
        $i = $this->institution($r);
        abort_unless($transfer->to_institution_id === $i->id && $transfer->status === ResidentTransfer::STATUS_PENDING, 403);
        DB::transaction(function () use ($transfer, $r, $i) {
            $transfer->resident->update(['institution_id' => $i->id]);
            $transfer->update(['status' => ResidentTransfer::STATUS_ACCEPTED, 'decided_by' => $r->user()->id, 'decided_at' => now()]);
        });
        return response()->json($transfer->fresh());
    }
    public function rejectTransfer(Request $r, ResidentTransfer $transfer)
    {
        abort_unless($transfer->to_institution_id === $this->institution($r)->id && $transfer->status === ResidentTransfer::STATUS_PENDING, 403);
        $transfer->update(['status' => ResidentTransfer::STATUS_DENIED, 'decided_by' => $r->user()->id, 'decided_at' => now()]);
        return response()->json($transfer);
    }
    public function consultantDocuments(Request $r, Consultant $consultant)
    {
        abort_unless($consultant->institution_id === $this->institution($r)->id, 403);
        return $consultant->documents;
    }
    public function storeConsultantDocument(Request $r, Consultant $consultant)
    {
        abort_unless($consultant->institution_id === $this->institution($r)->id, 403);
        $d = $r->validate(['type' => 'required|in:license,contract', 'file' => 'required|file|max:10240', 'expires_at' => 'nullable|date']);
        $path = $r->file('file')->store('consultant-documents/' . $consultant->id, 'public');
        $expiry = $d['type'] === 'license' ? today()->addYear()->startOfYear() : ($d['expires_at'] ?? null);
        return response()->json(ConsultantDocument::create(['consultant_id' => $consultant->id, 'type' => $d['type'], 'file_path' => $path, 'expires_at' => $expiry]), 201);
    }
    public function rotations(Request $r)
    {
        return RotationBlock::where('institution_id', $this->institution($r)->id)->with(['consultant', 'assignments.resident.user'])->orderBy('starts_at')->get();
    }
    public function storeRotation(Request $r)
    {
        $d = $r->validate(['title' => 'required|string|max:255', 'category' => 'required|string|max:255', 'starts_at' => 'required|date', 'ends_at' => 'required|date|after_or_equal:starts_at', 'consultant_id' => 'nullable|exists:consultants,id', 'notes' => 'nullable|string']);
        $i = $this->institution($r);
        abort_unless(!isset($d['consultant_id']) || Consultant::where('id', $d['consultant_id'])->where('institution_id', $i->id)->exists(), 403);
        abort_unless(\Carbon\Carbon::parse($d['starts_at'])->startOfMonth()->toDateString() === \Carbon\Carbon::parse($d['starts_at'])->toDateString() && \Carbon\Carbon::parse($d['ends_at'])->endOfMonth()->toDateString() === \Carbon\Carbon::parse($d['ends_at'])->toDateString(), 422, 'Rotations must cover a calendar month.');
        return response()->json(RotationBlock::create($d + ['institution_id' => $i->id]), 201);
    }
    public function storeRotationAssignment(Request $r, RotationBlock $rotation)
    {
        $i = $this->institution($r);
        abort_unless($rotation->institution_id === $i->id, 403);
        $d = $r->validate(['resident_id' => 'required|exists:residents,id']);
        abort_unless(Resident::where('id', $d['resident_id'])->where('institution_id', $i->id)->exists(), 403);
        return response()->json(RotationAssignment::create($d + ['rotation_block_id' => $rotation->id]), 201);
    }
    public function updateRotationAssignment(Request $r, RotationAssignment $assignment)
    {
        abort_unless($assignment->rotationBlock->institution_id === $this->institution($r)->id, 403);
        $d = $r->validate(['status' => 'required|in:assigned,completed', 'grade' => 'nullable|numeric|min:0']);
        $assignment->update($d);
        return response()->json($assignment);
    }

    /* -------------------- Consultant review (flowchart G/H/I) -------------------- */

    public function consultantReviews(Request $r)
    {
        $i = $this->institution($r);
        return ConsultantReview::whereHas('assignment.rotationBlock', function ($q) use ($i) {
            $q->where('institution_id', $i->id);
        })->with(['assignment.resident.user', 'consultant'])->orderByDesc('created_at')->get();
    }

    public function storeConsultantReview(Request $r)
    {
        $i = $this->institution($r);
        $d = $r->validate([
            'rotation_assignment_id' => 'required|exists:rotation_assignments,id',
            'consultant_id' => 'nullable|exists:consultants,id',
            'status' => 'required|in:validated,returned',
            'comments' => 'nullable|string',
        ]);
        $assignment = RotationAssignment::findOrFail($d['rotation_assignment_id']);
        abort_unless($assignment->rotationBlock->institution_id === $i->id, 403);
        if (!empty($d['consultant_id'])) {
            abort_unless(Consultant::where('id', $d['consultant_id'])->where('institution_id', $i->id)->exists(), 403);
        }
        // One review per assignment — upsert so re-review replaces the prior verdict.
        $review = ConsultantReview::updateOrCreate(
            ['rotation_assignment_id' => $assignment->id],
            ['consultant_id' => $d['consultant_id'] ?? null, 'status' => $d['status'], 'comments' => $d['comments'] ?? null]
        );
        return response()->json($review, 201);
    }

    /* -------------------- Consultant evaluation (flowchart M) -------------------- */

    public function consultantEvaluations(Request $r)
    {
        $i = $this->institution($r);
        return ConsultantEvaluation::whereHas('resident', function ($q) use ($i) {
            $q->where('institution_id', $i->id);
        })->with(['resident.user', 'consultant'])->orderByDesc('created_at')->get();
    }

    public function storeConsultantEvaluation(Request $r)
    {
        $i = $this->institution($r);
        $d = $r->validate([
            'resident_id' => 'required|exists:residents,id',
            'consultant_id' => 'nullable|exists:consultants,id',
            'period' => 'required|string|max:100',
            'ratings' => 'nullable|array',
            'comments' => 'nullable|string',
            'recommendation' => 'nullable|in:continue,remediate',
            'evaluated_at' => 'nullable|date',
        ]);
        $resident = Resident::findOrFail($d['resident_id']);
        abort_unless($resident->institution_id === $i->id, 403);
        if (!empty($d['consultant_id'])) {
            abort_unless(Consultant::where('id', $d['consultant_id'])->where('institution_id', $i->id)->exists(), 403);
        }
        return response()->json(ConsultantEvaluation::create($d), 201);
    }

    /* -------------------- Remediation plan (flowchart N/O) -------------------- */

    public function remediationPlans(Request $r)
    {
        $i = $this->institution($r);
        return RemediationPlan::whereHas('resident', function ($q) use ($i) {
            $q->where('institution_id', $i->id);
        })->with('resident.user')->orderByDesc('created_at')->get();
    }

    public function storeRemediationPlan(Request $r)
    {
        $i = $this->institution($r);
        $d = $r->validate([
            'resident_id' => 'required|exists:residents,id',
            'reason' => 'required|string',
            'plan' => 'required|string',
            'target_date' => 'nullable|date',
        ]);
        $resident = Resident::findOrFail($d['resident_id']);
        abort_unless($resident->institution_id === $i->id, 403);
        return response()->json(RemediationPlan::create(array_merge($d, ['status' => 'open'])), 201);
    }

    public function updateRemediationPlan(Request $r, RemediationPlan $plan)
    {
        abort_unless($plan->resident->institution_id === $this->institution($r)->id, 403);
        $d = $r->validate([
            'status' => 'required|in:open,in_progress,completed,closed',
            'plan' => 'nullable|string',
            'target_date' => 'nullable|date',
        ]);
        $plan->update($d);
        return response()->json($plan);
    }

    /* -------------------- Portfolio archive (flowchart U) -------------------- */

    public function portfolioArchives(Request $r)
    {
        $i = $this->institution($r);
        return PortfolioArchive::whereHas('resident', function ($q) use ($i) {
            $q->where('institution_id', $i->id);
        })->with('resident.user')->orderByDesc('created_at')->get();
    }

    public function storePortfolioArchive(Request $r)
    {
        $i = $this->institution($r);
        $d = $r->validate([
            'resident_id' => 'required|exists:residents,id',
            'summary' => 'nullable|string',
            'status' => 'nullable|in:archived,sealed',
            'archived_at' => 'nullable|date',
        ]);
        $resident = Resident::findOrFail($d['resident_id']);
        abort_unless($resident->institution_id === $i->id, 403);
        return response()->json(PortfolioArchive::create(array_merge($d, [
            'status' => $d['status'] ?? 'archived',
            'archived_at' => $d['archived_at'] ?? now()->toDateString(),
        ])), 201);
    }
}
