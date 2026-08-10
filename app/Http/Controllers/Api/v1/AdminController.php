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
        return response()->json(['users' => User::where('status', 'pending')->with('roles')->get(), 'institutions' => Institution::where('registration_status', 'pending')->get(), 'accreditations' => Accreditation::where('status', 'pending')->with('institution')->get()]);
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
    public function approveAccreditation(Request $r, Accreditation $a)
    {
        $years = (int)Setting::getValue('accreditation_years', 1);
        $years = in_array($years, [1, 3], true) ? $years : 1;
        $a->update(['status' => 'approved', 'approved_by' => $r->user()->id, 'valid_from' => today(), 'valid_until' => today()->addYears($years)]);
        return response()->json($a);
    }
    public function rejectAccreditation(Accreditation $a)
    {
        $a->update(['status' => 'rejected']);
        return response()->json($a);
    }
    public function settings(Request $r)
    {
        $r->validate(['settings.accreditation_years' => 'nullable|integer|in:1,3']);
        foreach ($r->validate(['settings' => 'required|array'])['settings'] as $k => $v) if (in_array($k, ['track_durations', 'promotion_thresholds', 'accreditation_years'])) Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        return response()->json(Setting::all());
    }
}
