<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;

class VerificationController extends Controller
{
    public function notice(Request $r)
    {
        return response()->json(['verified' => (bool)$r->user()->email_verified_at]);
    }
    public function resend(Request $r)
    {
        if (!$r->user()->hasVerifiedEmail()) $r->user()->sendEmailVerificationNotification();
        return response()->json(['message' => 'Verification link sent.']);
    }
    public function verify(Request $r, $id, $hash)
    {
        $u = User::findOrFail($id);
        abort_unless(hash_equals((string)$hash, sha1($u->getEmailForVerification())), 403);
        if (!$u->hasVerifiedEmail()) {
            $u->markEmailAsVerified();
            event(new Verified($u));
        }
        return response()->json(['message' => 'Email verified.']);
    }
}
