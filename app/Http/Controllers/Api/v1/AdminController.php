<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\{User, Institution, Accreditation, Setting, ChecklistItem, AccreditationDecision, AccreditationInspection, InspectionAccreditor};
use App\Notifications\{DecisionIssuedNotification, InspectionScheduledReminder};
use App\Services\NotificationService;
use App\Services\InspectionAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminController extends Controller
{
    private $assignments;
    public function __construct(InspectionAssignmentService $assignments)
    {
        $this->assignments =  $assignments;
    }
    public function pending()
    {
        // Accreditations still in progress (everything except the terminal approved/rejected/probationary states).
        $accreditations = Accreditation::whereNotIn('status', [
            Accreditation::STATUS_APPROVED,
            Accreditation::STATUS_PROBATIONARY,
            Accreditation::STATUS_REJECTED,
        ])->with(['institution', 'decisions'])->get();
        return response()->json(['users' => User::where('status', 'pending')->with('roles')->get(), 'institutions' => Institution::where('registration_status', 'pending')->get(), 'accreditations' => $accreditations]);
    }
    public function createStaff(Request $r)
    {
        $autoApprove = !app()->environment('production');

        $d = $r->validate(['name' => 'required|string|max:255', 'username' => 'required|string|max:255|unique:users,username', 'email' => 'required|email|unique:users,email', 'password' => 'required|string|min:8', 'role' => 'required|in:Admin,Accreditor']);
        $u = User::create(['name' => $d['name'], 'username' => $d['username'], 'email' => $d['email'], 'password' => Hash::make($d['password']), 'status' => $autoApprove ? 'approved' : 'pending',  'email_verified_at' => $autoApprove ? now() : null]);
        $u->assignRole($d['role']);
        if (!$autoApprove) {
            $u->sendEmailVerificationNotification();
        }
        return response()->json($u->load('roles'), 201);
    }
    public function approveUser(Request $r, User $user)
    {
        $user->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $r->user()->id, 'rejection_reason' => null]);
        if ($user->trainingOfficer) $user->trainingOfficer->institution->update(['registration_status' => 'approved', 'approved_at' => now(), 'approved_by' => $r->user()->id, 'rejection_reason' => null]);
        if ($user->institution) $user->institution->update(['registration_status' => 'approved', 'approved_at' => now(), 'approved_by' => $r->user()->id, 'rejection_reason' => null]);
        return response()->json($user);
    }
    public function rejectUser(Request $r, User $user)
    {
        $d = $r->validate(['reason' => 'required|string|max:255']);
        $user->update(['status' => 'rejected', 'rejection_reason' => $d['reason']]);
        if ($user->trainingOfficer) $user->trainingOfficer->institution->update(['registration_status' => 'rejected', 'rejection_reason' => $d['reason']]);
        if ($user->institution) $user->institution->update(['registration_status' => 'rejected', 'rejection_reason' => $d['reason']]);
        return response()->json($user);
    }
    public function recordDecision(Request $r, Accreditation $accreditation)
    {
        $d = $r->validate([
            'outcome' => 'required|in:approved,probationary,rejected',
            'notes' => 'nullable|string|max:2000',
            'valid_until' => 'nullable|date|after:today',
            'recommendation' => 'nullable|in:3_years,3_years_conditional,1_year',
            'vote_count' => 'nullable|integer|min:0',
            'track' => 'nullable|in:AP,CP,APCP',
        ]);

        return DB::transaction(function () use ($r, $accreditation, $d) {
            $outcome = $d['outcome'];

            if ($outcome === AccreditationDecision::OUTCOME_REJECTED) {
                $accreditation->update(['status' => Accreditation::STATUS_REJECTED]);
            } else {
                $years = (int) Setting::getValue('accreditation_years', 1);
                $years = in_array($years, [1, 3], true) ? $years : 1;
                $validUntil = $d['valid_until'] ?? today()->addYears($years);
                $accreditation->update([
                    'status' => $outcome === AccreditationDecision::OUTCOME_PROBATIONARY
                        ? Accreditation::STATUS_PROBATIONARY
                        : Accreditation::STATUS_APPROVED,
                    'approved_by' => $r->user()->id,
                    'valid_from' => today(),
                    'valid_until' => $validUntil,
                    'track' => $d['track'] ?? null,
                ]);
            }

            $accreditation->decisions()->create([
                'outcome' => $outcome,
                'recommendation' => $d['recommendation'] ?? null,
                'vote_count' => $d['vote_count'] ?? null,
                'notes' => $d['notes'] ?? null,
                'valid_from' => $outcome === AccreditationDecision::OUTCOME_REJECTED ? null : today(),
                'valid_until' => $outcome === AccreditationDecision::OUTCOME_REJECTED ? null : ($d['valid_until'] ?? null),
                'decided_by' => $r->user()->id,
            ]);

            // Dispatch after commit: notify the institution + reviewers of the decision.
            DB::afterCommit(function () use ($accreditation, $outcome) {
                $svc = new NotificationService();
                foreach (NotificationService::institutionRecipients($accreditation->institution) as $recipient) {
                    $svc->notify($recipient, new DecisionIssuedNotification($accreditation, $outcome), 'status_change', ['database', 'email']);
                }
            });

            return response()->json($accreditation->fresh()->load('decisions'));
        });
    }

    public function listDecisions(Request $r, Accreditation $accreditation)
    {
        return response()->json([
            'accreditation_id' => $accreditation->id,
            'decisions' => $accreditation->decisions()->with('decider')->get(),
        ]);
    }

    /**
     * Move an inspected accreditation into the deliberation phase.
     * Per the workflow, once in deliberation the assigned accreditor is locked
     * out of the checklist and only an admin (chairman) may edit it.
     */
    public function startDeliberation(Request $r, Accreditation $accreditation)
    {
        if ($accreditation->status !== Accreditation::STATUS_INSPECTED) {
            return response()->json([
                'message' => 'Deliberation can only begin after the inspection has been submitted (status: inspected).',
            ], 422);
        }

        $accreditation->update(['status' => Accreditation::STATUS_DELIBERATION]);

        return response()->json($accreditation->fresh()->load('decisions'));
    }

    /**
     * Admin (chairman) edits the captured checklist during deliberation (or while
     * still inspected). Accreditors are locked out; this endpoint is admin-only
     * and only permitted in the deliberation/inspected phases.
     */
    public function editChecklist(Request $r, Accreditation $accreditation)
    {
        if (! in_array($accreditation->status, [
            Accreditation::STATUS_DELIBERATION,
            Accreditation::STATUS_INSPECTED,
        ], true)) {
            return response()->json([
                'message' => 'The checklist can only be edited by an admin during deliberation (or while inspected).',
            ], 422);
        }

        $d = $r->validate([
            'answers' => 'required|array',
            'answers.*.compliant' => 'required|boolean',
            'answers.*.notes' => 'nullable|string|max:5000',
        ]);

        $inspection = $accreditation->inspections()
            ->where('status', AccreditationInspection::STATUS_SUBMITTED)
            ->latest()
            ->first();

        if (! $inspection) {
            return response()->json(['message' => 'No submitted inspection found for this accreditation.'], 422);
        }

        // Merge into the existing answers so the admin can adjust individual items.
        $answers = $inspection->answers ?? [];
        foreach ($d['answers'] as $itemId => $answer) {
            $key = (string) $itemId;
            $answers[$key] = [
                'compliant' => (bool) $answer['compliant'],
                'notes' => $answer['notes'] ?? ($answers[$key]['notes'] ?? null),
            ];
        }
        $inspection->update(['answers' => $answers]);

        return response()->json($inspection->fresh());
    }

    public function scheduleInspection(Request $r, Accreditation $accreditation)
    {
        // Inspection is scheduled after the admin marks requirements complete (and before approval).
        if ($accreditation->status !== Accreditation::STATUS_REQUIREMENTS_COMPLETED) {
            return response()->json([
                'message' => 'Requirements must be marked complete before an inspection can be scheduled.',
            ], 422);
        }
        $d = $r->validate([
            'inspection_scheduled_at' => 'required|date|after:today',
            'accreditor_ids' => 'nullable|array',
            'accreditor_ids.*' => 'integer|exists:users,id',
            'lead_id' => 'nullable|integer|exists:users,id',
        ]);

        $inspection = DB::transaction(function () use ($r, $accreditation, $d) {
            $accreditation->update([
                'inspection_scheduled_at' => $d['inspection_scheduled_at'],
                'status' => 'inspection_scheduled',
            ]);

            // Create the inspection row up front so accreditors can be assigned
            // before the on-site visit (assignable during or after scheduling).
            $inspection = $accreditation->inspections()->updateOrCreate(
                ['status' => AccreditationInspection::STATUS_PENDING],
                ['inspection_scheduled_at' => $d['inspection_scheduled_at']],
            );

            // Optional: assign accreditors at schedule time.
            $leadId = $d['lead_id'] ?? null;
            $accreditorIds = $d['accreditor_ids'] ?? [];
            if ($leadId !== null && ! in_array($leadId, $accreditorIds, true)) {
                $accreditorIds[] = $leadId;
            }
            foreach ($accreditorIds as $userId) {
                $user = User::find($userId);
                if (! $user) {
                    continue;
                }
                $role = ($leadId !== null && $userId == $leadId)
                    ? \App\Models\InspectionAccreditor::ROLE_LEAD
                    : \App\Models\InspectionAccreditor::ROLE_MEMBER;
                $this->assignments->assign($inspection, $user, $role, $inspection->id);
            }

            return $inspection;
        });

        DB::afterCommit(function () use ($accreditation, $inspection) {
            if (!$inspection) return;
            $svc = new NotificationService();
            foreach (NotificationService::institutionRecipients($accreditation->institution) as $recipient) {
                $svc->notify($recipient, new InspectionScheduledReminder($inspection), 'deadline_reminder', ['database', 'email']);
            }
        });
        return response()->json($accreditation->fresh()->load('inspections'));
    }

    /** Assign an accreditor (lead or member) to a scheduled inspection. */
    public function assignAccreditor(Request $r, Accreditation $accreditation, AccreditationInspection $inspection)
    {
        if ($inspection->accreditation_id !== $accreditation->id) {
            return response()->json(['message' => 'Inspection does not belong to this accreditation.'], 422);
        }
        $d = $r->validate([
            'user_id' => 'required|integer|exists:users,id',
            'role' => 'nullable|in:lead,member',
        ]);
        $user = User::findOrFail($d['user_id']);
        $role = $d['role'] ?? \App\Models\InspectionAccreditor::ROLE_MEMBER;

        $this->assignments->assign($inspection, $user, $role, $inspection->id);

        return response()->json($inspection->fresh()->load('accreditors'), 201);
    }

    /** Change the lead accreditor of a scheduled inspection. */
    public function changeLeadAccreditor(Request $r, Accreditation $accreditation, AccreditationInspection $inspection)
    {
        if ($inspection->accreditation_id !== $accreditation->id) {
            return response()->json(['message' => 'Inspection does not belong to this accreditation.'], 422);
        }
        $d = $r->validate(['user_id' => 'required|integer|exists:users,id']);
        $user = User::findOrFail($d['user_id']);

        $this->assignments->changeLead($inspection, $user, $inspection->id);

        return response()->json($inspection->fresh()->load('accreditors'));
    }

    /** Remove an accreditor from a scheduled inspection. */
    public function removeAccreditor(Request $r, Accreditation $accreditation, AccreditationInspection $inspection, int $userId)
    {
        if ($inspection->accreditation_id !== $accreditation->id) {
            return response()->json(['message' => 'Inspection does not belong to this accreditation.'], 422);
        }
        $assignment = $inspection->accreditorAssignments()
            ->where('user_id', $userId)
            ->firstOrFail();
        $this->assignments->remove($inspection, $assignment);

        return response()->json($inspection->fresh()->load('accreditors'));
    }

    public function markRequirementsCompleted(Request $r, Accreditation $accreditation)
    {
        if ($accreditation->status !== Accreditation::STATUS_PENDING) {
            return response()->json([
                'message' => 'Only a pending application can be marked requirements complete.',
            ], 422);
        }
        $accreditation->update(['status' => Accreditation::STATUS_REQUIREMENTS_COMPLETED]);
        return response()->json($accreditation->fresh());
    }
    /** Read-only detail for an admin: uploaded documents + the accreditor's captured checklist. */
    public function accreditationShow(Request $r, Accreditation $accreditation)
    {
        return response()->json([
            'accreditation' => $accreditation->load(['institution', 'inspections.accreditors']),
            'documents' => $accreditation->institution->documents()->get(),
            'checklist_items' => ChecklistItem::orderBy('sort_order')->get(),
        ]);
    }
    /** Inspection detail with its assigned accreditors (lead + members). */
    public function inspectionShow(Request $r, Accreditation $accreditation, AccreditationInspection $inspection)
    {
        if ($inspection->accreditation_id !== $accreditation->id) {
            return response()->json(['message' => 'Inspection does not belong to this accreditation.'], 422);
        }
        return response()->json([
            'inspection' => $inspection->load('accreditors'),
        ]);
    }

    /** List users with the Accreditor role (for assignment dropdowns). */
    public function listAccreditors(Request $r)
    {
        $accreditors = \App\Models\User::whereHas('roles', function ($q) {
            $q->where('name', 'Accreditor');
        })->select('id', 'name', 'email')->orderBy('name')->get();

        return response()->json(['accreditors' => $accreditors]);
    }
    public function settings(Request $r)
    {
        $r->validate(['settings.accreditation_years' => 'nullable|integer|in:1,3']);
        foreach ($r->validate(['settings' => 'required|array'])['settings'] as $k => $v) if (in_array($k, ['track_durations', 'promotion_thresholds', 'accreditation_years'])) Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        return response()->json(Setting::all());
    }
}
