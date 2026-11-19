<?php

namespace App\Http\Middleware;

use App\Support\LogHub;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** /logs routes: a plain 404 for anyone the Compliance hub is not shown to (see LogHub::hubVisible). */
class EnsureLogsHubVisible
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(LogHub::hubVisible($request), 404);

        return $next($request);
    }
}
