<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\{AccreditationInspection, ChecklistItem, CorrectiveAction, CorrectiveActionEvidence, CorrectiveActionStatusLog, Finding, Institution};
use App\Notifications\StatusChangeNotification;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Storage};

class FindingsController extends Controller
{
    /**
     * List findings.
     *  - Staff (Admin/Accreditor): all findings (optionally ?inspection_id=).
     *  - Training Officer: only findings for their own institution.
     */
    public function index(Request $r)
    {
        $q = Finding::query()->with(['checklistItem', 'raisedBy', 'actions.statusLogs', 'inspection.accreditation.institution']);

        if ($r->user()->hasRole('TrainingOfficer') || $r->user()->hasRole('TrainingInstitution')) {
            $instId = $this->institutionId($r);
            $q->whereHas('inspection.accreditation', function ($q) use ($instId) {
                $q->where('institution_id', $instId);
            });
        }
        if ($r->filled('inspection_id')) {
            $q->where('accreditation_inspection_id', $r->input('inspection_id'));
        }

        return $q->orderByDesc('created_at')->get();
    }

    /**
     * List inspections so a reviewer can raise findings against one.
     * Staff (Admin/Accreditor) sees all inspections; Training Officer sees only their institution's.
     */
    public function inspections(Request $r)
    {
        $q = AccreditationInspection::query()->with(['accreditation.institution', 'accreditor']);

        if ($r->user()->hasRole('TrainingOfficer') || $r->user()->hasRole('TrainingInstitution')) {
            $instId = $this->institutionId($r);
            $q->whereHas('accreditation', fn($q) => $q->where('institution_id', $instId));
        }

        return $q->orderByDesc('created_at')->get();
    }

    /** Reviewer (Admin/Accreditor) raises a finding from an inspection. */
    public function store(Request $r)
    {
        abort_unless($r->user()->hasRole('Admin') || $r->user()->hasRole('Accreditor'), 403);
        $d = $r->validate([
            'accreditation_inspection_id' => 'required|exists:accreditation_inspections,id',
            'checklist_item_id' => 'nullable|exists:checklist_items,id',
            'title' => 'required|string',
            'description' => 'required|string',
            'severity' => 'nullable|in:major,minor',
        ]);
        $finding = Finding::create(array_merge($d, [
            'status' => Finding::STATUS_OPEN,
            'raised_by' => $r->user()->id,
        ]));
        return response()->json($finding, 201);
    }

    /**
     * Reviewer (Admin/Accreditor) approves a finding.
     * Per the accreditation workflow, approving a finding raised from a
     * non-compliant checklist item marks that item compliant on the
     * inspection (the deficiency has been accepted/closed by the reviewer).
     */
    public function approve(Request $r, Finding $finding)
    {
        abort_unless($r->user()->hasRole('Admin') || $r->user()->hasRole('Accreditor'), 403);

        if ($finding->status === Finding::STATUS_REJECTED) {
            return response()->json(['message' => 'A rejected finding cannot be approved.'], 422);
        }

        DB::transaction(function () use ($finding, $r) {
            $finding->update([
                'status' => Finding::STATUS_RESOLVED,
            ]);

            // Mark the linked checklist item compliant on this inspection.
            if ($finding->checklist_item_id && $finding->inspection) {
                $inspection = $finding->inspection;
                $answers = $inspection->answers ?? [];
                $key = (string) $finding->checklist_item_id;
                $answers[$key] = [
                    'compliant' => true,
                    'notes' => ($answers[$key]['notes'] ?? '') . "\n[Approved by reviewer — marked compliant]",
                ];
                $inspection->update(['answers' => $answers]);
            }
        });

        return response()->json($finding->fresh());
    }

    /**
     * Institution (Training Officer) lists corrective actions for their findings.
     * Reviewer sees all (optionally ?finding_id=).
     */
    public function actions(Request $r)
    {
        $q = CorrectiveAction::query()->with(['finding.inspection.accreditation.institution', 'evidence', 'statusLogs']);

        if ($r->user()->hasRole('TrainingOfficer') || $r->user()->hasRole('TrainingInstitution')) {
            $instId = $this->institutionId($r);
            $q->whereHas('finding.inspection.accreditation', function ($q) use ($instId) {
                $q->where('institution_id', $instId);
            });
        } elseif ($r->filled('finding_id')) {
            $q->where('finding_id', $r->input('finding_id'));
        }

        return $q->orderByDesc('created_at')->get();
    }

    /** Institution (Training Officer) proposes a corrective action for a finding. */
    public function storeAction(Request $r)
    {
        abort_unless($r->user()->hasRole('TrainingOfficer') || $r->user()->hasRole('TrainingInstitution'), 403);
        $d = $r->validate([
            'finding_id' => 'required|exists:findings,id',
            'action_plan' => 'required|string',
            'due_date' => 'nullable|date',
            'assigned_to' => 'nullable|exists:users,id',
        ]);
        $finding = Finding::findOrFail($d['finding_id']);
        abort_unless(
            data_get($finding, 'inspection.accreditation.institution_id') === $this->institutionId($r),
            403,
            'This finding does not belong to your institution.'
        );

        $action = DB::transaction(function () use ($d, $r, $finding) {
            $action = CorrectiveAction::create(array_merge($d, [
                'status' => CorrectiveAction::STATUS_OPEN,
                'created_by' => $r->user()->id,
            ]));
            $this->logStatus($action, CorrectiveAction::STATUS_OPEN, null, $r->user()->id);
            // Keep the parent finding's progress in sync.
            if ($finding->status === Finding::STATUS_OPEN) {
                $finding->update(['status' => Finding::STATUS_IN_PROGRESS]);
            }
            return $action;
        });

        return response()->json($action, 201);
    }

    /** Institution uploads evidence for a corrective action (multipart). */
    public function uploadEvidence(Request $r, CorrectiveAction $action)
    {
        abort_unless($r->user()->hasRole('TrainingOfficer') || $r->user()->hasRole('TrainingInstitution'), 403);
        abort_unless(
            $action->finding->inspection->accreditation->institution_id === $this->institutionId($r),
            403
        );
        $d = $r->validate(['file' => 'required|file|max:10240']);
        $path = $d['file']->store('corrective-evidence/' . $action->id, 'public');
        $evidence = CorrectiveActionEvidence::create([
            'corrective_action_id' => $action->id,
            'file_path' => $path,
            'original_name' => $d['file']->getClientOriginalName(),
            'uploaded_by' => $r->user()->id,
        ]);
        return response()->json($evidence, 201);
    }

    /** Institution marks a corrective action resolved (ready for reviewer verification). */
    public function resolve(Request $r, CorrectiveAction $action)
    {
        abort_unless($r->user()->hasRole('TrainingOfficer') || $r->user()->hasRole('TrainingInstitution'), 403);
        abort_unless(
            $action->finding->inspection->accreditation->institution_id === $this->institutionId($r),
            403
        );
        abort_unless($action->status !== CorrectiveAction::STATUS_VERIFIED, 422, 'Already verified.');

        DB::transaction(function () use ($action, $r) {
            $action->update(['status' => CorrectiveAction::STATUS_RESOLVED]);
            $this->logStatus($action, CorrectiveAction::STATUS_RESOLVED, null, $r->user()->id);
        });

        DB::afterCommit(function () use ($action) {
            $acc = $action->finding->inspection->accreditation;
            $svc = new NotificationService();
            foreach (NotificationService::institutionRecipients($acc->institution) as $recipient) {
                $svc->notify($recipient, new StatusChangeNotification('corrective_action_resolved', $acc, "Action #{$action->id} resolved"), 'status_change', ['database', 'email']);
            }
        });

        return response()->json($action);
    }

    /**
     * Reviewer (Admin/Accreditor) verifies a corrective action.
     *  - decision=verified  -> status verified (comment optional)
     *  - decision=rejected  -> status reopened (comment REQUIRED, 422 if missing)
     * Both persisted inside a transaction with an append-only status log.
     */
    public function verify(Request $r, CorrectiveAction $action)
    {
        abort_unless($r->user()->hasRole('Admin') || $r->user()->hasRole('Accreditor'), 403);
        $d = $r->validate([
            'decision' => 'required|in:verified,rejected',
            'comment' => 'nullable|string|max:5000',
        ]);

        if ($d['decision'] === 'rejected' && empty($d['comment'])) {
            return response()->json([
                'message' => 'A comment is required when rejecting a corrective action.',
            ], 422);
        }

        $newStatus = $d['decision'] === 'verified'
            ? CorrectiveAction::STATUS_VERIFIED
            : CorrectiveAction::STATUS_REOPENED;

        DB::transaction(function () use ($action, $newStatus, $d, $r) {
            $action->update(['status' => $newStatus]);
            $this->logStatus($action, $newStatus, $d['comment'] ?? null, $r->user()->id);
        });

        DB::afterCommit(function () use ($action, $newStatus) {
            $acc = $action->finding->inspection->accreditation;
            $svc = new NotificationService();
            foreach (NotificationService::institutionRecipients($acc->institution) as $recipient) {
                $svc->notify($recipient, new StatusChangeNotification("corrective_action_{$newStatus}", $acc, "Action #{$action->id} {$newStatus}"), 'status_change', ['database', 'email']);
            }
        });

        return response()->json($action);
    }

    /* ----------------------------- helpers ----------------------------- */

    private function institutionId(Request $r): int
    {
        $i = (new Institution());
        // Reuse the DomainController institution() resolution without coupling.
        $u = $r->user();
        if ($u->hasRole('TrainingInstitution')) return $u->institution->id;
        if ($u->hasRole('TrainingOfficer')) return optional($u->trainingOfficer)->institution_id;
        abort(403, 'No institution context.');
    }

    private function logStatus(CorrectiveAction $action, string $status, ?string $comment, ?int $actorId)
    {
        CorrectiveActionStatusLog::create([
            'corrective_action_id' => $action->id,
            'status' => $status,
            'comment' => $comment,
            'actor_id' => $actorId,
            'logged_at' => now(),
        ]);
    }
}
