<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Managers without a club are sent to pick one first. */
class EnsureUserHasClub
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->club) {
            return redirect()->route('clubs.choose');
        }

        return $next($request);
    }
}
