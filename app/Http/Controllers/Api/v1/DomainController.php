<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\{InstitutionDocument, Consultant, Quiz, QuizResult, ResearchPaper, CaseLog, Accreditation, Resident, Setting};
use Illuminate\Http\Request;

class DomainController extends Controller
{
    private function institution(Request $r)
    {
        $u = $r->user();
        if ($u->hasRole('TrainingOfficer')) return optional($u->trainingOfficer)->institution;
        if ($u->hasRole('Resident')) return optional($u->resident)->institution;
        abort(403, 'No institution context.');
    }
    public function me(Request $r)
    {
        return $r->user()->load('roles', 'trainingOfficer.institution.documents', 'resident.institution');
    }
    public function pending(Request $r)
    {
        return response()->json(['status' => $r->user()->status, 'email_verified' => $r->user()->hasVerifiedEmail()]);
    }
    public function dashboard(Request $r)
    {
        $i = $this->institution($r);
        return response()->json(['institution' => $i, 'documents' => $i->documents()->latest()->get(), 'expired_documents' => $i->documents()->whereDate('expires_at', '<', today())->count(), 'accreditations' => $i->accreditations()->latest()->get(), 'unread_notifications' => $r->user()->unreadNotifications()->count()]);
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
}
