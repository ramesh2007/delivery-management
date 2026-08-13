<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  ...$permissions
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        if (! $request->user() || ! $request->user()->hasAnyPermission($permissions)) {
            return response()->json([
                'message' => 'Unauthorized. You do not have the required permission.'
            ], 403);
        }

        return $next($request);
    }
}
