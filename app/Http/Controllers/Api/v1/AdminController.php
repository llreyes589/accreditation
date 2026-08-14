<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\{User, Institution, Accreditation, Setting, ChecklistItem, AccreditationDecision};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminController extends Controller
{
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
        $d = $r->validate(['name' => 'required|string|max:255', 'username' => 'required|string|max:255|unique:users,username', 'email' => 'required|email|unique:users,email', 'password' => 'required|string|min:8', 'role' => 'required|in:Admin,Accreditor']);
        $u = User::create(['name' => $d['name'], 'username' => $d['username'], 'email' => $d['email'], 'password' => Hash::make($d['password']), 'status' => 'pending']);
        $u->assignRole($d['role']);
        $u->sendEmailVerificationNotification();
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
                ]);
            }

            $accreditation->decisions()->create([
                'outcome' => $outcome,
                'notes' => $d['notes'] ?? null,
                'valid_from' => $outcome === AccreditationDecision::OUTCOME_REJECTED ? null : today(),
                'valid_until' => $outcome === AccreditationDecision::OUTCOME_REJECTED ? null : ($d['valid_until'] ?? null),
                'decided_by' => $r->user()->id,
            ]);

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

    public function scheduleInspection(Request $r, Accreditation $accreditation)
    {
        // Inspection is scheduled after the admin marks requirements complete (and before approval).
        if ($accreditation->status !== Accreditation::STATUS_REQUIREMENTS_COMPLETED) {
            return response()->json([
                'message' => 'Requirements must be marked complete before an inspection can be scheduled.',
            ], 422);
        }
        $d = $r->validate(['inspection_scheduled_at' => 'required|date|after:today']);
        $accreditation->update(['inspection_scheduled_at' => $d['inspection_scheduled_at'], 'status' => 'inspection_scheduled']);
        return response()->json($accreditation->fresh());
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
            'accreditation' => $accreditation->load(['institution', 'inspections']),
            'documents' => $accreditation->institution->documents()->get(),
            'checklist_items' => ChecklistItem::orderBy('sort_order')->get(),
        ]);
    }
    public function settings(Request $r)
    {
        $r->validate(['settings.accreditation_years' => 'nullable|integer|in:1,3']);
        foreach ($r->validate(['settings' => 'required|array'])['settings'] as $k => $v) if (in_array($k, ['track_durations', 'promotion_thresholds', 'accreditation_years'])) Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        return response()->json(Setting::all());
    }
}
