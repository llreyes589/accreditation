<?php

namespace App\Http\Middleware;

use Closure;

class RequireRole
{
    public function handle($request, Closure $next, $roles)
    {
        foreach (explode('|', $roles) as $role) if ($request->user() && $request->user()->hasRole($role)) return $next($request);
        return response()->json(['message' => 'You are not authorized for this action.'], 403);
    }
}
