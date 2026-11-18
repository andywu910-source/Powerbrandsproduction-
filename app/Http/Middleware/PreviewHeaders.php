<?php

namespace App\Http\Middleware;

use App\Support\Preview;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Preview site: tell search engines to stay away (nginx adds the same header). */
class PreviewHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $res = $next($request);
        if (Preview::enabled() || Preview::guestLoginEnabled()) {
            $res->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $res;
    }
}
