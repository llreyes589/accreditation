<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\{User, Institution, TrainingOfficer, Resident};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Hash, DB};
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $r)
    {
        $d = $r->validate(['username' => 'required|string', 'password' => 'required|string']);
        $u = User::where('username', $d['username'])->first();
        if (!$u || !Hash::check($d['password'], $u->password)) throw ValidationException::withMessages(['username' => ['The provided credentials are incorrect.']]);
        return response()->json(['user' => $u->load('roles', 'institution', 'trainingOfficer.institution', 'resident.institution'), 'token' => $u->createToken('auth-token')->plainTextToken]);
    }
    public function registerInstitution(Request $r)
    {
        $d = $r->validate([
            'institution.name' => 'required|string|max:255',
            'institution.address' => 'nullable|string',
            'institution.hospital_level' => 'nullable|string|max:255',
            'institution.laboratory_level' => 'nullable|string|max:255',
            'institution.bsf_category' => 'nullable|string|max:255',
            'institution.director' => 'nullable|string|max:255',
            'institution.chairman' => 'nullable|string|max:255',
            'institution.contact_number' => 'nullable|string|max:50',
            'institution.email' => 'nullable|email|max:255',
            'institution.year_program_opened' => 'nullable|integer|min:1900|max:2100',
            'institution.region' => 'nullable|string|max:255',
            'institution.province' => 'nullable|string|max:255',
            'institution.city' => 'nullable|string|max:255',
            'institution.latitude' => 'nullable|numeric',
            'institution.longitude' => 'nullable|numeric',
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users,username',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'phone' => 'nullable|string|max:50',
            'telegram_handle' => 'nullable|string|max:255',
        ]);
        $u = DB::transaction(function () use ($d) {
            $u = User::create(['name' => $d['name'], 'username' => $d['username'], 'email' => $d['email'], 'password' => Hash::make($d['password']), 'status' => 'pending']);
            $u->assignRole('TrainingInstitution');
            $i = Institution::create(array_merge($d['institution'], ['registration_status' => 'pending', 'user_id' => $u->id]));
            return $u;
        });
        $u->sendEmailVerificationNotification();
        return response()->json(['message' => 'Registration submitted. Verify your email and wait for Admin approval.', 'user' => $u], 201);
    }
    public function registerResident(Request $r)
    {
        $d = $r->validate(['institution_id' => 'required|exists:institutions,id', 'name' => 'required|string|max:255', 'username' => 'required|string|max:255|unique:users,username', 'email' => 'required|email|unique:users,email', 'password' => 'required|string|min:8|confirmed', 'track' => 'required|in:AP,CP,AP_CP', 'date_accepted' => 'nullable|date|before_or_equal:today', 'age_at_enrollment' => 'nullable|integer|min:0']);
        $i = Institution::findOrFail($d['institution_id']);
        if ($i->registration_status !== 'approved') return response()->json(['message' => 'Residents may only register with an approved institution.'], 422);
        $u = DB::transaction(function () use ($d) {
            $u = User::create(['name' => $d['name'], 'username' => $d['username'], 'email' => $d['email'], 'password' => Hash::make($d['password']), 'status' => 'pending']);
            $u->assignRole('Resident');
            Resident::create(['user_id' => $u->id, 'institution_id' => $d['institution_id'], 'track' => $d['track'], 'date_accepted' => $d['date_accepted'] ?? null, 'age_at_enrollment' => $d['age_at_enrollment'] ?? null]);
            return $u;
        });
        $u->sendEmailVerificationNotification();
        return response()->json(['message' => 'Registration submitted. Verify your email and wait for Admin approval.', 'user' => $u], 201);
    }
    public function logout(Request $r)
    {
        $r->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }
}
