<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Viewer accounts are read-only: any change request is refused. */
class BlockViewerWrites
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->isViewer() && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)
            && ! $request->routeIs('logout', 'password.update', 'labels.preview')) {
            abort(403, 'Your account is read-only.');
        }

        return $next($request);
    }
}
