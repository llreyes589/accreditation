<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\{User, Institution, Accreditation, Setting};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminController extends Controller
{
    public function pending()
    {
        // Accreditations still in progress (everything except the terminal approved/rejected states).
        $accreditations = Accreditation::whereNotIn('status', [
            Accreditation::STATUS_APPROVED,
            Accreditation::STATUS_REJECTED,
        ])->with('institution')->get();
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
    public function approveAccreditation(Request $r, Accreditation $accreditation)
    {
        $years = (int)Setting::getValue('accreditation_years', 1);
        $years = in_array($years, [1, 3], true) ? $years : 1;
        $accreditation->update(['status' => 'approved', 'approved_by' => $r->user()->id, 'valid_from' => today(), 'valid_until' => today()->addYears($years)]);
        return response()->json($accreditation->fresh());
    }
    public function rejectAccreditation(Accreditation $accreditation)
    {
        $accreditation->update(['status' => 'rejected']);
        return response()->json($accreditation->fresh());
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
    public function settings(Request $r)
    {
        $r->validate(['settings.accreditation_years' => 'nullable|integer|in:1,3']);
        foreach ($r->validate(['settings' => 'required|array'])['settings'] as $k => $v) if (in_array($k, ['track_durations', 'promotion_thresholds', 'accreditation_years'])) Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        return response()->json(Setting::all());
    }
}
