<?php

namespace App\Http\Middleware;

use App\Support\Modules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** ->middleware('module:checklists'): 404 until that tab is switched on. */
class EnsureModule
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless(Modules::enabled($module), 404);

        return $next($request);
    }
}
