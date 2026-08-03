<?php

namespace App\Http\Middleware;

use Closure;

class EnsureApproved
{
    public function handle($request, Closure $next)
    {
        if (! $request->user() || ! $request->user()->isApproved()) {
            return response()->json(['message' => 'Your account is awaiting administrator approval.', 'status' => optional($request->user())->status], 403);
        }
        return $next($request);
    }
}
