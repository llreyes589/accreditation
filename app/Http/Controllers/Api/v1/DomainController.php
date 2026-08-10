<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\{InstitutionDocument, Consultant, ConsultantDocument, Quiz, QuizResult, ResearchPaper, CaseLog, Accreditation, Resident, ResidentTransfer, RotationBlock, RotationAssignment, Setting, TrainingOfficer, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash};

class DomainController extends Controller
{
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
        return response()->json($n);
    }
    public function documents(Request $r)
    {
        return $this->institution($r)->documents;
    }
    public function storeDocument(Request $r)
    {
        $d = $r->validate(['type' => 'required|in:license,permit,accreditation,other', 'file' => 'required|file|max:10240']);
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
    public function submitAccreditation(Request $r)
    {
        $d = $r->validate(['checklist_snapshot' => 'required|array']);
        return response()->json(Accreditation::create(['institution_id' => $this->institution($r)->id, 'checklist_snapshot' => $d['checklist_snapshot'], 'status' => 'pending']), 201);
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
            $u = User::create(['name' => $d['name'], 'username' => $d['username'], 'email' => $d['email'], 'password' => Hash::make($d['password']), 'status' => 'pending']);
            $u->assignRole('TrainingOfficer');
            TrainingOfficer::create(['user_id' => $u->id, 'institution_id' => $i->id, 'phone' => $d['phone'] ?? null, 'telegram_handle' => $d['telegram_handle'] ?? null]);
            return $u;
        });
        $u->sendEmailVerificationNotification();
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
        $u = DB::transaction(function () use ($d, $i) {
            $u = User::create(['name' => $d['name'], 'username' => $d['username'], 'email' => $d['email'], 'password' => Hash::make($d['password']), 'status' => 'pending']);
            $u->assignRole('Resident');
            Resident::create(['user_id' => $u->id, 'institution_id' => $i->id, 'track' => $d['track'], 'date_accepted' => $d['date_accepted'] ?? null, 'age_at_enrollment' => $d['age_at_enrollment'] ?? null]);
            return $u;
        });
        $u->sendEmailVerificationNotification();
        return response()->json($u, 201);
    }
    public function requestTransfer(Request $r, Resident $resident)
    {
        $i = $this->institution($r);
        abort_unless($resident->institution_id === $i->id, 403);
        $d = $r->validate(['to_institution_id' => 'required|exists:institutions,id', 'reason' => 'nullable|string|max:255']);
        abort_if($d['to_institution_id'] == $i->id, 422, 'Destination must be another institution.');
        abort_unless(\App\Models\Institution::where('id', $d['to_institution_id'])->where('registration_status', 'approved')->exists(), 422);
        return response()->json(ResidentTransfer::create($d + ['resident_id' => $resident->id, 'from_institution_id' => $i->id, 'requested_by' => $r->user()->id]), 201);
    }
    public function incomingTransfers(Request $r)
    {
        return ResidentTransfer::where('to_institution_id', $this->institution($r)->id)->where('status', 'pending')->with(['resident.user', 'destination'])->get();
    }
    public function acceptTransfer(Request $r, ResidentTransfer $transfer)
    {
        $i = $this->institution($r);
        abort_unless($transfer->to_institution_id === $i->id && $transfer->status === 'pending', 403);
        DB::transaction(function () use ($transfer, $r, $i) {
            $transfer->resident->update(['institution_id' => $i->id]);
            $transfer->update(['status' => 'accepted', 'decided_by' => $r->user()->id, 'decided_at' => now()]);
        });
        return response()->json($transfer->fresh());
    }
    public function rejectTransfer(Request $r, ResidentTransfer $transfer)
    {
        abort_unless($transfer->to_institution_id === $this->institution($r)->id && $transfer->status === 'pending', 403);
        $transfer->update(['status' => 'rejected', 'decided_by' => $r->user()->id, 'decided_at' => now()]);
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
        abort_unless(\Carbon\Carbon::parse($d['starts_at'])->startOfMonth()->eq(\Carbon\Carbon::parse($d['starts_at'])) && \Carbon\Carbon::parse($d['ends_at'])->endOfMonth()->eq(\Carbon\Carbon::parse($d['ends_at'])), 422, 'Rotations must cover a calendar month.');
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
}
