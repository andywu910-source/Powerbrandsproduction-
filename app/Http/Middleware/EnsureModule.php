<?php

namespace App\Http\Middleware;

use App\Support\Modules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** ->middleware('module:checklists'): 404 until that tab is switched on (old /compliance links go to the hub). */
class EnsureModule
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        if (! Modules::enabled($module)) {
            // The old Compliance Checklists tab was retired in favour of the Compliance hub (Nov 19):
            // its old links land on the hub instead of a 404.
            if ($module === 'compliance' && \App\Support\LogHub::hubVisible($request)) {
                return redirect()->route('logs.index');
            }
            abort(404);
        }

        return $next($request);
    }
}
